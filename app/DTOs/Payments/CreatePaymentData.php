<?php

declare(strict_types=1);

namespace App\DTOs\Payments;

use Illuminate\Support\Carbon;

/**
 * Everything the gateway needs to open a charge. Amounts are integer cents.
 */
final readonly class CreatePaymentData
{
    /**
     * @param  string  $externalReference  our own public code for the registration
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $externalReference,
        public int $amountCents,
        public string $currency,
        public string $method,
        public string $description,
        public string $payerName,
        public string $payerEmail,
        public string $payerDocument,
        public ?Carbon $expiresAt = null,
        public array $metadata = [],
    ) {}
}
