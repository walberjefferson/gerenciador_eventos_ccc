<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Actions\Inscricoes\LiberarVagas;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Events\InscricaoConfirmada;
use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconhece o dinheiro: marca a cobranca como paga e confirma a inscricao.
 *
 * E o unico caminho pelo qual uma inscricao vira "confirmada". Ele nunca e
 * acionado por parametro vindo do navegador — apenas por aviso assinado do
 * provedor ou por consulta direta ao provedor (reconciliacao).
 *
 * E idempotente por construcao: cada mudanca acontece em um comando que exige
 * a situacao anterior. O mesmo aviso chegando duas vezes muda tudo na primeira
 * e nada na segunda, entao os contadores de vaga nunca contam em dobro.
 */
class ConfirmarPagamento
{
    public function __construct(private readonly LiberarVagas $liberarVagas) {}

    /**
     * @return bool true se esta chamada foi quem confirmou; false se ja estava
     *              confirmado antes
     */
    public function __invoke(Pagamento $pagamento, ?Carbon $pagoEm = null): bool
    {
        $momento = $pagoEm ?? Carbon::now();

        $inscricaoConfirmada = null;

        $reconheceu = DB::transaction(function () use ($pagamento, $momento, &$inscricaoConfirmada): bool {
            $linhas = Pagamento::query()
                ->whereKey($pagamento->getKey())
                ->where('situacao', SituacaoPagamento::Pendente->value)
                ->update([
                    'situacao' => SituacaoPagamento::Pago->value,
                    'pago_em' => $momento,
                    'updated_at' => Carbon::now(),
                ]);

            if ($linhas === 0) {
                return false;
            }

            $inscricao = Inscricao::query()->find($pagamento->inscricao_id);

            if ($inscricao === null) {
                return true;
            }

            $confirmou = Inscricao::query()
                ->whereKey($inscricao->getKey())
                ->where('situacao', SituacaoInscricao::AguardandoPagamento->value)
                ->update([
                    'situacao' => SituacaoInscricao::Confirmada->value,
                    'confirmada_em' => $momento,
                    'updated_at' => Carbon::now(),
                ]);

            if ($confirmou === 1) {
                // A vaga presa vira vaga paga: o total ocupado nao muda.
                $this->liberarVagas->confirmar($inscricao);

                $inscricaoConfirmada = $inscricao;
            }

            return true;
        });

        // O anuncio sai fora da transacao, e so na chamada que de fato mudou a
        // situacao: aviso repetido do provedor nao dispara evento de novo.
        if ($inscricaoConfirmada instanceof Inscricao) {
            InscricaoConfirmada::dispatch($inscricaoConfirmada->refresh(), $pagamento->refresh());
        }

        return $reconheceu;
    }
}
