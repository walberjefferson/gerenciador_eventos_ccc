<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\SituacaoPagamento;
use App\Models\Pagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Encerra uma cobranca que nao sera mais paga — porque a inscricao expirou ou
 * foi cancelada.
 *
 * Nao mexe em contador de vaga: quem devolve vaga e a transicao da inscricao.
 * Aqui so se fecha a porta do dinheiro, para que ninguem pague um Pix de uma
 * vaga que ja voltou para a fila.
 *
 * O pagamento nao e apagado: ele passa a "cancelado" (ou "expirado"), com o
 * momento gravado.
 */
class CancelarPagamento
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @return bool true se esta chamada foi quem encerrou a cobranca
     */
    public function __invoke(
        Pagamento $pagamento,
        SituacaoPagamento $destino = SituacaoPagamento::Cancelado,
        bool $avisarProvedor = true,
    ): bool {
        $momento = Carbon::now();

        $atributos = [
            'situacao' => $destino->value,
            'updated_at' => $momento,
        ];

        if ($destino === SituacaoPagamento::Cancelado) {
            $atributos['cancelado_em'] = $momento;
        }

        $linhas = Pagamento::query()
            ->whereKey($pagamento->getKey())
            ->where('situacao', SituacaoPagamento::Pendente->value)
            ->update($atributos);

        if ($linhas === 0) {
            return false;
        }

        if ($avisarProvedor && $pagamento->id_externo !== null) {
            try {
                $this->gateway->cancelPayment($pagamento->id_externo);
            } catch (Throwable $erro) {
                // O provedor pode estar fora do ar. A cobranca ja esta fechada
                // aqui, e a rotina de reconciliacao volta a olhar para ela.
                // Registramos apenas o codigo publico: nada de segredo em log.
                Log::warning('Nao foi possivel avisar o provedor sobre o cancelamento da cobranca.', [
                    'pagamento' => $pagamento->codigo_publico,
                    'motivo' => $erro->getMessage(),
                ]);
            }
        }

        return true;
    }
}
