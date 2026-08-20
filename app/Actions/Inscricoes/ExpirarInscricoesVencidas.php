<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\Actions\Pagamentos\CancelarPagamento;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Events\InscricaoExpirada;
use App\Models\Evento;
use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marca como expiradas as inscricoes que nao foram pagas dentro do prazo e
 * devolve as vagas que elas prendiam.
 *
 * Usada em dois momentos:
 * - pela rotina agendada, de minuto em minuto;
 * - pela criacao de inscricao, quando o contador diz "lotado": antes de
 *   recusar alguem, varremos as reservas vencidas daquele evento, porque a
 *   vaga pode estar presa por quem ja perdeu o prazo.
 *
 * E idempotente: rodar duas vezes seguidas nao muda nada na segunda, porque
 * so muda quem ainda esta aguardando pagamento.
 *
 * Nenhum registro e apagado. A inscricao continua no banco, com a situacao e o
 * momento da expiracao gravados.
 */
class ExpirarInscricoesVencidas
{
    public function __construct(
        private readonly LiberarVagas $liberarVagas,
        private readonly CancelarPagamento $cancelarPagamento,
    ) {}

    /**
     * @param  Evento|null  $evento  limita a varredura a um evento (uso sob demanda)
     * @return int quantas inscricoes foram expiradas nesta execucao
     */
    public function __invoke(?Evento $evento = null, ?Carbon $momento = null): int
    {
        $momento ??= Carbon::now();
        $expiradas = 0;

        Inscricao::query()
            ->vencidas($momento)
            ->when($evento !== null, fn ($consulta) => $consulta->where('evento_id', $evento?->id))
            ->chunkById(100, function (Collection $inscricoes) use (&$expiradas, $momento): void {
                foreach ($inscricoes as $inscricao) {
                    $expiradas += $this->expirar($inscricao, $momento);
                }
            });

        return $expiradas;
    }

    /**
     * @return int 1 se esta execucao foi quem expirou a inscricao, 0 caso contrario
     */
    private function expirar(Inscricao $inscricao, Carbon $momento): int
    {
        $expirou = DB::transaction(function () use ($inscricao, $momento): bool {
            // A condicao "ainda aguardando pagamento" faz o papel de trava: se
            // outro processo confirmou ou expirou esta inscricao no meio do
            // caminho, nenhuma linha muda e nada e devolvido em dobro.
            $linhas = Inscricao::query()
                ->whereKey($inscricao->getKey())
                ->where('situacao', SituacaoInscricao::AguardandoPagamento->value)
                ->update([
                    'situacao' => SituacaoInscricao::Expirada->value,
                    'expirada_em' => $momento,
                ]);

            if ($linhas === 0) {
                return false;
            }

            $this->liberarVagas->liberarReserva($inscricao);

            $this->encerrarCobrancas($inscricao);

            return true;
        });

        if (! $expirou) {
            return 0;
        }

        $inscricao->refresh();

        // O anuncio sai depois do commit: ninguem deve ser avisado de uma
        // expiracao que o banco ainda pode desfazer.
        InscricaoExpirada::dispatch($inscricao);

        return 1;
    }

    /**
     * Fecha a porta do dinheiro: nenhuma cobranca pode continuar aceitando Pix
     * de uma vaga que ja voltou para a fila.
     *
     * A Action de cancelamento exige situacao "pendente", entao rodar de novo
     * simplesmente nao encontra nada para fechar.
     */
    private function encerrarCobrancas(Inscricao $inscricao): void
    {
        Pagamento::query()
            ->where('inscricao_id', $inscricao->getKey())
            ->pendentes()
            ->get()
            ->each(function (Pagamento $pagamento): void {
                ($this->cancelarPagamento)($pagamento, SituacaoPagamento::Expirado);
            });
    }
}
