<?php

declare(strict_types=1);

use App\Actions\Pagamentos\CriarPagamentoDaInscricao;
use App\Actions\Pagamentos\SortearResponsavel;
use App\Enums\FormaRecebimento;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Pagamentos\SetorSemChavePixException;
use App\Models\Cidade;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\Responsavel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\Feature\Inscricoes\Cenario;

/*
 * O sorteio de quem recebe cada cobranca do modo setor.
 *
 * O que se prova aqui:
 *
 * - RN-R4 — o sorteio EQUILIBRA e continua sendo sorteio. Sao duas promessas
 *   que se contradizem se uma delas for escrita sozinha: com dois responsaveis
 *   e dez inscricoes cada um fica com cinco (equilibrio), e com dois empatados
 *   em zero os dois aparecem ao longo de muitas emissoes (imprevisibilidade).
 *   Um sorteio que sempre devolve o de menor id nao e sorteio — e ordenacao;
 * - RN-R4 pelo lado da exclusao — inativo, sem chave e de outro setor nunca sao
 *   sorteados. Um teste por motivo, porque cada um falha por um caminho
 *   diferente;
 * - RN-R5 — cobranca pendente NAO sorteia de novo; a reemissao depois do
 *   vencimento sorteia, e a cobranca antiga continua apontando para quem
 *   apontava. O historico nao e reescrito;
 * - RN-R8 — a carga e balanceamento, nunca capacidade: um responsavel so
 *   recebe quantas inscricoes vierem, sem trava e sem estouro;
 * - RN-R9 — desativar tira do sorteio na hora sem tocar em cobranca emitida, e
 *   o banco recusa apagar quem ja recebeu.
 */

/**
 * Um evento que recebe pelo setor, com o setor pronto para receber.
 *
 * @param  array<int, Responsavel>  $responsaveis
 */
function cenarioComSorteio(array $responsaveis = []): Cenario
{
    $cenario = Cenario::montar([
        'forma_recebimento' => FormaRecebimento::Setor,
        'prazo_pagamento_minutos' => 10080,
    ]);

    $cenario->cidade->responsaveis()->sync(array_map(
        fn (Responsavel $responsavel): int => (int) $responsavel->getKey(),
        $responsaveis,
    ));

    return $cenario;
}

/**
 * Quantas cobrancas do evento apontam para cada responsavel, pelo nome deles.
 *
 * @return array<string, int>
 */
function cargaPorResponsavel(Cenario $cenario): array
{
    return Pagamento::query()
        ->whereIn('inscricao_id', Inscricao::query()
            ->where('evento_id', $cenario->evento->getKey())
            ->select('id'))
        ->with('responsavel')
        ->get()
        ->groupBy(fn (Pagamento $pagamento): string => (string) ($pagamento->responsavel?->nome ?? 'ninguem'))
        ->map(fn ($grupo): int => $grupo->count())
        // Ordenado pelo nome para que a expectativa possa ser escrita como um
        // array literal: a ordem em que os nomes aparecem depende do sorteio, e
        // e justamente ela que nao interessa aqui.
        ->sortKeys()
        ->all();
}

// ---------------------------------------------------------------------------
// RN-R4 — o sorteio equilibra
// ---------------------------------------------------------------------------

it('divide dez inscricoes igualmente entre dois responsaveis', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $bruno = Responsavel::factory()->semConta()->create(['nome' => 'Bruno']);

    $cenario = cenarioComSorteio([$ana, $bruno]);

    for ($i = 1; $i <= 10; $i++) {
        $cenario->inscrever($cenario->outraPessoa($i));
    }

    // Determinístico mesmo com sorteio: o empate so existe entre os MENOS
    // carregados, entao a undecima emissao nunca pode cair em quem ja tem uma a
    // mais que o outro.
    expect(cargaPorResponsavel($cenario))->toBe(['Ana' => 5, 'Bruno' => 5]);
});

it('divide nove inscricoes igualmente entre tres responsaveis', function (): void {
    $tres = collect(['Ana', 'Bruno', 'Carla'])
        ->map(fn (string $nome): Responsavel => Responsavel::factory()->semConta()->create(['nome' => $nome]))
        ->all();

    $cenario = cenarioComSorteio($tres);

    for ($i = 1; $i <= 9; $i++) {
        $cenario->inscrever($cenario->outraPessoa($i));
    }

    expect(cargaPorResponsavel($cenario))->toBe(['Ana' => 3, 'Bruno' => 3, 'Carla' => 3]);
});

it('continua imprevisivel dentro do empate: os dois zerados aparecem', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $bruno = Responsavel::factory()->semConta()->create(['nome' => 'Bruno']);

    $cenario = cenarioComSorteio([$ana, $bruno]);
    $sortear = app(SortearResponsavel::class);

    // Ninguem recebeu nada ainda: os dois estao empatados em zero em TODA
    // repeticao, porque nenhuma cobranca e criada aqui. Se o sorteio fosse
    // ordenacao disfarcada, o conjunto teria um nome so.
    $escolhidos = [];

    for ($i = 0; $i < 60; $i++) {
        $escolhidos[] = $sortear($cenario->cidade, $cenario->evento)?->nome;
    }

    expect(array_unique($escolhidos))->toHaveCount(2)
        ->and($escolhidos)->toContain('Ana')
        ->toContain('Bruno');
});

