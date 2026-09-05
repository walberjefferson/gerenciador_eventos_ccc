<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\Models\Evento;
use App\Models\Lote;
use Illuminate\Support\Carbon;

/**
 * Qual e o lote que vale agora.
 *
 * E o primeiro lote do evento, em ordem de posicao, que ainda esta disponivel:
 * dentro do prazo e com vaga. Ha no maximo um; pode nao haver nenhum, quando
 * todos venceram ou esgotaram (RN-L9).
 *
 * ESTE E O UNICO LUGAR ONDE A REGRA DO LOTE VIGENTE MORA (RN-L3). A Action que
 * cria a inscricao, o Resource que monta a tela e os testes perguntam todos
 * aqui. Uma segunda copia da regra — num controller, num Blade, num computed do
 * Vue — discordaria desta no dia em que uma das duas mudasse, e o sistema
 * passaria a mostrar um preco e cobrar outro.
 *
 * A situacao nao e gravada em coluna nenhuma: ela e derivada da data e do
 * contador a cada leitura, e por isso e sempre verdadeira.
 */
class ResolverLoteVigente
{
    /**
     * O lote vigente do evento, lido do banco.
     *
     * Consulta sempre, mesmo que a relacao esteja carregada: quem chama daqui e
     * quem precisa do dado FRESCO — a criacao da inscricao, dentro da transacao.
     * Quem ja carregou os lotes para montar uma tela usa daColecao().
     */
    public function __invoke(Evento $evento, ?Carbon $momento = null): ?Lote
    {
        return $this->daColecao($evento->lotes()->emOrdem()->get(), $momento);
    }

    /**
     * O mesmo criterio, sobre lotes que quem chamou ja tem em maos.
     *
     * A colecao precisa vir em ordem de posicao — e como a relacao Evento::lotes
     * a entrega.
     *
     * @param  iterable<int, Lote>  $lotes
     */
    public function daColecao(iterable $lotes, ?Carbon $momento = null): ?Lote
    {
        $momento ??= Carbon::now();

        foreach ($lotes as $lote) {
            if ($lote->estaDisponivel($momento)) {
                return $lote;
            }
        }

        return null;
    }
}
