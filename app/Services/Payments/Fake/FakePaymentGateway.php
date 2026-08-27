<?php

declare(strict_types=1);

namespace App\Services\Payments\Fake;

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\CreatePaymentData;
use App\DTOs\Payments\PaymentResult;
use App\DTOs\Payments\PaymentStatusResult;
use App\DTOs\Payments\RefundResult;
use App\DTOs\Payments\WebhookRequestData;
use App\DTOs\Payments\WebhookResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provedor de pagamento simulado.
 *
 * Faz tudo o que uma instituicao financeira faria — emitir a cobranca Pix,
 * receber o pagamento, deixar vencer, falhar, estornar e avisar por webhook —
 * sem mover um centavo e sem exigir credencial de ninguem.
 *
 * O estado de cada cobranca fica em arquivo no disco local (storage/app), e
 * nao em memoria, porque a simulacao acontece em uma requisicao e a consulta
 * acontece em outra. Disco tambem evita depender de Redis para desenvolver.
 *
 * Nenhuma regra de inscricao mora aqui: este arquivo so conhece cobrancas.
 */
class FakePaymentGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Fake-Signature';

    private const DIRECTORY = 'pagamentos-simulados';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly Filesystem $disk,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createPayment(CreatePaymentData $data): PaymentResult
    {
        $externalId = 'fake_'.Str::lower((string) Str::ulid());

        $charge = [
            'external_id' => $externalId,
            'external_reference' => $data->externalReference,
            'status' => 'pending',
            'method' => $data->method,
            'amount_cents' => $data->amountCents,
            'currency' => $data->currency,
            'description' => $data->description,
            'pix_payload' => $this->pixPayload($externalId, $data->amountCents),
            'expires_at' => $data->expiresAt?->toIso8601String(),
            'paid_at' => null,
            'refunded_amount_cents' => null,
            'created_at' => Carbon::now()->toIso8601String(),
        ];

        $this->write($charge);

        return new PaymentResult(
            externalId: $externalId,
            status: 'pending',
            amountCents: $data->amountCents,
            pixPayload: $charge['pix_payload'],
            expiresAt: $data->expiresAt,
            raw: $charge,
        );
    }

    public function getPayment(string $externalId): PaymentStatusResult
    {
        $charge = $this->read($externalId);

        return new PaymentStatusResult(
            externalId: $externalId,
            status: (string) $charge['status'],
            amountCents: (int) $charge['amount_cents'],
            paidAt: $charge['paid_at'] !== null ? Carbon::parse($charge['paid_at']) : null,
            refundedAmountCents: $charge['refunded_amount_cents'] !== null
                ? (int) $charge['refunded_amount_cents']
                : null,
            raw: $charge,
        );
    }

    public function cancelPayment(string $externalId): void
    {
        $charge = $this->read($externalId);

        if ($charge['status'] === 'pending') {
            $charge['status'] = 'canceled';
            $this->write($charge);
        }
    }

    public function refundPayment(string $externalId, ?int $amountCents = null): RefundResult
    {
        $charge = $this->read($externalId);

        if ($charge['status'] !== 'paid') {
            throw new RuntimeException('So e possivel estornar uma cobranca paga.');
        }

        $valor = $amountCents ?? (int) $charge['amount_cents'];

        if ($valor <= 0 || $valor > (int) $charge['amount_cents']) {
            throw new RuntimeException('Valor de estorno fora do valor da cobranca.');
        }

        $charge['status'] = 'refunded';
        $charge['refunded_amount_cents'] = $valor;
        $charge['refunded_at'] = Carbon::now()->toIso8601String();

        $this->write($charge);

        return new RefundResult(
            externalId: $externalId,
            status: 'refunded',
            refundedAmountCents: $valor,
            refundedAt: Carbon::parse($charge['refunded_at']),
            raw: $charge,
        );
    }

    /**
     * A assinatura do provedor simulado viaja em cabecalho — e e ele quem
     * sabe disso, nao o controller que recebe o aviso.
     */
    public function webhookRequest(Request $request): WebhookRequestData
    {
        return WebhookRequestData::fromRequest($request, self::SIGNATURE_HEADER);
    }

    public function verifyWebhookSignature(WebhookRequestData $request): bool
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        // Sem segredo configurado nao ha como distinguir um aviso legitimo de
        // um forjado. Nesse caso, recusa: a falha e sempre para o lado seguro.
        if ($secret === '' || $request->signature === null) {
            return false;
        }

        return hash_equals($this->sign($request->rawBody), $request->signature);
    }

    /**
     * O provedor simulado avisa um pagamento por vez. A lista tem sempre um
     * item — o que muda e a forma, para caber no contrato de quem manda
     * varios de uma vez.
     *
     * @return list<WebhookResult>
     */
    public function parseWebhook(WebhookRequestData $request): array
    {
        $payload = $request->payload;

        return [new WebhookResult(
            eventId: isset($payload['id']) ? (string) $payload['id'] : null,
            eventType: isset($payload['type']) ? (string) $payload['type'] : null,
            externalId: isset($payload['data']['payment_id']) ? (string) $payload['data']['payment_id'] : null,
            status: isset($payload['data']['status']) ? (string) $payload['data']['status'] : null,
            amountCents: isset($payload['data']['amount_cents']) ? (int) $payload['data']['amount_cents'] : null,
            occurredAt: isset($payload['occurred_at']) ? Carbon::parse((string) $payload['occurred_at']) : null,
            raw: $payload,
        )];
    }

    // ------------------------------------------------------------------
    // Simulacao — usado apenas por routes/dev.php e pelos testes.
    // ------------------------------------------------------------------

    public function simulatePayment(string $externalId): PaymentStatusResult
    {
        return $this->transitionTo($externalId, 'paid');
    }

    public function simulateExpiration(string $externalId): PaymentStatusResult
    {
        return $this->transitionTo($externalId, 'expired');
    }

    public function simulateFailure(string $externalId): PaymentStatusResult
    {
        return $this->transitionTo($externalId, 'failed');
    }

    /**
     * Monta o aviso que uma instituicao real enviaria, ja assinado.
     */
    public function emitWebhook(string $externalId, string $status, ?string $eventId = null): WebhookRequestData
    {
        $charge = $this->read($externalId);

        $payload = [
            'id' => $eventId ?? 'evt_'.Str::lower((string) Str::ulid()),
            'type' => 'payment.'.$status,
            'occurred_at' => Carbon::now()->toIso8601String(),
            'data' => [
                'payment_id' => $externalId,
                'status' => $status,
                'amount_cents' => (int) $charge['amount_cents'],
                'external_reference' => $charge['external_reference'],
            ],
        ];

        $rawBody = (string) json_encode($payload);

        return new WebhookRequestData(
            rawBody: $rawBody,
            payload: $payload,
            headers: [self::SIGNATURE_HEADER => $this->sign($rawBody)],
            signature: $this->sign($rawBody),
        );
    }

    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) ($this->config['webhook_secret'] ?? ''));
    }

    // ------------------------------------------------------------------

    private function transitionTo(string $externalId, string $status): PaymentStatusResult
    {
        $charge = $this->read($externalId);

        $charge['status'] = $status;
        $charge['paid_at'] = $status === 'paid' ? Carbon::now()->toIso8601String() : $charge['paid_at'];

        $this->write($charge);

        return $this->getPayment($externalId);
    }

    /**
     * Monta um "Pix copia e cola" ficticio no formato EMV, o mesmo formato que
     * os bancos usam. E ficticio de proposito: a chave e o comerciante saem da
     * configuracao do provedor simulado e nao correspondem a conta nenhuma.
     */
    private function pixPayload(string $externalId, int $amountCents): string
    {
        $chave = (string) ($this->config['pix_key'] ?? 'chave-pix-ficticia@example.com');
        $nome = $this->emv26((string) ($this->config['merchant_name'] ?? 'EVENTOS DEMO'), 25);
        $cidade = $this->emv26((string) ($this->config['merchant_city'] ?? 'SAO PAULO'), 15);
        $identificador = $this->emv26(Str::upper(Str::substr($externalId, -20)), 25);

        $conta = $this->campo('00', 'br.gov.bcb.pix').$this->campo('01', $chave);

        $payload = $this->campo('00', '01')
            .$this->campo('26', $conta)
            .$this->campo('52', '0000')
            .$this->campo('53', '986')
            .$this->campo('54', number_format($amountCents / 100, 2, '.', ''))
            .$this->campo('58', 'BR')
            .$this->campo('59', $nome)
            .$this->campo('60', $cidade)
            .$this->campo('62', $this->campo('05', $identificador))
            .'6304';

        return $payload.$this->crc16($payload);
    }

    private function campo(string $id, string $valor): string
    {
        return $id.str_pad((string) mb_strlen($valor), 2, '0', STR_PAD_LEFT).$valor;
    }

    /**
     * O formato EMV so aceita letras sem acento, numeros e espaco.
     */
    private function emv26(string $texto, int $limite): string
    {
        $limpo = preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($texto)) ?? '';

        return Str::upper(Str::substr(trim($limpo), 0, $limite));
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($payload); $i++) {
            $crc ^= ord($payload[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return Str::upper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $externalId): array
    {
        $caminho = $this->path($externalId);

        if (! $this->disk->exists($caminho)) {
            throw new RuntimeException("Cobranca simulada nao encontrada: {$externalId}");
        }

        $conteudo = json_decode((string) $this->disk->get($caminho), true);

        if (! is_array($conteudo)) {
            throw new RuntimeException("Cobranca simulada ilegivel: {$externalId}");
        }

        return $conteudo;
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function write(array $charge): void
    {
        $this->disk->put(
            $this->path((string) $charge['external_id']),
            (string) json_encode($charge, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function path(string $externalId): string
    {
        // O identificador e gerado aqui e so tem letras, numeros e "_"; ainda
        // assim, filtramos qualquer outra coisa antes de virar nome de arquivo.
        $seguro = preg_replace('/[^A-Za-z0-9_\-]/', '', $externalId) ?? '';

        return self::DIRECTORY.'/'.$seguro.'.json';
    }
}
