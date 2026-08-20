<?php

declare(strict_types=1);

namespace App\DTOs\Payments;

use Illuminate\Support\Carbon;

/**
 * A webhook payload translated into neutral terms. It only DESCRIBES what the
 * gateway says happened; changing the domain is the application's job.
 */
final readonly class WebhookResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $eventId,
        public ?string $eventType,
        public ?string $externalId,
        public ?string $status,
        public ?int $amountCents = null,
        public ?Carbon $occurredAt = null,
        public array $raw = [],
    ) {}

    /**
     * A webhook we can act upon: it points at a charge and carries a status we
     * understand.
     */
    public function isActionable(): bool
    {
        return $this->externalId !== null && $this->status !== null;
    }
}
