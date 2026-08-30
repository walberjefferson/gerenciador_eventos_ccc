<?php

declare(strict_types=1);

use App\Enums\TipoComunicacao;
use App\Events\InscricaoCancelada;
use App\Events\InscricaoConfirmada;
use App\Events\InscricaoCriada;
use App\Events\InscricaoExpirada;
use App\Listeners\EnviarEmailInscricaoRecebida;
use App\Models\ComunicacaoEnviada;
use App\Models\Inscricao;
use App\Services\Comunicacao\RegistrarEnvio;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| A mesma mensagem nunca chega duas vezes
|--------------------------------------------------------------------------
|
| A protecao nao esta em nenhuma linha de PHP: esta no indice unico
| (inscricao_id, tipo, canal) da tabela comunicacoes_enviadas. Estes testes
| exercitam a trava pelo banco, que e onde ela mora.
|
*/

it('recusa dois registros da mesma mensagem para a mesma inscricao', function (): void {
    $inscricao = Inscricao::factory()->create();

    $registro = fn (): ComunicacaoEnviada => ComunicacaoEnviada::query()->create([
        'inscricao_id' => $inscricao->id,
        'tipo' => TipoComunicacao::InscricaoRecebida->value,
        'canal' => ComunicacaoEnviada::CANAL_EMAIL,
        'destino' => $inscricao->email,
        'enviada_em' => Carbon::now(),
    ]);

    $registro();

    // A segunda gravacao vai dentro de uma transacao propria: no PostgreSQL,
    // um erro deixa a transacao aberta inutilizavel, e sem esse cerco nao
    // sobraria conexao para conferir o resultado.
    expect(fn () => DB::transaction($registro))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(ComunicacaoEnviada::query()->count())->toBe(1);
});

it('aceita mensagens de tipos diferentes para a mesma inscricao', function (): void {
    $inscricao = Inscricao::factory()->create();

    foreach (TipoComunicacao::cases() as $tipo) {
        ComunicacaoEnviada::query()->create([
            'inscricao_id' => $inscricao->id,
            'tipo' => $tipo->value,
            'canal' => ComunicacaoEnviada::CANAL_EMAIL,
            'destino' => $inscricao->email,
            'enviada_em' => Carbon::now(),
        ]);
    }

    expect(ComunicacaoEnviada::query()->count())->toBe(count(TipoComunicacao::cases()));
});

it('envia uma vez so, mesmo chamado duas vezes', function (): void {
    $inscricao = Inscricao::factory()->create();
    $registrar = app(RegistrarEnvio::class);
    $envios = 0;

    $primeira = $registrar->umaVezPor(
        $inscricao,
        TipoComunicacao::InscricaoRecebida,
        $inscricao->email,
        function () use (&$envios): void {
            $envios++;
        },
    );

    $segunda = $registrar->umaVezPor(
        $inscricao,
        TipoComunicacao::InscricaoRecebida,
        $inscricao->email,
        function () use (&$envios): void {
            $envios++;
        },
    );

    expect($primeira)->toBeTrue()
        ->and($segunda)->toBeFalse()
        ->and($envios)->toBe(1)
        ->and(ComunicacaoEnviada::query()->count())->toBe(1);
});