// ---------------------------------------------------------------------------
// RN-R4 pela exclusao — so entram os aptos
// ---------------------------------------------------------------------------

it('nunca sorteia responsavel inativo', function (): void {
    $ativo = Responsavel::factory()->semConta()->create(['nome' => 'Ativo']);
    $inativo = Responsavel::factory()->semConta()->inativo()->create(['nome' => 'Inativo']);

    $cenario = cenarioComSorteio([$ativo, $inativo]);
    $sortear = app(SortearResponsavel::class);

    for ($i = 0; $i < 20; $i++) {
        expect($sortear($cenario->cidade, $cenario->evento)?->nome)->toBe('Ativo');
    }
});

it('nunca sorteia responsavel sem chave', function (): void {
    $comChave = Responsavel::factory()->semConta()->create(['nome' => 'Com chave']);
    $semChave = Responsavel::factory()->semConta()->semChave()->create(['nome' => 'Sem chave']);

    $cenario = cenarioComSorteio([$comChave, $semChave]);
    $sortear = app(SortearResponsavel::class);

    for ($i = 0; $i < 20; $i++) {
        expect($sortear($cenario->cidade, $cenario->evento)?->nome)->toBe('Com chave');
    }
});

it('nunca sorteia responsavel de outro setor', function (): void {
    $doSetor = Responsavel::factory()->semConta()->create(['nome' => 'Do setor']);
    $deOutro = Responsavel::factory()->semConta()->create(['nome' => 'De outro']);

    $cenario = cenarioComSorteio([$doSetor]);

    // Existe, esta apto, e nao atende este setor.
    Cidade::factory()->create(['uf' => 'BA'])->responsaveis()->sync([$deOutro->getKey()]);

    $sortear = app(SortearResponsavel::class);

    for ($i = 0; $i < 20; $i++) {
        expect($sortear($cenario->cidade, $cenario->evento)?->nome)->toBe('Do setor');
    }
});

it('recusa a inscricao quando o setor nao tem ninguem apto', function (): void {
    $cenario = cenarioComSorteio([
        Responsavel::factory()->semConta()->inativo()->create(),
        Responsavel::factory()->semConta()->semChave()->create(),
    ]);

    expect(fn () => $cenario->inscrever())->toThrow(SetorSemChavePixException::class);
});

// ---------------------------------------------------------------------------
// RN-R5 — cada cobranca sorteia de novo, e so quando ela nasce
// ---------------------------------------------------------------------------

it('nao sorteia de novo enquanto a cobranca esta pendente', function (): void {
    $cenario = cenarioComSorteio([
        Responsavel::factory()->semConta()->create(['nome' => 'Ana']),
        Responsavel::factory()->semConta()->create(['nome' => 'Bruno']),
    ]);

    $inscricao = $cenario->inscrever();
    $primeira = $inscricao->pagamentoPendente();

    // Vinte chamadas: se alguma sorteasse, a chance de nenhuma trocar o nome
    // seria de uma em um milhao.
    for ($i = 0; $i < 20; $i++) {
        $devolvida = app(CriarPagamentoDaInscricao::class)($inscricao);

        expect($devolvida->getKey())->toBe($primeira?->getKey())
            ->and($devolvida->responsavel_id)->toBe($primeira?->responsavel_id);
    }

    expect(Pagamento::query()->where('inscricao_id', $inscricao->getKey())->count())->toBe(1);
});

it('sorteia de novo na reemissao, e a cobranca antiga guarda quem recebeu', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $bruno = Responsavel::factory()->semConta()->create(['nome' => 'Bruno']);

    $cenario = cenarioComSorteio([$ana, $bruno]);

    $inscricao = $cenario->inscrever();
    $antiga = $inscricao->pagamentoPendente();
    $responsavelAntigo = (int) $antiga?->responsavel_id;

    // A primeira venceu: e so aqui que uma cobranca nova pode nascer.
    Pagamento::query()->whereKey($antiga?->getKey())->update([
        'situacao' => 'expirado',
        'expira_em' => Carbon::now()->subHour(),
    ]);

    $nova = app(CriarPagamentoDaInscricao::class)($inscricao->fresh());

    expect($nova->getKey())->not->toBe($antiga?->getKey())
        ->and($nova->responsavel_id)->not->toBeNull()
        // O historico nao e reescrito: a antiga continua apontando para quem
        // ela apontava, mesmo que a nova aponte para outra pessoa.
        ->and((int) $antiga?->fresh()->responsavel_id)->toBe($responsavelAntigo);
});

