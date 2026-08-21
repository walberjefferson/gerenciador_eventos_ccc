<?php

declare(strict_types=1);

use App\Models\Inscricao;
use Tests\Feature\Inscricoes\Disputa;

/**
 * A abertura das inscricoes, que e o unico momento em que este sistema fica
 * realmente sob pressao.
 *
 * O ConcorrenciaTest ja prova a garantia com seis processos. Aqui sao
 * cinquenta, todos largando no mesmo instante, cada um com a sua propria
 * conexao com o banco, disputando as ultimas cinco vagas de uma atividade. E a
 * situacao do dia do anuncio: o link circula no grupo da comunidade e todo
 * mundo abre ao mesmo tempo.
 *
 * Tres coisas precisam ficar provadas:
 *
 * 1. A capacidade nunca e furada — nem por uma. Vaga vendida duas vezes vira
 *    gente chegando no evento e descobrindo que nao tem lugar.
 * 2. Ninguem trava esperando outro. A garantia vem de gravacao condicional, e
 *    nao de tranca no banco, justamente para que ninguem fique parado numa fila
 *    invisivel. Impasse entre transacoes apareceria aqui como erro do Postgres.
 * 3. O tempo de resposta sob disputa fica registrado (docs/PERFORMANCE.md).
 *
 * Se este teste falhar, o defeito e real e anterior a esta fase: nao se
 * conserta contador em cima do laco.
 */

/** Quantos processos disputam ao mesmo tempo. */
const CONCORRENTES = 50;

/** Quantas vagas existem para os cinquenta. */
const VAGAS_DISPUTADAS = 5;

/**
 * Le a saida "resultado|segundos" que o script devolve no formato com tempo.
 *
 * @param  list<string>  $saidas
 * @return array{resultados: list<string>, tempos: list<float>}
 */
function separarResultadosETempos(array $saidas): array
{
    $resultados = [];
    $tempos = [];

    foreach ($saidas as $saida) {
        $partes = explode('|', $saida);

        $resultados[] = $partes[0];

        if (isset($partes[1])) {
            $tempos[] = (float) $partes[1];
        }
    }

    return ['resultados' => $resultados, 'tempos' => $tempos];
}

/**
 * @param  list<float>  $tempos
 */
function percentil(array $tempos, float $fracao): float
{
    if ($tempos === []) {
        return 0.0;
    }

    sort($tempos);

    $posicao = (int) ceil($fracao * count($tempos)) - 1;

    return $tempos[max(0, min($posicao, count($tempos) - 1))];
}

it('nao fura a capacidade da atividade com cinquenta processos disputando cinco vagas', function () {
    // O evento tem folga de sobra: quem limita aqui e a atividade, que e onde
    // a disputa costuma acontecer de verdade (a modalidade mais procurada
    // esgota muito antes do evento inteiro).
    $cenario = Disputa::cenarioVisivelParaOutrosProcessos([
        'capacidade' => 500,
        'prazo_pagamento_minutos' => 60,
    ]);

    try {
        // A capacidade precisa ser gravada pela conexao que os outros processos
        // enxergam: dentro da transacao do teste ela seria invisivel para eles.
        Disputa::gravarParaTodos('atividades', $cenario->futebol->id, [
            'capacidade' => VAGAS_DISPUTADAS,
        ]);

        // Cinquenta processos precisam de mais tempo para nascer do que seis:
        // sem essa folga, os primeiros terminariam antes de o ultimo subir e
        // nao haveria disputa nenhuma para medir.
        $saidas = Disputa::emParalelo($cenario, CONCORRENTES, margemDeLargada: 8.0, formato: 'com-tempo');

        ['resultados' => $resultados, 'tempos' => $tempos] = separarResultadosETempos($saidas);

        $inesperados = array_values(array_filter(
            $resultados,
            fn (string $resultado): bool => ! in_array($resultado, ['ok', 'esgotado'], true),
        ));

        // Impasse entre transacoes chegaria aqui como erro do Postgres com a
        // palavra "deadlock". A checagem e separada para que a mensagem da
        // falha diga logo do que se trata.
        $impasses = array_values(array_filter(
            $inesperados,
            fn (string $erro): bool => str_contains(mb_strtolower($erro), 'deadlock'),
        ));

        expect($impasses)->toBe([], 'Houve impasse entre transacoes: '.implode(' | ', $impasses));
        expect($inesperados)->toBe([], 'Algum processo falhou por motivo alheio a disputa: '.implode(' | ', $inesperados));

        $contagem = array_count_values($resultados);
        $futebol = $cenario->futebol->fresh();
        $evento = $cenario->evento->fresh();

        // O numero exato: cinco entraram, quarenta e cinco foram recusados.
        expect($contagem['ok'] ?? 0)->toBe(VAGAS_DISPUTADAS)
            ->and($contagem['esgotado'] ?? 0)->toBe(CONCORRENTES - VAGAS_DISPUTADAS);

        // Nem por uma: reservadas + confirmadas nunca passa da capacidade.
        expect($futebol->vagas_reservadas + $futebol->vagas_confirmadas)
            ->toBe(VAGAS_DISPUTADAS)
            ->toBeLessThanOrEqual($futebol->capacidade);

        // E o contador do evento nao ficou com vaga presa por quem foi recusado
        // na atividade: a transacao inteira volta atras quando a vaga falta.
        expect($evento->vagas_reservadas)->toBe(VAGAS_DISPUTADAS)
            ->and($evento->vagas_confirmadas)->toBe(0)
            ->and(Inscricao::where('evento_id', $cenario->evento->id)->count())->toBe(VAGAS_DISPUTADAS);

        // O tempo de resposta sob disputa, para o relatorio. Nao vira teto de
        // teste: maquina de desenvolvimento oscila, e teste que falha por
        // milissegundo vira teste que a equipe aprende a ignorar.
        $mediana = percentil($tempos, 0.5);

        fwrite(STDERR, sprintf(
            "\n  [carga] %d processos / %d vagas — tempo do caminho da inscricao:".
            " minimo %.3Fs · mediana %.3Fs · p95 %.3Fs · maximo %.3Fs\n",
            CONCORRENTES,
            VAGAS_DISPUTADAS,
            min($tempos),
            $mediana,
            percentil($tempos, 0.95),
            max($tempos),
        ));

        expect($tempos)->toHaveCount(CONCORRENTES);

        // Um teto largo, so para pegar regressao grosseira: se a mediana passar
        // de cinco segundos sob disputa, alguma coisa comecou a esperar em fila.
        expect($mediana)->toBeLessThan(5.0);
    } finally {
        Disputa::limparCenarioCommitado($cenario);
    }
});
