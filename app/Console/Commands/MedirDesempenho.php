<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Inscricoes\ExpirarInscricoesVencidas;
use App\Http\Controllers\Admin\ExportarInscricoesController;
use App\Http\Controllers\EventoPublicoController;
use App\Models\Evento;
use App\Services\Admin\FiltroDeInscricoes;
use App\Services\Admin\NumerosDoEvento;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Mede as cinco consultas mais pesadas do sistema com o banco cheio.
 *
 * Por que isto e um comando, e nao um script jogado fora depois: a Fase 9 exige
 * o numero **antes** e o numero **depois** de cada correcao. Comparar duas
 * medicoes feitas por caminhos diferentes nao prova nada; o mesmo comando,
 * rodado duas vezes, prova.
 *
 * Ele nao inventa consulta nenhuma: chama os mesmos servicos, o mesmo
 * controlador e a mesma rotina que o sistema usa. O que ele acrescenta e o
 * cronometro e o `EXPLAIN ANALYZE` da consulta mais cara de cada cenario.
 *
 * So roda em `local` e `testing`, porque precisa do banco carregado pelo
 * `VolumeSeeder`.
 */
class MedirDesempenho extends Command
{
    protected $signature = 'desempenho:medir
        {--slug=volume-10k : slug do evento carregado pelo VolumeSeeder}
        {--repeticoes=5 : quantas vezes cada cenario e executado}
        {--plano : mostra o EXPLAIN ANALYZE completo de cada cenario}';

    protected $description = 'Mede com cronometro e EXPLAIN ANALYZE as consultas do painel, da lista, da exportacao, da pagina publica e da expiracao';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('desempenho:medir so roda em local ou testing.');

