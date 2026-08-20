<?php

declare(strict_types=1);

namespace App\DTOs\Payments;

use Illuminate\Support\Carbon;

final readonly class RefundResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalId,
        public string $status,
        public int $refundedAmountCents,
        public ?Carbon $refundedAt = null,
        public array $raw = [],
    ) {}
}
