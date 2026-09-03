<?php

declare(strict_types=1);

use App\Enums\FormaRecebimento;
use App\Enums\SituacaoComprovante;
use App\Enums\SituacaoInscricao;
use App\Models\ComprovantePagamento;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Inscricoes\Cenario;

/*
 * O envio do comprovante pela tela do participante.
 *
 * O que se prova aqui:
 *
 * - RN-S5 — imagem ou PDF, ate 5 MB, conferido pelo CONTEUDO e nunca pela
 *   extensao; guardado em disco privado, com nome gerado pelo servidor e o nome
 *   original apenas na coluna; rota assinada;
 * - RN-S6 — um comprovante em aberto por inscricao. Mandar outro por cima do
 *   que ainda espera SUBSTITUI a linha e apaga o arquivo velho do disco; mandar
 *   depois de uma recusa cria linha nova e nao apaga o historico;
 * - RN-S7 — enviar NAO confirma nada: a inscricao continua exatamente onde
 *   estava, com o mesmo prazo e a mesma vaga presa;
 * - RN-S11 — o arquivo nao fica em disco publico e nao ha URL adivinhavel.
 */

beforeEach(function (): void {
    Storage::fake('comprovantes');
});

/**
 * Uma inscricao num evento que recebe pela chave Pix do setor, com o setor
 * pronto para receber.
 */
function inscricaoNoModoSetor(): Inscricao
{
    $cenario = Cenario::montar([
        'forma_recebimento' => FormaRecebimento::Setor,
        'prazo_pagamento_minutos' => 10080,
    ]);

    $cenario->cidade->update([
        'responsavel_id' => User::factory()->create()->getKey(),
        'chave_pix' => 'setor@example.com',
        'titular_chave_pix' => 'Joana da Silva',
    ]);

    return $cenario->inscrever();
}

function urlDoEnvio(Inscricao $inscricao): string
{
    return URL::temporarySignedRoute(
        'inscricoes.comprovante',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $inscricao->codigo_publico],
    );
}

// ---------------------------------------------------------------------------
// RN-S5 — o arquivo, e como ele e conferido
// ---------------------------------------------------------------------------

it('recusa o envio sem assinatura na url', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $this->post("/inscricoes/{$inscricao->codigo_publico}/comprovante", [
        'comprovante' => UploadedFile::fake()->image('recibo.jpg'),
    ])->assertForbidden();

    expect(ComprovantePagamento::query()->count())->toBe(0);
});

it('guarda o comprovante enviado, com o nome original so na coluna', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('meu recibo do pix.jpg', 600, 800),
    ])->assertRedirect();

    $comprovante = ComprovantePagamento::query()->firstOrFail();

    expect($comprovante->situacao)->toBe(SituacaoComprovante::Enviado)
        ->and($comprovante->nome_original)->toBe('meu recibo do pix.jpg')
        ->and($comprovante->mime)->toBe('image/jpeg')
        ->and($comprovante->tamanho_bytes)->toBeGreaterThan(0)
        ->and($comprovante->conferido_por_id)->toBeNull()
        // O nome que a pessoa deu NAO entra no caminho do servidor.
        ->and($comprovante->caminho)->not->toContain('meu recibo')
        ->and($comprovante->caminho)->toStartWith('comprovantes/'.Carbon::now()->year.'/'.$inscricao->codigo_publico.'/');

    Storage::disk('comprovantes')->assertExists($comprovante->caminho);
});

it('aceita pdf e recusa arquivo cujo conteudo nao e imagem nem pdf', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->create('recibo.pdf', 200, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    // O nome diz ".jpg"; o conteudo, nao. `mimetypes` abre o arquivo e pergunta
    // ao sistema o que ele e — `mimes` teria acreditado no nome.
    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->create('malicioso.jpg', 10, 'application/x-msdownload'),
    ])->assertSessionHasErrors('comprovante');

    expect(ComprovantePagamento::query()->count())->toBe(1)
        ->and(ComprovantePagamento::query()->firstOrFail()->mime)->toBe('application/pdf');
});

