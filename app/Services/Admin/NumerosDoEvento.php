<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\Evento;
use Illuminate\Support\Facades\DB;

/**
 * Os numeros que o painel mostra, por evento.
 *
 * Tudo aqui e leitura. Nenhuma regra de inscricao ou de pagamento passa por
 * este arquivo — ele so pergunta ao banco o que ja aconteceu.
 *
 * Duas escolhas que valem explicacao:
 *
 * 1. Cada bloco sai de UMA consulta agregada, com "count"/"sum" e "group by"
 *    feitos pelo banco. Um evento com 5.000 inscricoes nao pode virar 5.000
 *    objetos na memoria do PHP para mostrar quatro numeros.
 *
 * 2. A vaga restante de cada atividade vem do contador gravado na propria
 *    linha da atividade, que e a fonte da verdade do dominio. Recontar as
 *    escolhas de atividade criaria uma segunda versao do mesmo numero, e as
 *    duas iriam divergir no primeiro caso de borda.
 *
 * A presenca no portao mora em servico proprio (NumerosDePresenca) e e apenas
 * COMPOSTA aqui. O motivo e concreto: a tela da portaria precisa dos mesmos
 * dois numeros sem carregar vaga por atividade nem dinheiro, e um evento com
 * mil inscritos nao pode pagar por consultas que ninguem vai olhar no portao.
 */
class NumerosDoEvento
{
    public function __construct(private readonly NumerosDePresenca $presenca) {}

    /**
     * Junta os quatro blocos do painel para um evento.
     *
     * @return array{
     *     inscricoes: array{total: int, por_situacao: array<int, array{situacao: string, rotulo: string, total: int}>},
     *     vagas: array<int, array{atividade_id: int, atividade: string, grupo: string, dia: string, capacidade: int|null, reservadas: int, confirmadas: int, ocupadas: int, restantes: int|null}>,
     *     dinheiro: array{recebido_centavos: int, pendente_centavos: int, estornado_centavos: int, pagamentos_pagos: int, pagamentos_pendentes: int},
     *     presenca: array{presentes: int, faltantes: int, confirmadas: int}
     * }
     */
    public function paraEvento(Evento $evento): array
    {
        return [
            'inscricoes' => $this->inscricoesPorSituacao($evento),
            'vagas' => $this->vagasPorAtividade($evento),
            'dinheiro' => $this->dinheiro($evento),
            'presenca' => $this->presenca->paraEvento($evento),
        ];
    }

    /**
     * Quantas inscricoes existem em cada situacao, mais o total.
     *
     * Toda situacao aparece, inclusive as que estao zeradas: um evento sem
     * inscricao nenhuma mostra uma linha de zeros, e nao uma tela vazia que
     * deixa duvida se o numero e zero ou se a consulta falhou.
     *
     * @return array{total: int, por_situacao: array<int, array{situacao: string, rotulo: string, total: int}>}
     */
    public function inscricoesPorSituacao(Evento $evento): array
    {
        /** @var array<string, int> $contagens */
        $contagens = DB::table('inscricoes')
            ->where('evento_id', $evento->id)
            ->groupBy('situacao')
            ->selectRaw('situacao, count(*) as total')
            ->pluck('total', 'situacao')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $porSituacao = [];
        $total = 0;

        foreach (SituacaoInscricao::cases() as $situacao) {
            $quantidade = $contagens[$situacao->value] ?? 0;
            $total += $quantidade;

            $porSituacao[] = [
                'situacao' => $situacao->value,
                'rotulo' => $situacao->rotulo(),
                'total' => $quantidade,
            ];
        }

        return ['total' => $total, 'por_situacao' => $porSituacao];
    }

    /**
     * Capacidade, reservadas, confirmadas e restantes de cada atividade.
     *
     * "Restantes" fica nulo quando a atividade nao tem limite de vagas — nulo
     * e "sem limite", que e diferente de zero.
     *
     * @return array<int, array{atividade_id: int, atividade: string, grupo: string, dia: string, capacidade: int|null, reservadas: int, confirmadas: int, ocupadas: int, restantes: int|null}>
     */
    public function vagasPorAtividade(Evento $evento): array
    {
        return DB::table('atividades')
            ->join('grupos_atividades', 'grupos_atividades.id', '=', 'atividades.grupo_atividade_id')
            ->join('dias_evento', 'dias_evento.id', '=', 'grupos_atividades.dia_evento_id')
            ->where('dias_evento.evento_id', $evento->id)
            ->where('atividades.ativo', true)
            ->orderBy('dias_evento.posicao')
            ->orderBy('grupos_atividades.posicao')
            ->orderBy('atividades.posicao')
            ->orderBy('atividades.id')
            ->selectRaw('
                atividades.id as atividade_id,
                atividades.nome as atividade,
                grupos_atividades.nome as grupo,
                dias_evento.nome as dia,
                atividades.capacidade,
                atividades.vagas_reservadas,
                atividades.vagas_confirmadas,
                atividades.vagas_reservadas + atividades.vagas_confirmadas as ocupadas,
                case
                    when atividades.capacidade is null then null
                    else greatest(atividades.capacidade - atividades.vagas_reservadas - atividades.vagas_confirmadas, 0)
                end as restantes
            ')
            ->get()
            ->map(fn (object $linha): array => [
                'atividade_id' => (int) $linha->atividade_id,
                'atividade' => (string) $linha->atividade,
                'grupo' => (string) $linha->grupo,
                'dia' => (string) $linha->dia,
                'capacidade' => $linha->capacidade === null ? null : (int) $linha->capacidade,
                'reservadas' => (int) $linha->vagas_reservadas,
                'confirmadas' => (int) $linha->vagas_confirmadas,
                'ocupadas' => (int) $linha->ocupadas,
                'restantes' => $linha->restantes === null ? null : (int) $linha->restantes,
            ])
            ->all();
    }

    /**
     * Dinheiro que ja entrou, que ainda pode entrar e que voltou.
     *
     * Sempre em centavos e sempre inteiro: numero quebrado acumula erro de
     * arredondamento. A formatacao com virgula e "R$" acontece na tela.
     *
     * @return array{recebido_centavos: int, pendente_centavos: int, estornado_centavos: int, pagamentos_pagos: int, pagamentos_pendentes: int}
     */
    public function dinheiro(Evento $evento): array
    {
        $porSituacao = DB::table('pagamentos')
            ->join('inscricoes', 'inscricoes.id', '=', 'pagamentos.inscricao_id')
            ->where('inscricoes.evento_id', $evento->id)
            ->groupBy('pagamentos.situacao')
            ->selectRaw('
                pagamentos.situacao,
                count(*) as quantidade,
                sum(pagamentos.valor_centavos) as valor,
                sum(coalesce(pagamentos.valor_estornado_centavos, 0)) as estornado
            ')
            ->get()
            ->keyBy('situacao');

        $pago = $porSituacao->get(SituacaoPagamento::Pago->value);
        $pendente = $porSituacao->get(SituacaoPagamento::Pendente->value);
        $estorno = $porSituacao->get(SituacaoPagamento::Estornado->value);

        return [
            'recebido_centavos' => (int) ($pago->valor ?? 0),
            'pendente_centavos' => (int) ($pendente->valor ?? 0),
            'estornado_centavos' => (int) ($estorno->estornado ?? 0),
            'pagamentos_pagos' => (int) ($pago->quantidade ?? 0),
            'pagamentos_pendentes' => (int) ($pendente->quantidade ?? 0),
        ];
    }
}
