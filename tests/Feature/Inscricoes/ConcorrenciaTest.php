<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Exceptions\Inscricoes\VagasEsgotadasException;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inscricoes\Cenario;
use Tests\Feature\Inscricoes\Disputa;

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
 * Os processos de verdade que disputam a vaga moram em Tests\Feature\
 * Inscricoes\Disputa: a mesma maquinaria serve a este teste, com seis
 * concorrentes, e ao teste de carga, com cinquenta. Os tres atalhos abaixo
 * existem so para o texto dos cenarios continuar se lendo como antes.
 */
function cenarioVisivelParaOutrosProcessos(array $atributosDoEvento = []): Cenario
{
    return Disputa::cenarioVisivelParaOutrosProcessos($atributosDoEvento);
}

function limparCenarioCommitado(Cenario $cenario): void
{
    Disputa::limparCenarioCommitado($cenario);
}

/**
 * @return list<string>
 */
function disputarEmParalelo(Cenario $cenario, int $quantidade, float $margemDeLargada = 2.0): array
{
    return Disputa::emParalelo($cenario, $quantidade, $margemDeLargada);
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