it('nao equilibra entre eventos: a carga de um nao empurra o sorteio do outro', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $bruno = Responsavel::factory()->semConta()->create(['nome' => 'Bruno']);

    $primeiro = cenarioComSorteio([$ana, $bruno]);

    for ($i = 1; $i <= 6; $i++) {
        $primeiro->inscrever($primeiro->outraPessoa($i));
    }

    // Um evento novo, com o MESMO setor: os dois voltam a zero. Herdar carga
    // faria o responsavel novo receber tudo do evento seguinte.
    $segundo = Cenario::montar([
        'forma_recebimento' => FormaRecebimento::Setor,
        'prazo_pagamento_minutos' => 10080,
    ]);
    $segundo->cidade->responsaveis()->sync([$ana->getKey(), $bruno->getKey()]);

    for ($i = 100; $i <= 103; $i++) {
        $segundo->inscrever($segundo->outraPessoa($i));
    }

    expect(cargaPorResponsavel($segundo))->toBe(['Ana' => 2, 'Bruno' => 2]);
});

// ---------------------------------------------------------------------------
// RN-R8 — carga e balanceamento, nunca capacidade
// ---------------------------------------------------------------------------

it('deixa um responsavel so receber todas as inscricoes, sem estourar nada', function (): void {
    $cenario = cenarioComSorteio([
        Responsavel::factory()->semConta()->create(['nome' => 'Sozinho']),
    ]);

    for ($i = 1; $i <= 8; $i++) {
        $cenario->inscrever($cenario->outraPessoa($i));
    }

    // Nenhum limite, nenhuma recusa: a carga serve para escolher entre varios,
    // e nao para dizer que alguem "encheu".
    expect(cargaPorResponsavel($cenario))->toBe(['Sozinho' => 8]);
});

it('nao conta inscricao cancelada na carga', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $bruno = Responsavel::factory()->semConta()->create(['nome' => 'Bruno']);

    $cenario = cenarioComSorteio([$ana, $bruno]);

    $primeira = $cenario->inscrever($cenario->outraPessoa(1));
    $sorteado = (int) $primeira->pagamentoPendente()?->responsavel_id;

    // Ela sai do ar: a vaga voltou e o dinheiro dela nunca vai chegar. Contar
    // essa carga faria quem a recebeu ser pulado sem motivo.
    Inscricao::query()->whereKey($primeira->getKey())->update([
        'situacao' => SituacaoInscricao::Cancelada->value,
    ]);

    $sortear = app(SortearResponsavel::class);
    $escolhidos = [];

    for ($i = 0; $i < 40; $i++) {
        $escolhidos[] = (int) $sortear($cenario->cidade, $cenario->evento)?->getKey();
    }

    expect(array_unique($escolhidos))->toHaveCount(2)
        ->and($escolhidos)->toContain($sorteado);
});

// ---------------------------------------------------------------------------
// RN-R9 — desativar tira do sorteio; excluir quem recebeu, nao
// ---------------------------------------------------------------------------

it('tira do sorteio na hora quem foi desativado, sem tocar em cobranca emitida', function (): void {
    $ana = Responsavel::factory()->semConta()->create(['nome' => 'Ana']);
    $bruno = Responsavel::factory()->semConta()->create(['nome' => 'Bruno']);

    $cenario = cenarioComSorteio([$ana, $bruno]);

    $inscricao = $cenario->inscrever();
    $cobranca = $inscricao->pagamentoPendente();
    $quemRecebeu = (int) $cobranca?->responsavel_id;

    Responsavel::query()->whereKey($quemRecebeu)->update(['ativo' => false]);

    // A cobranca ja emitida continua apontando para ele: foi ele quem recebeu.
    expect((int) $cobranca?->fresh()->responsavel_id)->toBe($quemRecebeu);

    $sortear = app(SortearResponsavel::class);
    $sobrou = $quemRecebeu === (int) $ana->getKey() ? $bruno : $ana;

    for ($i = 0; $i < 20; $i++) {
        expect((int) $sortear($cenario->cidade, $cenario->evento)?->getKey())->toBe((int) $sobrou->getKey());
    }
});

it('nao deixa o banco apagar responsavel que ja recebeu', function (): void {
    $cenario = cenarioComSorteio([
        Responsavel::factory()->semConta()->create(['nome' => 'Sozinho']),
    ]);

    $inscricao = $cenario->inscrever();
    $quemRecebeu = Responsavel::query()->findOrFail($inscricao->pagamentoPendente()?->responsavel_id);

    // "restrictOnDelete": apagar quem recebeu apagaria a resposta a pergunta
    // "para quem foi este dinheiro". A saida e desativar.
    expect(fn () => $quemRecebeu->delete())->toThrow(QueryException::class);
});

it('apaga sem reclamar quem nunca recebeu nada', function (): void {
    $novato = Responsavel::factory()->semConta()->create();

    $novato->delete();

    expect(Responsavel::query()->whereKey($novato->getKey())->exists())->toBeFalse();
});
