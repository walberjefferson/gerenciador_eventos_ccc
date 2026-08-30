<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SituacaoPagamento;
use App\Http\Controllers\Controller;
use App\Models\Cidade;
use App\Models\Evento;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Admin\FiltroDeInscricoes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A lista de inscricoes em CSV, para quem precisa dela numa planilha.
 *
 * Tres decisoes explicam quase todo este arquivo:
 *
 * 1. **Os mesmos filtros da tela.** A consulta vem de `FiltroDeInscricoes`, a
 *    mesma classe que a lista usa. Se fossem duas consultas, um dia o arquivo
 *    traria linhas diferentes das que a pessoa viu antes de clicar em exportar
 *    — e ela so descobriria depois de mandar a planilha para alguem.
 *
 * 2. **Nada e montado na memoria.** O arquivo sai por `streamDownload`, linha a
 *    linha, com um cursor aberto no banco. Um evento com dez mil inscritos e
 *    escrito com o mesmo consumo de memoria de um evento com dez.
 *
 * 3. **Ponto e virgula e BOM UTF-8.** E o que faz o Excel em portugues abrir o
 *    arquivo com as colunas separadas e os acentos no lugar. Virgula pura
 *    embaralha tudo, e sem o BOM "Inscrição" vira "InscriÃ§Ã£o".
 *
 * O CPF **nao tem coluna**. Nem cifrado, nem em pedaco, nem a impressao
 * digital. Planilha e o formato que mais viaja por e-mail e mais fica esquecida
 * em pasta compartilhada; o documento de ninguem viaja junto.
 */
class ExportarInscricoesController extends Controller
{
    /**
     * O cabecalho do arquivo, em portugues, na ordem em que as colunas saem.
     *
     * @var list<string>
     */
    private const CABECALHO = [
        'Código',
        'Nome',
        'E-mail',
        'Telefone',
        'Evento',
        'Cidade',
        'Grupo',
        'Situação',
        'Situação da cobrança',
        'Valor (R$)',
        'Inscrita em',
        'Prazo de pagamento',
        'Confirmada em',
        'Cancelada em',
        'Motivo do cancelamento',
        'Atividades',
    ];

    public function __invoke(Request $pedido): StreamedResponse
    {
        $this->authorize('exportar', Inscricao::class);

        $consulta = FiltroDeInscricoes::doPedido($pedido)->consulta();

        // A lista carrega as relacoes de uma vez porque mostra 25 linhas. Aqui
        // seriam milhares, entao as relacoes viram subconsultas de uma coluna
        // so: o cursor faz uma consulta unica e nao volta ao banco por linha.
        $consulta
            ->without(['evento', 'grupoParticipante', 'grupoParticipante.cidade', 'pagamentos'])
            ->select('inscricoes.*')
            ->addSelect([
                'evento_nome' => Evento::query()
                    ->select('eventos.nome')
                    ->whereColumn('eventos.id', 'inscricoes.evento_id')
                    ->limit(1),
                'grupo_nome' => GrupoParticipante::query()
                    ->select('grupos_participantes.nome')
                    ->whereColumn('grupos_participantes.id', 'inscricoes.grupo_participante_id')
                    ->limit(1),
                'cidade_nome' => Cidade::query()
                    ->select('cidades.nome')
                    ->join('grupos_participantes', 'grupos_participantes.cidade_id', '=', 'cidades.id')
                    ->whereColumn('grupos_participantes.id', 'inscricoes.grupo_participante_id')
                    ->limit(1),
                'cidade_uf' => Cidade::query()
                    ->select('cidades.uf')
                    ->join('grupos_participantes', 'grupos_participantes.cidade_id', '=', 'cidades.id')
                    ->whereColumn('grupos_participantes.id', 'inscricoes.grupo_participante_id')
                    ->limit(1),
                // A cobranca mais recente e a que descreve a situacao de hoje:
                // se a primeira venceu e outra foi emitida, e a nova que vale.
                'pagamento_situacao' => Pagamento::query()
                    ->select('pagamentos.situacao')
                    ->whereColumn('pagamentos.inscricao_id', 'inscricoes.id')
                    ->orderByDesc('pagamentos.id')
                    ->limit(1),
                'atividades_nomes' => DB::table('inscricoes_atividades')
                    ->selectRaw("string_agg(atividades.nome, ', ' order by atividades.id)")
                    ->join('atividades', 'atividades.id', '=', 'inscricoes_atividades.atividade_id')
                    ->whereColumn('inscricoes_atividades.inscricao_id', 'inscricoes.id'),
            ]);

        $nomeDoArquivo = 'inscricoes-'.Carbon::now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($consulta): void {
            $saida = fopen('php://output', 'w');

            if ($saida === false) {
                return;
            }

            // O BOM vem antes de qualquer outra coisa: e por ele que o Excel
            // em portugues sabe que o arquivo esta em UTF-8.
            fwrite($saida, "\u{FEFF}");

            fputcsv($saida, self::CABECALHO, ';', '"', '');

            foreach ($consulta->cursor() as $inscricao) {
                fputcsv($saida, $this->linha($inscricao), ';', '"', '');
                flush();
            }

            fclose($saida);
        }, $nomeDoArquivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Uma linha do arquivo. Nenhuma coluna de documento, por decisao.
     *
     * @return list<string>
     */
    private function linha(Inscricao $inscricao): array
    {
        $cidade = $this->texto($inscricao->getAttribute('cidade_nome'));
        $uf = $this->texto($inscricao->getAttribute('cidade_uf'));
        $situacaoDaCobranca = SituacaoPagamento::tryFrom(
            $this->texto($inscricao->getAttribute('pagamento_situacao'))
        );

        return array_map($this->semFormula(...), [
            $inscricao->codigo_publico,
            $inscricao->nome_completo,
            $inscricao->email,
            $inscricao->telefone,
            $this->texto($inscricao->getAttribute('evento_nome')),
            $cidade === '' ? '' : "{$cidade}/{$uf}",
            $this->texto($inscricao->getAttribute('grupo_nome')),
            $inscricao->situacao->rotulo(),
            $situacaoDaCobranca?->rotulo() ?? '',
            number_format($inscricao->valor_centavos / 100, 2, ',', '.'),
            $this->momento($inscricao->created_at),
            $this->momento($inscricao->prazo_pagamento),
            $this->momento($inscricao->confirmada_em),
            $this->momento($inscricao->cancelada_em),
            $inscricao->motivo_cancelamento ?? '',
            $this->texto($inscricao->getAttribute('atividades_nomes')),
        ]);
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }

    private function momento(mixed $valor): string
    {
        return $valor instanceof Carbon ? $valor->format('d/m/Y H:i') : '';
    }

    /**
     * Desarma texto que a planilha leria como formula.
     *
     * Um nome que comece com "=" ou "+" e so um nome; para o Excel, e codigo
     * para executar. Uma aspa simples na frente resolve, e a planilha continua
     * mostrando o texto original.
     */
    private function semFormula(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) === 1 ? "'".$valor : $valor;
    }
}
