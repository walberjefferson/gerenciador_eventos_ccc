<?php

declare(strict_types=1);

namespace App\DTOs\Payments;

use Illuminate\Support\Carbon;

/**
 * The current state of a charge, as reported by the gateway itself.
 */
final readonly class PaymentStatusResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalId,
        public string $status,
        public int $amountCents,
        public ?Carbon $paidAt = null,
        public ?int $refundedAmountCents = null,
        public array $raw = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