it('recusa arquivo acima de cinco megabytes', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->create('enorme.pdf', 5121, 'application/pdf'),
    ])->assertSessionHasErrors('comprovante');

    expect(ComprovantePagamento::query()->count())->toBe(0);
});

it('recusa comprovante em evento que recebe pelo provedor', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('recibo.jpg'),
    ])->assertSessionHasErrors('comprovante');

    expect(ComprovantePagamento::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// RN-S11 — nunca em disco publico, nunca por URL adivinhavel
// ---------------------------------------------------------------------------

it('nao guarda o comprovante em disco publico', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('recibo.png'),
    ])->assertRedirect();

    $comprovante = ComprovantePagamento::query()->firstOrFail();

    // O disco "comprovantes" tem raiz propria, fora de storage/app/public — que
    // e a pasta que o `storage:link` espelha para a web.
    expect(config('filesystems.disks.comprovantes.root'))
        ->toBe(storage_path('app/comprovantes'))
        ->and(config('filesystems.disks.comprovantes'))->not->toHaveKey('url')
        ->and(str_contains(config('filesystems.disks.comprovantes.root'), 'app/public'))->toBeFalse()
        // E o caminho gravado nao aponta para la.
        ->and($comprovante->caminho)->not->toContain('public');

    // O disco publico continua sem nada.
    Storage::fake('public');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

// ---------------------------------------------------------------------------
// RN-S6 — um comprovante em aberto por inscricao
// ---------------------------------------------------------------------------

it('substitui o comprovante em aberto e apaga o arquivo anterior do disco', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('primeiro.jpg'),
    ])->assertRedirect();

    $primeiro = ComprovantePagamento::query()->firstOrFail();
    $caminhoAntigo = $primeiro->caminho;

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('segundo.png'),
    ])->assertRedirect();

    $comprovantes = ComprovantePagamento::query()->get();

    expect($comprovantes)->toHaveCount(1)
        // A MESMA linha: substituir e sobrescrever, e nao empilhar.
        ->and((int) $comprovantes->first()->getKey())->toBe((int) $primeiro->getKey())
        ->and($comprovantes->first()->nome_original)->toBe('segundo.png')
        ->and($comprovantes->first()->mime)->toBe('image/png');

    Storage::disk('comprovantes')->assertMissing($caminhoAntigo);
    Storage::disk('comprovantes')->assertExists($comprovantes->first()->caminho);
});