it('desfaz o registro quando o envio falha, para que a fila possa tentar de novo', function (): void {
    $inscricao = Inscricao::factory()->create();
    $registrar = app(RegistrarEnvio::class);

    $tentativa = fn (): bool => $registrar->umaVezPor(
        $inscricao,
        TipoComunicacao::InscricaoRecebida,
        $inscricao->email,
        function (): void {
            throw new RuntimeException('servidor de e-mail fora do ar');
        },
    );

    expect($tentativa)->toThrow(RuntimeException::class);

    // Nenhum registro sobrou: a mensagem nao saiu, entao o caminho continua
    // livre para a proxima tentativa da fila.
    expect(ComunicacaoEnviada::query()->count())->toBe(0)
        ->and($registrar->jaEnviada($inscricao, TipoComunicacao::InscricaoRecebida))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Onde a trava mora de verdade
|--------------------------------------------------------------------------
|
| Um teste que so prova que um "if" funciona nao prova nada: dois processos
| avaliam o mesmo "if" no mesmo instante e os dois passam. Os testes abaixo
| olham para o banco, que e o unico lugar onde a decisao pode ser tomada uma
| vez so.
|
*/

it('tem, no banco, o indice unico que arbitra a duplicidade', function (): void {
    /** @var list<object{indexdef: string}> $indices */
    $indices = DB::select(
        "select indexdef from pg_indexes where tablename = 'comunicacoes_enviadas'"
    );

    $definicoes = array_map(fn (object $indice): string => (string) $indice->indexdef, $indices);

    $unico = array_filter($definicoes, fn (string $def): bool => str_contains($def, 'CREATE UNIQUE INDEX')
        && str_contains($def, 'inscricao_id')
        && str_contains($def, 'tipo')
        && str_contains($def, 'canal'));

    expect($unico)->not->toBeEmpty(
        'A tabela comunicacoes_enviadas precisa de um indice UNIQUE em (inscricao_id, tipo, canal): '
        .'e ele, e nao o PHP, que impede a segunda copia. Indices encontrados: '.implode(' | ', $definicoes)
    );
});

it('tenta gravar mesmo quando ja existe registro: quem recusa e o banco, nao uma verificacao previa', function (): void {
    $inscricao = Inscricao::factory()->create();
    $registrar = app(RegistrarEnvio::class);

    ComunicacaoEnviada::query()->create([
        'inscricao_id' => $inscricao->id,
        'tipo' => TipoComunicacao::InscricaoRecebida->value,
        'canal' => ComunicacaoEnviada::CANAL_EMAIL,
        'destino' => $inscricao->email,
        'enviada_em' => Carbon::now(),
    ]);

    // O PostgreSQL reserva o proximo id assim que um INSERT comeca, e nao
    // devolve essa reserva quando a linha e recusada. Entao a contagem da
    // sequencia denuncia se o INSERT chegou ou nao ao banco.
    $idsReservados = fn (): int => (int) DB::selectOne(
        'select last_value from comunicacoes_enviadas_id_seq'
    )->last_value;

    $antes = $idsReservados();

    $enviou = $registrar->umaVezPor(
        $inscricao,
        TipoComunicacao::InscricaoRecebida,
        $inscricao->email,
        function (): void {
            throw new RuntimeException('este envio jamais deveria acontecer');
        },
    );

    // O servico nao perguntou "ja mandei?" para decidir: ele mandou o INSERT e
    // aceitou a recusa do banco. Sobrou um id reservado para uma linha que
    // nunca existiu — a marca de que a decisao foi tomada la, e nao aqui. E
    // por isso que dois processos simultaneos chegam ao mesmo resultado que um
    // sozinho.
    expect($enviou)->toBeFalse()
        ->and($idsReservados())->toBeGreaterThan(
            $antes,
            'O registro deveria ter sido tentado no banco, e nao evitado por uma verificacao no PHP'
        )
        ->and(ComunicacaoEnviada::query()->count())->toBe(1);
});

it('dois trabalhadores com o mesmo trabalho nas maos produzem um e-mail so', function (): void {
    Mail::fake();

    $inscricao = Inscricao::factory()->create();
    $anuncio = new InscricaoCriada($inscricao);

    // Dois trabalhadores da fila, cada um com sua propria instancia do
    // ouvinte — e o mesmo trabalho na mao.
    $trabalhadorA = app(EnviarEmailInscricaoRecebida::class);
    $trabalhadorB = app(EnviarEmailInscricaoRecebida::class);

    $registrar = app(RegistrarEnvio::class);

    // O instante da corrida: os dois olham antes de agir e os dois veem o
    // caminho livre. Se a protecao fosse uma verificacao em memoria, a partir
    // daqui sairiam duas copias.
    $aViuLivre = ! $registrar->jaEnviada($inscricao, TipoComunicacao::InscricaoRecebida);
    $bViuLivre = ! $registrar->jaEnviada($inscricao, TipoComunicacao::InscricaoRecebida);

    expect($aViuLivre)->toBeTrue()->and($bViuLivre)->toBeTrue();

    $trabalhadorA->handle($anuncio);
    $trabalhadorB->handle($anuncio);

    // Saiu uma so, porque o segundo INSERT esbarrou no indice unico.
    Mail::assertQueuedCount(1);
    expect(ComunicacaoEnviada::query()->count())->toBe(1);
});

it('anuncio repetido nao gera segundo e-mail, em nenhum dos quatro casos', function (): void {
    Mail::fake();

    $recebida = Inscricao::factory()->create();
    $confirmada = Inscricao::factory()->confirmada()->create();
    $expirada = Inscricao::factory()->expirada()->create();
    $cancelada = Inscricao::factory()->cancelada('Anotacao interna da secretaria')->create();

    // Cada anuncio disparado duas vezes: e o que acontece quando um trabalho
    // volta para a fila depois de uma falha de rede no meio da entrega.
    foreach ([1, 2] as $vez) {
        InscricaoCriada::dispatch($recebida);
        InscricaoConfirmada::dispatch($confirmada);
        InscricaoExpirada::dispatch($expirada);
        InscricaoCancelada::dispatch($cancelada, 'Anotacao interna da secretaria', null, true);
    }

    Mail::assertQueuedCount(4);
    expect(ComunicacaoEnviada::query()->count())->toBe(4);
});
