<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Pagamentos\CancelarPagamento;
use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\WebhookRequestData;
use App\DTOs\Payments\WebhookResult;
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
            // O provedor traduz uma lista de eventos, porque um aviso pode
            // trazer varios pagamentos. Quem desdobrou a lista foi o
            // controller: cada registro guarda o recorte de UM evento, e e
            // esse unico evento que chega aqui.
            $resultado = $gateway->parseWebhook(
                WebhookRequestData::fromPayload((array) $webhook->payload)
            )[0] ?? null;

            if ($resultado === null || ! $resultado->isActionable()) {
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

            $this->guardarIdentificadorDaTransferencia($pagamento, $resultado);
            $this->guardarPagador($pagamento, $resultado);

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
     * Guarda o identificador que o sistema de pagamentos instantaneos da a
     * transferencia, quando o aviso trouxer um.
     *
     * Ele nao serve para nada hoje, e e exatamente por isso que precisa ser
     * guardado hoje: uma devolucao, no dia em que houver, e pedida por esse
     * identificador e nao pelo da cobranca. Ele chega uma unica vez, no aviso.
     * Se nao for gravado agora, some — e reencontra-lo depois significa varrer
     * a API do provedor pagamento a pagamento.
     *
     * A chave vive em metadados, que ja e uma coluna jsonb: nao ha coluna nova
     * nem migracao para uma informacao que ainda nao tem consulta.
     */
    private function guardarIdentificadorDaTransferencia(Pagamento $pagamento, WebhookResult $resultado): void
    {
        $identificador = $resultado->raw['end_to_end_id'] ?? null;

        if (! is_string($identificador) || $identificador === '') {
            return;
        }

        $metadados = (array) ($pagamento->metadados ?? []);

        if (($metadados['end_to_end_id'] ?? null) === $identificador) {
            return;
        }

        $metadados['end_to_end_id'] = $identificador;

        $pagamento->forceFill(['metadados' => $metadados])->save();
    }

    /**
     * Guarda quem pagou, quando o aviso disser.
     *
     * Serve para conferir: o extrato do banco traz um nome, e e este campo que
     * responde "esta cobranca foi paga por quem?" sem ninguem precisar abrir o
     * painel do provedor. O caso comum e o pagamento feito por outra pessoa —
     * a mae que paga a inscricao do filho, a igreja que paga a do grupo —, em
     * que o nome do pagador nao e o do participante e a diferenca so aparece
     * aqui.
     *
     * Vive em metadados pela mesma razao que o identificador da transferencia:
     * ja e uma coluna jsonb, e nao ha consulta que peca coluna propria.
     *
     * O documento chega ja mascarado quando e CPF — quem mascara e a porta de
     * entrada do aviso, antes de o payload virar linha.
     */
    private function guardarPagador(Pagamento $pagamento, WebhookResult $resultado): void
    {
        $pagador = $resultado->payer;

        if ($pagador === []) {
            return;
        }

        $metadados = (array) ($pagamento->metadados ?? []);

        if (($metadados['pagador'] ?? null) === $pagador) {
            return;
        }

        $metadados['pagador'] = $pagador;

        $pagamento->forceFill(['metadados' => $metadados])->save();
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
