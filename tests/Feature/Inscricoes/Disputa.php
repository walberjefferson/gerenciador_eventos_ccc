<?php

declare(strict_types=1);

namespace Tests\Feature\Inscricoes;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A maquinaria que poe processos de verdade para disputar a mesma vaga.
 *
 * Threads simuladas dentro de um unico processo nao provariam nada: o risco
 * real mora entre duas conexoes com o banco, cada uma enxergando o mundo por
 * conta propria. Por isso cada concorrente aqui e um processo de sistema
 * operacional, com a sua propria conexao, comecando no mesmo instante.
 *
 * Serve a dois testes: o de concorrencia (ConcorrenciaTest, seis processos) e
 * o de carga (CargaTest, cinquenta). Mora numa classe, e nao dentro de um dos
 * dois arquivos, porque funcao declarada num teste so existe se aquele arquivo
 * tiver sido carregado — e rodar um teste sozinho e coisa que se faz o tempo
 * todo.
 */
final class Disputa
{
    /**
     * Monta o cenario numa conexao propria, fora da transacao do teste.
     *
     * Os processos que disputam a vaga so enxergam o que ja foi confirmado no
     * banco. O que o teste grava dentro da transacao que o RefreshDatabase
     * abre e invisivel para eles.
     *
     * @param  array<string, mixed>  $atributosDoEvento
     */
    public static function cenarioVisivelParaOutrosProcessos(array $atributosDoEvento = []): Cenario
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
     * Muda algo do cenario de um jeito que os outros processos enxerguem.
     *
     * Alterar pela conexao do teste nao adiantaria: a mudanca ficaria presa na
     * transacao aberta pelo RefreshDatabase, e os concorrentes continuariam
     * vendo o valor antigo.
     *
     * @param  array<string, mixed>  $valores
     */
    public static function gravarParaTodos(string $tabela, int $id, array $valores): void
    {
        DB::connection('disputa')->table($tabela)->where('id', $id)->update($valores);
    }

    /**
     * Apaga o que foi gravado fora da transacao do teste.
     *
     * Esta e a unica excecao a regra de nunca apagar registro: nao ha dominio
     * aqui, e sim sujeira de teste que a transacao do RefreshDatabase nao
     * alcanca e que estragaria as contagens dos testes seguintes.
     */
    public static function limparCenarioCommitado(Cenario $cenario): void
    {
        $conexao = DB::connection('disputa');

        // As demais tabelas saem por cascata: inscricoes_atividades vai junto
        // com as inscricoes, e dias_evento, grupos e atividades vao junto com o
        // evento. As cobrancas precisam sair antes das inscricoes: a chave
        // estrangeira e "restrict" de proposito, para que nenhum pagamento suma
        // sem querer.
        $inscricoes = $conexao->table('inscricoes')
            ->where('evento_id', $cenario->evento->id)
            ->pluck('id')
            ->all();

        $conexao->table('pagamentos')->whereIn('inscricao_id', $inscricoes)->delete();
        // Pelo mesmo motivo, os registros de e-mail enviado saem antes: a chave
        // tambem e "restrict", para que nenhum comprovante de envio se perca.
        $conexao->table('comunicacoes_enviadas')->whereIn('inscricao_id', $inscricoes)->delete();
        $conexao->table('inscricoes')->where('evento_id', $cenario->evento->id)->delete();
        $conexao->table('eventos')->where('id', $cenario->evento->id)->delete();
        $conexao->table('grupos_participantes')->where('id', $cenario->grupoParticipante->id)->delete();
        $conexao->table('cidades')->where('id', $cenario->cidade->id)->delete();
    }

    /**
     * Dispara varios processos independentes disputando a mesma vaga.
     *
     * Todos recebem o mesmo instante de largada, para que ninguem termine antes
     * de o ultimo nascer. Devolve a saida de cada um: "ok", "esgotado" ou
     * "erro: ..." — e, no formato "com-tempo", tambem quantos segundos a
     * inscricao levou.
     *
     * @param  list<int>|null  $atividadeIds  as atividades disputadas; por padrao, o futebol
     * @return list<string>
     */
    public static function emParalelo(
        Cenario $cenario,
        int $quantidade,
        float $margemDeLargada = 2.0,
        string $formato = 'simples',
        ?array $atividadeIds = null,
    ): array {
        $raiz = base_path();
        $script = $raiz.'/tests/Feature/Inscricoes/scripts/disputar-vaga.php';
        $conexao = config('database.connections.pgsql');
        $atividades = implode(',', $atividadeIds ?? [$cenario->futebol->id]);

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
                    $atividades,
                    (string) $indice,
                    sprintf('%.6F', $largada),
                    $formato,
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
}
