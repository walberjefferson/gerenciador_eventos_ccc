<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Exceptions\Inscricoes\VagasEsgotadasException;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inscricoes\Cenario;

/**
 * Corresponde ao ConcurrencyTest exigido pelo briefing.
 *
 * Prova, de duas maneiras complementares, que a mesma vaga nunca e vendida
 * duas vezes:
 *
 * 1. De forma deterministica, olhando para a peca que sustenta a garantia: a
 *    gravacao condicional (compare-and-swap). Quando o contador ja alcancou a
 *    capacidade, o UPDATE nao altera nenhuma linha — e e esse zero que vira a
 *    recusa da inscricao.
 * 2. De forma realista, colocando varios processos de sistema operacional,
 *    cada um com a sua propria conexao com o banco, para disputar a ultima
 *    vaga no mesmo instante. Threads simuladas dentro de um unico processo
 *    nao provariam nada: o risco real mora entre duas conexoes.
 *
 * Junto vai a varredura sob demanda vista pelo mesmo angulo: quando a gravacao
 * condicional recusa, mas a vaga esta presa apenas por reserva vencida, a
 * inscricao ainda assim e concedida na unica retentativa, sem esperar o
 * agendador.
 */

/** O mesmo comando de reserva que a Action executa, isolado para inspecao. */
function reservarComGravacaoCondicional(int $eventoId): int
{
    return DB::update(
        'UPDATE eventos
            SET vagas_reservadas = vagas_reservadas + 1, updated_at = now()
          WHERE id = ?
            AND (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas < capacidade)',
        [$eventoId],
    );
}

/**
 * Monta o cenario numa conexao propria, fora da transacao do teste.
 *
 * Os processos que disputam a vaga sao processos de verdade: eles so enxergam
 * o que ja foi confirmado no banco. O que o teste grava dentro da transacao
 * que o RefreshDatabase abre e invisivel para eles.
 *
 * @param  array<string, mixed>  $atributosDoEvento
 */
function cenarioVisivelParaOutrosProcessos(array $atributosDoEvento = []): Cenario
{
    $padrao = config('database.default');

    config(['database.connections.disputa' => config("database.connections.{$padrao}")]);
    config(['database.default' => 'disputa']);

    try {
        return Cenario::montar($atributosDoEvento);
    } finally {
        config(['database.default' => $padrao]);
    }
}

/**
 * Apaga o que foi gravado fora da transacao do teste.
 *
 * Esta e a unica excecao a regra de nunca apagar registro: nao ha dominio
 * aqui, e sim sujeira de teste que a transacao do RefreshDatabase nao alcanca
 * e que estragaria as contagens dos testes seguintes.
 */
function limparCenarioCommitado(Cenario $cenario): void
{
    $conexao = DB::connection('disputa');

    // As demais tabelas saem por cascata: inscricoes_atividades vai junto com
    // as inscricoes, e dias_evento, grupos e atividades vao junto com o evento.
    $conexao->table('inscricoes')->where('evento_id', $cenario->evento->id)->delete();
    $conexao->table('eventos')->where('id', $cenario->evento->id)->delete();
    $conexao->table('grupos_participantes')->where('id', $cenario->grupoParticipante->id)->delete();
    $conexao->table('cidades')->where('id', $cenario->cidade->id)->delete();
}

/**
 * Dispara varios processos independentes disputando a mesma vaga.
 *
 * Todos recebem o mesmo instante de largada, para que ninguem termine antes de
 * o ultimo nascer. Devolve a saida de cada um: "ok", "esgotado" ou "erro: ...".
 *
 * @return list<string>
 */
