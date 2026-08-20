<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Pagamentos\CancelarPagamento;
use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\WebhookRequestData;
use App\Enums\SituacaoPagamento;
use App\Enums\SituacaoWebhook;
use App\Models\Pagamento;
use App\Models\WebhookPagamento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Interpreta, em segundo plano, um aviso ja guardado do provedor de pagamento.
 *
 * Idempotente em tres camadas: so trabalha em aviso que ainda esta "recebido";
 * cada mudanca de situacao exige a situacao anterior; e o mesmo identificador
 * de aviso nao entra duas vezes no banco. Resultado: o provedor pode reenviar
 * o mesmo aviso quantas vezes quiser que a inscricao e confirmada uma vez so e
 * os contadores de vaga nao contam em dobro.
 */
class ProcessarWebhookPagamento implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $webhookId) {}

    public function handle(
        PaymentGateway $gateway,
        ConfirmarPagamento $confirmarPagamento,
        CancelarPagamento $cancelarPagamento,
    ): void {
        $webhook = WebhookPagamento::query()->find($this->webhookId);

        if ($webhook === null || ! $webhook->estaPendenteDeProcessamento()) {
            return;
        }

        try {
            $resultado = $gateway->parseWebhook(
                WebhookRequestData::fromPayload((array) $webhook->payload)
            );

            if (! $resultado->isActionable()) {
                $this->encerrar($webhook, SituacaoWebhook::Ignorado, 'Aviso sem cobranca ou sem situacao reconhecivel.');

                return;
            }

            $pagamento = Pagamento::query()
                ->where('gateway', $webhook->gateway)
                ->where('id_externo', $resultado->externalId)
                ->first();

            if ($pagamento === null) {
                $this->encerrar($webhook, SituacaoWebhook::Ignorado, 'Cobranca desconhecida neste sistema.');

                return;
            }

            $situacao = SituacaoPagamento::deStatusExterno((string) $resultado->status);

            if ($situacao === null || $situacao === SituacaoPagamento::Pendente) {
                $this->encerrar($webhook, SituacaoWebhook::Ignorado, 'Aviso sem mudanca a aplicar.');

                return;
            }

            match ($situacao) {
                SituacaoPagamento::Pago => $confirmarPagamento($pagamento, $resultado->occurredAt),
                SituacaoPagamento::Estornado => $this->estornar($pagamento, $resultado->amountCents),
                default => $cancelarPagamento($pagamento, $situacao, avisarProvedor: false),
            };

            $this->encerrar($webhook, SituacaoWebhook::Processado);
        } catch (Throwable $erro) {
            $this->encerrar($webhook, SituacaoWebhook::Falhou, $erro->getMessage());

            throw $erro;
        }
    }

    /**
     * Estorno so faz sentido sobre cobranca paga. A condicao na consulta e o
     * que impede aplicar duas vezes.
     */
    private function estornar(Pagamento $pagamento, ?int $valorCentavos): void
    {
        $momento = Carbon::now();

        Pagamento::query()
            ->whereKey($pagamento->getKey())
            ->where('situacao', SituacaoPagamento::Pago->value)
            ->update([
                'situacao' => SituacaoPagamento::Estornado->value,
                'estornado_em' => $momento,
                'valor_estornado_centavos' => $valorCentavos ?? $pagamento->valor_centavos,
                'updated_at' => $momento,
            ]);
    }

    private function encerrar(WebhookPagamento $webhook, SituacaoWebhook $situacao, ?string $erro = null): void
    {
        $webhook->forceFill([
            'situacao' => $situacao,
            'processado_em' => Carbon::now(),
            'erro' => $erro,
        ])->save();
    }
}
