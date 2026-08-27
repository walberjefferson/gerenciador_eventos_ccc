<?php

declare(strict_types=1);

namespace App\DTOs\Payments;

use Illuminate\Http\Request;

/**
 * An inbound webhook exactly as it arrived: raw body, decoded payload and the
 * signature header. The raw body matters — signatures are computed over the
 * bytes that were sent, not over a re-encoded array.
 */
final readonly class WebhookRequestData
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $rawBody,
        public array $payload = [],
        public array $headers = [],
        public ?string $signature = null,
    ) {}

    public static function fromRequest(Request $request, string $signatureHeader): self
    {
        $rawBody = $request->getContent();
        $decoded = json_decode($rawBody, true);

        return new self(
            rawBody: $rawBody,
            payload: is_array($decoded) ? $decoded : [],
            headers: [$signatureHeader => (string) $request->header($signatureHeader, '')],
            signature: $request->header($signatureHeader),
        );
    }

    /**
     * Some providers do not send a signature header at all: they hand back a
     * value that WE registered inside the webhook URL itself, and it arrives
     * as a query string parameter on every notification. The raw body is still
     * kept byte for byte — it is what any other provider signs.
     */
    public static function fromRequestQuery(Request $request, string $signatureParameter): self
    {
        $rawBody = $request->getContent();
        $decoded = json_decode($rawBody, true);
        $signature = $request->query($signatureParameter);

        return new self(
            rawBody: $rawBody,
            payload: is_array($decoded) ? $decoded : [],
            signature: is_string($signature) ? $signature : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, ?string $signature = null): self
    {
        return new self(
            rawBody: (string) json_encode($payload),
            payload: $payload,
            signature: $signature,
        );
    }
}
