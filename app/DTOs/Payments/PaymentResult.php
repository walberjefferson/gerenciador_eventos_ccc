<?php

declare(strict_types=1);

namespace App\DTOs\Payments;

use Illuminate\Support\Carbon;

/**
 * A charge that has just been opened at the gateway.
 *
 * "status" uses the neutral vocabulary of the boundary (pending, paid, failed,
 * expired, canceled, refunded) and is translated by SituacaoPagamento.
 */
final readonly class PaymentResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalId,
        public string $status,
        public int $amountCents,
        public ?string $pixPayload = null,
        public ?Carbon $expiresAt = null,
        public array $raw = [],
    ) {}
}
