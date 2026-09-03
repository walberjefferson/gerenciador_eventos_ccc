<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Enums\SituacaoInscricao;
use App\Models\Cidade;
use App\Models\Evento;
use App\Models\Responsavel;
use Illuminate\Support\Facades\DB;

/**
 * Escolhe qual responsavel do setor vai receber esta cobranca (RN-R4).
 *
 * A regra inteira mora aqui, e mora num lugar so de proposito: ela e curta o
 * bastante para ser reescrita em qualquer controller que precise dela, e uma
 * copia com um "orderBy" a mais deixaria de ser sorteio sem que ninguem
 * notasse.
 *
 * Sao dois passos:
 *
 * 1. **Quem pode.** Responsaveis ativos, com chave, vinculados ao setor da
 *    inscricao. Inativo, sem chave ou de outro setor nao entra — nunca.
 * 2. **Entre os menos carregados, ao acaso.** Carga e quantas inscricoes ativas
 *    daquele EVENTO ja apontam para cada candidato, por `pagamentos.
 *    responsavel_id`. Toma-se o menor valor e sorteia-se entre os empatados
 *    nele. Ordenar por carga e pegar o primeiro devolveria sempre o de menor
 *    id nos empates — o que e ordenacao, nao sorteio; sortear sem olhar a carga
 *    deixaria alguem com o dobro do outro por azar. As duas metades juntas dao
 *    equilibrio E imprevisibilidade.
 *
 * **A carga conta o evento, e nao a historia inteira.** Equilibrar entre
 * eventos faria um responsavel novo herdar a carga de outro e receber tudo do
 * evento seguinte.
 *
 * **Nao ha trava aqui, e a ausencia e decisao (RN-R8).** Duas inscricoes
 * simultaneas podem ler a mesma carga e sortear a mesma pessoa. Isso e
 * aceitavel porque a carga e BALANCEAMENTO, nunca capacidade: nada estoura,
 * nada e vendido duas vezes, e o pior desfecho e um responsavel com uma
 * inscricao a mais que o outro. Um `SELECT ... FOR UPDATE` serializaria a
 * emissao de cobranca do setor inteiro para corrigir um desequilibrio de uma
 * unidade — custo que nao se paga.
 */
class SortearResponsavel
{
    /**
     * O escolhido, ou nulo quando o setor nao tem ninguem apto.
     *
     * Nulo nao e erro aqui: quem decide o que fazer com "nao ha para quem
     * mandar" e quem emite a cobranca, que tem a mensagem certa para dar
     * (RN-R10).
     */
    public function __invoke(Cidade $setor, Evento $evento): ?Responsavel
    {
        /** @var array<int, Responsavel> $candidatos */
        $candidatos = $setor->responsaveis()->aptos()->get()->all();

        if ($candidatos === []) {
            return null;
        }

        $cargas = $this->cargaNoEvento($evento, array_map(
            fn (Responsavel $candidato): int => (int) $candidato->getKey(),
            $candidatos,
        ));

        $menorCarga = min(array_map(
            fn (Responsavel $candidato): int => $cargas[(int) $candidato->getKey()] ?? 0,
            $candidatos,
        ));

        $empatados = array_values(array_filter(
            $candidatos,
            fn (Responsavel $candidato): bool => ($cargas[(int) $candidato->getKey()] ?? 0) === $menorCarga,
        ));

        return $empatados[array_rand($empatados)];
    }

    /**
     * Quantas inscricoes ativas deste evento ja apontam para cada candidato.
     *
     * Conta INSCRICAO, e nao cobranca: uma inscricao que teve a primeira
     * cobranca vencida e outra emitida apontaria duas vezes para a mesma
     * pessoa, e ela nao ganhou duas pessoas para atender. Quem nunca recebeu
     * nada nao aparece no resultado — para o chamador, isso e carga zero.
     *
     * @param  array<int, int>  $candidatos
     * @return array<int, int>
     */
    private function cargaNoEvento(Evento $evento, array $candidatos): array
    {
        return DB::table('pagamentos')
            ->join('inscricoes', 'inscricoes.id', '=', 'pagamentos.inscricao_id')
            ->whereIn('pagamentos.responsavel_id', $candidatos)
            ->where('inscricoes.evento_id', $evento->getKey())
            ->whereIn('inscricoes.situacao', SituacaoInscricao::valoresAtivos())
            ->groupBy('pagamentos.responsavel_id')
            ->selectRaw('pagamentos.responsavel_id, COUNT(DISTINCT inscricoes.id) AS carga')
            ->pluck('carga', 'pagamentos.responsavel_id')
            ->mapWithKeys(fn (mixed $carga, mixed $id): array => [(int) $id => (int) $carga])
            ->all();
    }
}
