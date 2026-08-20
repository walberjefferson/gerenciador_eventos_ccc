<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

use App\DTOs\Payments\CreatePaymentData;
use App\DTOs\Payments\PaymentResult;
use App\DTOs\Payments\PaymentStatusResult;
use App\DTOs\Payments\RefundResult;
use App\DTOs\Payments\WebhookRequestData;
use App\DTOs\Payments\WebhookResult;

/**
 * Fronteira com o provedor de pagamento.
 *
 * Este contrato e os seus DTOs ficam em ingles de proposito: eles espelham a
 * API de quem esta do outro lado. Da fronteira para dentro, tudo e portugues.
 *
 * Nenhum Model do Eloquent atravessa esta fronteira — apenas DTOs somente
 * leitura. E "parseWebhook" apenas TRADUZ o aviso recebido: quem muda o estado
 * do dominio e a Action da aplicacao, nunca o provedor.
 */
interface PaymentGateway
{
    /**
     * Nome curto do provedor, gravado em pagamentos.gateway.
     */
    public function name(): string;

    public function createPayment(CreatePaymentData $data): PaymentResult;

    public function getPayment(string $externalId): PaymentStatusResult;

    public function cancelPayment(string $externalId): void;

    public function refundPayment(string $externalId, ?int $amountCents = null): RefundResult;

    public function verifyWebhookSignature(WebhookRequestData $request): bool;

    public function parseWebhook(WebhookRequestData $request): WebhookResult;
}