            return self::FAILURE;
        }

        $evento = Evento::query()->where('slug', $this->option('slug'))->first();

        if ($evento === null) {
            $this->error('Evento nao encontrado. Rode antes: php artisan db:seed --class=Database\\\\Seeders\\\\VolumeSeeder');

            return self::FAILURE;
        }

        // A exportacao exige permissao. O que se mede aqui e a consulta, nao a
        // permissao — e o comando so existe em local e testing.
        Gate::before(fn (?Authenticatable $usuario): bool => true);

        $inscricoes = DB::table('inscricoes')->where('evento_id', $evento->id)->count();
        $this->info("Evento {$evento->slug} — {$inscricoes} inscricoes no banco.");
        $this->newLine();

        $linhas = [];

        foreach ($this->cenarios($evento) as $nome => $operacao) {
            $linhas[] = $this->medir($nome, $operacao);
        }

        $this->table(
            ['Consulta', 'Mediana', 'Pior', 'Consultas SQL', 'EXPLAIN'],
            array_map(fn (array $linha): array => [
                $linha['nome'],
                $linha['mediana'].' ms',
                $linha['pior'].' ms',
                (string) $linha['consultas'],
                $linha['plano_ms'] === null ? '—' : $linha['plano_ms'].' ms',
            ], $linhas),
        );

        if ($this->option('plano')) {
            foreach ($linhas as $linha) {
                $this->newLine();
                $this->line('### '.$linha['nome']);
                $this->line($linha['sql'] ?? '(sem SQL)');
                $this->newLine();
                $this->line($linha['plano'] ?? '(sem plano)');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Os cinco cenarios de §3.4 do plano, na mesma ordem.
     *
     * A expiracao fica por ultimo de proposito: e o unico que escreve, e mesmo
     * desfeito ao final ele deixa a tabela com paginas sujas.
     *
     * @return array<string, Closure>
     */
    private function cenarios(Evento $evento): array
    {
        return [
            'Painel — os tres blocos' => function () use ($evento): void {
                app(NumerosDoEvento::class)->paraEvento($evento);
            },

            'Lista de inscricoes — sem filtro' => function () use ($evento): void {
                FiltroDeInscricoes::doPedido(new Request(['evento_id' => (string) $evento->id]))
                    ->consulta()
                    ->paginate(25);
            },

            'Lista de inscricoes — filtros combinados' => function () use ($evento): void {
                FiltroDeInscricoes::doPedido(new Request([
                    'evento_id' => (string) $evento->id,
                    'situacao' => 'confirmada',
                    'atividade_id' => (string) $this->umaAtividade($evento),
                    'situacao_pagamento' => 'pago',
                    'criada_de' => '2026-08-01',
                    'criada_ate' => '2026-10-31',
                ]))->consulta()->paginate(25);
            },

            'Exportacao CSV — evento inteiro' => function () use ($evento): void {
                $this->exportar($evento);
            },

            'Pagina publica do evento' => function () use ($evento): void {
                $this->paginaPublica($evento);
            },

            'Expiracao de inscricoes vencidas' => function () use ($evento): void {
                $this->expirar($evento);
            },
        ];
    }

    /**
     * Roda o cenario algumas vezes, cronometra todas e guarda a consulta mais
     * cara para o `EXPLAIN ANALYZE`.
     *
     * A **mediana** e o numero que vale: a primeira execucao paga o custo de
     * encher o cache do banco e nao representa o dia a dia.
     *
     * @return array{nome: string, mediana: float, pior: float, consultas: int, sql: string|null, plano: string|null, plano_ms: float|null}
     */
    private function medir(string $nome, Closure $operacao): array
    {
        $repeticoes = max(1, (int) $this->option('repeticoes'));
        $tempos = [];
        $maisCara = null;
        $consultas = 0;

        for ($i = 0; $i < $repeticoes; $i++) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $comecou = microtime(true);
            $operacao();
            $tempos[] = round((microtime(true) - $comecou) * 1000, 1);

            $registro = DB::getQueryLog();
            DB::disableQueryLog();

            $consultas = count($registro);

            foreach ($registro as $consulta) {
                if ($maisCara === null || $consulta['time'] > $maisCara['time']) {
                    $maisCara = $consulta;
                }
            }
        }

        sort($tempos);

        [$plano, $planoMs] = $maisCara === null
            ? [null, null]
            : $this->explicar((string) $maisCara['query'], (array) $maisCara['bindings']);

        return [
            'nome' => $nome,
            'mediana' => $tempos[intdiv(count($tempos), 2)],
            'pior' => max($tempos),
            'consultas' => $consultas,
            'sql' => $maisCara === null ? null : (string) $maisCara['query'],
            'plano' => $plano,
            'plano_ms' => $planoMs,
        ];
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array{0: string|null, 1: float|null}
     */
    private function explicar(string $sql, array $bindings): array
    {
        if (! str_starts_with(strtolower(ltrim($sql)), 'select')) {
            return [null, null];
        }

        try {
            $linhas = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $bindings);
        } catch (\Throwable $erro) {
            return ['EXPLAIN falhou: '.$erro->getMessage(), null];
        }

        $texto = implode("\n", array_map(
            fn (object $linha): string => (string) ($linha->{'QUERY PLAN'} ?? ''),
            $linhas,
        ));

        preg_match('/Execution Time: ([\d.]+) ms/', $texto, $encontrado);

        return [$texto, isset($encontrado[1]) ? round((float) $encontrado[1], 3) : null];
    }

    private function umaAtividade(Evento $evento): int
    {
        $atividade = DB::table('atividades')
            ->join('grupos_atividades', 'grupos_atividades.id', '=', 'atividades.grupo_atividade_id')
            ->join('dias_evento', 'dias_evento.id', '=', 'grupos_atividades.dia_evento_id')
            ->where('dias_evento.evento_id', $evento->id)
            ->orderBy('atividades.id')
            ->value('atividades.id');

        if ($atividade === null) {
            throw new RuntimeException('O evento de volume nao tem atividade nenhuma.');
        }

        return (int) $atividade;
    }

    /**
     * A exportacao inteira, consumida ate a ultima linha.
     */
    private function exportar(Evento $evento): void
    {
        $pedido = Request::create('/admin/inscricoes/exportar', 'GET', ['evento_id' => (string) $evento->id]);
        $resposta = app(ExportarInscricoesController::class)($pedido);

        ob_start();
        $resposta->sendContent();
        ob_end_clean();
    }

    /**
     * A pagina publica com a programacao inteira, com as propriedades do Inertia
     * ja resolvidas — que e onde o custo de verdade aparece.
     */
    private function paginaPublica(Evento $evento): void
    {
        $pedido = Request::create('/eventos/'.$evento->slug, 'GET', server: ['HTTP_X_INERTIA' => 'true']);
        app()->instance('request', $pedido);

        app(EventoPublicoController::class)->show($evento->slug)->toResponse($pedido);
    }

    /**
     * A varredura de vencidas com o relogio adiantado, para que TODAS as
     * inscricoes aguardando pagamento estejam vencidas ao mesmo tempo.
     *
     * Tudo dentro de uma transacao desfeita ao final: a medicao nao pode
     * mudar o banco que as outras medicoes usam.
     */
    private function expirar(Evento $evento): void
    {
        DB::beginTransaction();

        try {
            app(ExpirarInscricoesVencidas::class)($evento, Carbon::parse('2027-01-01'));
        } finally {
            DB::rollBack();
        }
    }
}
