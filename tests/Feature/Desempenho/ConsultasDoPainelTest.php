<?php

declare(strict_types=1);

use App\Models\Evento;
use App\Services\Admin\FiltroDeInscricoes;
use App\Services\Admin\NumerosDoEvento;
use Database\Seeders\PapeisSeeder;
use Database\Seeders\VolumeSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Cenario;

/**
 * O que este arquivo protege, em uma frase: **a quantidade de consultas ao
 * banco nao pode crescer junto com a quantidade de inscritos.**
 *
 * A medicao de desempenho da Fase 9 esta registrada em `docs/PERFORMANCE.md` e
 * foi feita a mao, uma vez. Este teste e a parte dela que roda para sempre: se
 * alguem transformar uma consulta agregada em contagem no PHP, ou carregar as
 * relacoes de uma lista de 25 linhas com 25 idas ao banco, a suite quebra aqui.
 *
 * **Sobre os tetos de tempo:** eles sao folgados de proposito. A maquina que
 * roda a suite varia — notebook, servidor de integracao, contêiner apertado —
 * e um teto justo viraria teste que falha sozinho, que e o pior tipo de teste.
 * Os tetos daqui pegam mudanca de ORDEM DE GRANDEZA (5 ms virando 5 segundos),
 * nao variacao de maquina. O numero fino esta no relatorio.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();

    (new VolumeSeeder)->run();

    $this->evento = Evento::query()->where('slug', VolumeSeeder::SLUG)->firstOrFail();
});

/**
 * Conta quantas consultas o trecho dispara, sem contar as do preparo.
 *
 * @return array{consultas: int, ms: float, resultado: mixed}
 */
function contandoConsultas(Closure $trecho): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $comecou = microtime(true);
    $resultado = $trecho();
    $ms = (microtime(true) - $comecou) * 1000;

    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    return ['consultas' => $consultas, 'ms' => $ms, 'resultado' => $resultado];
}

it('o volume da medicao entra no banco inteiro e coerente', function (): void {
    expect(DB::table('inscricoes')->where('evento_id', $this->evento->id)->count())
        ->toBe(VolumeSeeder::TOTAL);

    // Os contadores de vaga sao a fonte da verdade do dominio. Se o seeder os
    // deixasse zerados, a medicao mediria uma tela mentindo.
    $ocupadas = DB::table('atividades')
        ->join('grupos_atividades', 'grupos_atividades.id', '=', 'atividades.grupo_atividade_id')
        ->join('dias_evento', 'dias_evento.id', '=', 'grupos_atividades.dia_evento_id')
        ->where('dias_evento.evento_id', $this->evento->id)
        ->selectRaw('max(vagas_reservadas + vagas_confirmadas) as maximo, min(capacidade) as capacidade')
        ->first();

    expect((int) $ocupadas->maximo)->toBeLessThanOrEqual((int) $ocupadas->capacidade);
});

it('o painel responde em quatro consultas agregadas, quaisquer que sejam os inscritos', function (): void {
    $medida = contandoConsultas(fn () => app(NumerosDoEvento::class)->paraEvento($this->evento));

    // Uma por bloco: inscricoes por situacao, vagas por atividade, dinheiro e
    // presenca no portao. Foram tres ate o controle de presenca entrar; a
    // quarta conta presentes e faltantes na MESMA varredura, e nao em duas.
    // Se este numero subir, alguem passou a contar linha por linha no PHP.
    expect($medida['consultas'])->toBe(4);

    $numeros = $medida['resultado'];

    expect($numeros['inscricoes']['total'])->toBe(VolumeSeeder::TOTAL)
        ->and($numeros['dinheiro']['pagamentos_pagos'])->toBeGreaterThan(0)
        // Os dois numeros do portao fecham mesmo com milhares de inscritos.
        ->and($numeros['presenca']['presentes'] + $numeros['presenca']['faltantes'])
        ->toBe($numeros['presenca']['confirmadas']);

    expect($medida['ms'])->toBeLessThan(2_000.0);
});

it('a lista de inscricoes nao faz uma consulta por linha', function (): void {
    $pedido = new Request(['evento_id' => (string) $this->evento->id]);

    $vinteECinco = contandoConsultas(
        fn () => FiltroDeInscricoes::doPedido($pedido)->consulta()->paginate(25)
    );

    $cem = contandoConsultas(
        fn () => FiltroDeInscricoes::doPedido($pedido)->consulta()->paginate(100)
    );

    // Quatro vezes mais linhas na tela, o MESMO numero de consultas. E essa
    // igualdade — e nao o valor em si — que prova a ausencia de N+1.
    expect($cem['consultas'])->toBe($vinteECinco['consultas'])
        ->and($vinteECinco['consultas'])->toBeLessThanOrEqual(8);

    expect($vinteECinco['ms'])->toBeLessThan(2_000.0);
});

it('os filtros mais pesados combinados nao multiplicam as consultas', function (): void {
    $atividade = DB::table('atividades')
        ->join('grupos_atividades', 'grupos_atividades.id', '=', 'atividades.grupo_atividade_id')
        ->join('dias_evento', 'dias_evento.id', '=', 'grupos_atividades.dia_evento_id')
        ->where('dias_evento.evento_id', $this->evento->id)
        ->value('atividades.id');

    $medida = contandoConsultas(fn () => FiltroDeInscricoes::doPedido(new Request([
        'evento_id' => (string) $this->evento->id,
        'situacao' => 'confirmada',
        'atividade_id' => (string) $atividade,
        'situacao_pagamento' => 'pago',
        'criada_de' => '2026-08-01',
        'criada_ate' => '2026-10-31',
    ]))->consulta()->paginate(25));

    expect($medida['consultas'])->toBeLessThanOrEqual(9)
        ->and($medida['ms'])->toBeLessThan(2_000.0);
});

it('a exportacao do evento inteiro sai em uma unica consulta', function (): void {
    $usuario = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $medida = contandoConsultas(function () use ($usuario): string {
        $resposta = $this->actingAs($usuario)
            ->get(route('admin.inscricoes.exportar', ['evento_id' => $this->evento->id]));

        $resposta->assertOk();

        return $resposta->streamedContent();
    });

    // Uma consulta para dez mil linhas. As colunas que viriam de outra tabela
    // (evento, cidade, grupo, cobranca, atividades) sao subconsultas de uma
    // coluna so — sem elas, seriam dez mil idas ao banco.
    $consultasDeDados = $medida['consultas'];

    expect($consultasDeDados)->toBeLessThanOrEqual(6);

    // O arquivo tem cabecalho + uma linha por inscricao.
    expect(substr_count($medida['resultado'], "\n"))->toBeGreaterThanOrEqual(VolumeSeeder::TOTAL);
});
