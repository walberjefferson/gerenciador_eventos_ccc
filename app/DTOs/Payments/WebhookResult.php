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
     * `$payer` is deliberately NOT part of `$raw`.
     *
     * `$raw` is what gets STORED — it is the webhook as the provider sent it,
     * and it is scrubbed of sensitive data on the way in. `$payer` is a
     * READING of that same webhook, produced before the scrubbing runs, so
     * anything copied into it would be stored unscrubbed. Keeping it in its
     * own field means the payer's document is only ever persisted through the
     * scrubbed payload — never twice, never in the clear.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $payer
     */
    public function __construct(
        public ?string $eventId,
        public ?string $eventType,
        public ?string $externalId,
        public ?string $status,
        public ?int $amountCents = null,
        public ?Carbon $occurredAt = null,
        public array $raw = [],
        public array $payer = [],
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