function disputarEmParalelo(Cenario $cenario, int $quantidade, float $margemDeLargada = 2.0): array
{
    $raiz = base_path();
    $script = $raiz.'/tests/Feature/Inscricoes/scripts/disputar-vaga.php';
    $conexao = config('database.connections.pgsql');

    $ambiente = array_merge(getenv(), [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $conexao['host'],
        'DB_PORT' => (string) $conexao['port'],
        'DB_DATABASE' => (string) $conexao['database'],
        'DB_USERNAME' => (string) $conexao['username'],
        'DB_PASSWORD' => (string) $conexao['password'],
    ]);

    $largada = microtime(true) + $margemDeLargada;
    $processos = [];

    for ($indice = 1; $indice <= $quantidade; $indice++) {
        $tubos = [];

        $processo = proc_open(
            [
                PHP_BINARY,
                $script,
                (string) $cenario->evento->id,
                (string) $cenario->cidade->id,
                (string) $cenario->grupoParticipante->id,
                (string) $cenario->futebol->id,
                (string) $indice,
                sprintf('%.6F', $largada),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubos,
            $raiz,
            $ambiente,
        );

        if (! is_resource($processo)) {
            throw new RuntimeException("Nao foi possivel iniciar o processo {$indice} da disputa.");
        }

        $processos[] = [$processo, $tubos];
    }

    $saidas = [];

    foreach ($processos as [$processo, $tubos]) {
        $saida = trim((string) stream_get_contents($tubos[1]));
        $erro = trim((string) stream_get_contents($tubos[2]));

        fclose($tubos[1]);
        fclose($tubos[2]);
        proc_close($processo);

        $saidas[] = $saida !== '' ? $saida : 'sem saida: '.$erro;
    }

    return $saidas;
}

describe('gravacao condicional', function () {
    it('nao altera nenhuma linha quando reservadas mais confirmadas ja alcancaram a capacidade', function () {
        $cenario = Cenario::montar(['capacidade' => 2]);

        // Uma vaga presa por reserva e outra ja paga: o evento esta cheio.
        DB::update(
            'UPDATE eventos SET vagas_reservadas = 1, vagas_confirmadas = 1 WHERE id = ?',
            [$cenario->evento->id],
        );

        expect(reservarComGravacaoCondicional($cenario->evento->id))->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1);
    });

    it('altera exatamente uma linha enquanto ainda ha vaga', function () {
        $cenario = Cenario::montar(['capacidade' => 2]);

        expect(reservarComGravacaoCondicional($cenario->evento->id))->toBe(1)
            ->and(reservarComGravacaoCondicional($cenario->evento->id))->toBe(1)
            ->and(reservarComGravacaoCondicional($cenario->evento->id))->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(2);
    });

    it('recusa a inscricao justamente quando a gravacao condicional devolve zero', function () {
        $cenario = Cenario::montar(['capacidade' => 1]);

        DB::update(
            'UPDATE eventos SET vagas_confirmadas = 1 WHERE id = ?',
            [$cenario->evento->id],
        );

        expect(reservarComGravacaoCondicional($cenario->evento->id))->toBe(0);

        expect(fn () => $cenario->inscrever())->toThrow(VagasEsgotadasException::class);

        // A tentativa recusada nao deixou nada para tras.
        expect(Inscricao::count())->toBe(0)
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(0);
    });

    it('concede a vaga presa por reserva vencida na unica retentativa, sem esperar o agendador', function () {
        $cenario = Cenario::montar(['capacidade' => 1, 'prazo_pagamento_minutos' => 60]);

        $primeira = $cenario->inscrever();

        // A pessoa nao pagou e o prazo passou; o agendador ainda nao rodou.
        Inscricao::whereKey($primeira->id)->update([
            'prazo_pagamento' => Carbon::now()->subMinute(),
        ]);

        // Neste instante o contador diz "lotado": a gravacao condicional recusa
        // e, por nao alterar linha nenhuma, nao mexe no contador.
        expect(reservarComGravacaoCondicional($cenario->evento->id))->toBe(0);

        // Ainda assim a inscricao passa: a Action varre as reservas vencidas
        // daquele evento e tenta a transacao inteira mais uma vez.
        $segunda = $cenario->inscrever($cenario->outraPessoa(31));

        expect($segunda->exists)->toBeTrue()
            ->and($primeira->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
            ->and($primeira->fresh()->expirada_em)->not->toBeNull()
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
            // Nada foi apagado: as duas inscricoes seguem no banco.
            ->and(Inscricao::count())->toBe(2);
    });
});

describe('disputa real entre processos', function () {
    it('vende a ultima vaga a um unico processo, com seis disputando ao mesmo tempo', function () {
        $cenario = cenarioVisivelParaOutrosProcessos([
            'capacidade' => 1,
            'prazo_pagamento_minutos' => 60,
        ]);

        try {
            $saidas = disputarEmParalelo($cenario, 6);

            $erros = array_values(array_filter(
                $saidas,
                fn (string $saida): bool => ! in_array($saida, ['ok', 'esgotado'], true),
            ));

            expect($erros)->toBe([], 'Algum processo falhou por motivo alheio a disputa: '.implode(' | ', $erros));

            $evento = $cenario->evento->fresh();
            $futebol = $cenario->futebol->fresh();

            expect(array_count_values($saidas)['ok'] ?? 0)->toBe(1)
                ->and(array_count_values($saidas)['esgotado'] ?? 0)->toBe(5)
                ->and($evento->vagas_reservadas + $evento->vagas_confirmadas)
                ->toBeLessThanOrEqual($evento->capacidade)
                ->and($evento->vagas_reservadas)->toBe(1)
                ->and($evento->vagas_confirmadas)->toBe(0)
                // A atividade nao ficou com vaga presa a mais: quem foi
                // recusado no evento nem chegou a reservar nela.
                ->and($futebol->vagas_reservadas)->toBe(1)
                ->and(Inscricao::where('evento_id', $cenario->evento->id)->count())->toBe(1);
        } finally {
            limparCenarioCommitado($cenario);
        }
    });
});