it('o banco recusa dois comprovantes em aberto para a mesma inscricao', function (): void {
    $inscricao = inscricaoNoModoSetor();

    ComprovantePagamento::factory()->enviado()->create(['inscricao_id' => $inscricao->getKey()]);

    expect(fn () => ComprovantePagamento::factory()->enviado()->create([
        'inscricao_id' => $inscricao->getKey(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('cria linha nova depois de uma recusa, sem apagar o historico', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $recusado = ComprovantePagamento::factory()
        ->recusado('O valor não confere com o da inscrição.')
        ->create(['inscricao_id' => $inscricao->getKey()]);

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('segunda-tentativa.jpg'),
    ])->assertRedirect();

    expect(ComprovantePagamento::query()->count())->toBe(2)
        ->and($recusado->fresh()->situacao)->toBe(SituacaoComprovante::Recusado)
        ->and($recusado->fresh()->motivo_recusa)->toBe('O valor não confere com o da inscrição.')
        ->and($inscricao->comprovanteEmAberto()?->nome_original)->toBe('segunda-tentativa.jpg');
});

it('o banco recusa uma recusa sem motivo escrito', function (): void {
    $inscricao = inscricaoNoModoSetor();

    expect(fn () => ComprovantePagamento::query()->create([
        'inscricao_id' => $inscricao->getKey(),
        'caminho' => 'comprovantes/2026/X/abc.jpg',
        'nome_original' => 'abc.jpg',
        'mime' => 'image/jpeg',
        'tamanho_bytes' => 10,
        'situacao' => SituacaoComprovante::Recusado,
        'enviado_em' => Carbon::now(),
        'motivo_recusa' => null,
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// RN-S7 — enviar nao confirma nada
// ---------------------------------------------------------------------------

it('nao confirma a inscricao nem mexe na vaga quando o comprovante chega', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $evento = $inscricao->evento;
    $reservadasAntes = (int) $evento->vagas_reservadas;
    $confirmadasAntes = (int) $evento->vagas_confirmadas;
    $prazoAntes = $inscricao->prazo_pagamento?->toIso8601String();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('recibo.jpg'),
    ])->assertRedirect();

    $inscricao->refresh();
    $evento->refresh();

    expect($inscricao->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($inscricao->confirmada_em)->toBeNull()
        ->and($inscricao->prazo_pagamento?->toIso8601String())->toBe($prazoAntes)
        ->and((int) $evento->vagas_reservadas)->toBe($reservadasAntes)
        ->and((int) $evento->vagas_confirmadas)->toBe($confirmadasAntes)
        // A cobranca tambem nao mudou de situacao.
        ->and($inscricao->pagamentoPendente())->not->toBeNull();
});

it('a tela do participante conta o estado do comprovante sem inventar situacao de inscricao', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $this->post(urlDoEnvio($inscricao), [
        'comprovante' => UploadedFile::fake()->image('recibo.jpg'),
    ])->assertRedirect();

    $url = URL::temporarySignedRoute(
        'inscricoes.pagamento',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    $props = $this->get($url)->assertOk()->viewData('page')['props'];

    expect($props['recebe_pelo_setor'])->toBeTrue()
        ->and($props['setor']['chave_pix'])->toBe('setor@example.com')
        ->and($props['setor']['titular'])->toBe('Joana da Silva')
        ->and($props['comprovante']['situacao'])->toBe('enviado')
        ->and($props['comprovante']['situacao_rotulo'])->toBe('Em conferência')
        // A INSCRICAO continua aguardando pagamento: o comprovante e informacao
        // de tela, e nao estado de dominio.
        ->and($props['situacao'])->toBe(SituacaoInscricao::AguardandoPagamento->value)
        ->and($props['estado'])->toBe('aguardando');
});

/*
| O telefone de quem responde pelo setor.
|
| Ele existe para a duvida que aparece com o dinheiro prestes a sair — "e este
| nome mesmo?", "o valor confere?" — e por isso viaja junto da chave, na mesma
| tela. E OPCIONAL: sem ele a tela simplesmente nao oferece contato, em vez de
| convidar a pessoa a ligar para lugar nenhum.
*/
it('leva o telefone do responsavel para a tela, junto da chave', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $inscricao->setor()?->update(['telefone_responsavel' => '(82) 99999-1234']);

    $url = URL::temporarySignedRoute(
        'inscricoes.pagamento',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    $props = $this->get($url)->assertOk()->viewData('page')['props'];

    expect($props['setor']['telefone'])->toBe('(82) 99999-1234');
});

it('nao oferece contato quando ninguem cadastrou o telefone do responsavel', function (): void {
    $inscricao = inscricaoNoModoSetor();

    $url = URL::temporarySignedRoute(
        'inscricoes.pagamento',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    $props = $this->get($url)->assertOk()->viewData('page')['props'];

    // Nulo, e nao string vazia: a tela decide pelo v-if, e "" seria verdadeiro
    // o bastante para desenhar um link de telefone sem telefone.
    expect($props['setor']['telefone'])->toBeNull()
        // E o setor sem telefone continua podendo receber: RN-S4 trava em chave
        // e responsavel, nunca no contato.
        ->and($props['setor']['chave_pix'])->toBe('setor@example.com');
});

it('nao mostra chave nem campo de comprovante em evento que recebe pelo provedor', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    $url = URL::temporarySignedRoute(
        'inscricoes.pagamento',
        Carbon::now()->addDay(),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    $props = $this->get($url)->assertOk()->viewData('page')['props'];

    expect($props['recebe_pelo_setor'])->toBeFalse()
        ->and($props['setor'])->toBeNull()
        ->and($props['comprovante'])->toBeNull()
        ->and($props['url_comprovante'])->toBeNull();
});
