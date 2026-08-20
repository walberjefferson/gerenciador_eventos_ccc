<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\WebhookRequestData;
use App\Enums\SituacaoWebhook;
use App\Jobs\ProcessarWebhookPagamento;
use App\Models\WebhookPagamento;
use App\Services\Payments\Fake\FakePaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Porta de entrada dos avisos automaticos do provedor de pagamento.
 *
 * A ordem importa: conferir a assinatura, guardar o aviso cru e responder 200
 * imediatamente. Provedores desistem em poucos segundos e reenviam o aviso; se
 * o processamento acontecesse aqui, uma consulta lenta viraria aviso repetido.
 * Quem interpreta e o job, em segundo plano.
 */
class PaymentWebhookController
{
    public function __invoke(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $dados = WebhookRequestData::fromRequest($request, FakePaymentGateway::SIGNATURE_HEADER);

        $assinaturaValida = $gateway->verifyWebhookSignature($dados);
        $resultado = $gateway->parseWebhook($dados);
        $agora = Carbon::now();

        if (! $assinaturaValida) {
            // Guardamos mesmo assim: tentativa de aviso forjado e informacao de
            // seguranca. Mas ela morre aqui, sem virar job.
            WebhookPagamento::create([
                'gateway' => $gateway->name(),
                'id_evento_externo' => null,
                'tipo_evento' => $resultado->eventType,
                'payload' => $this->semDadoSensivel($dados->payload),
                'assinatura_valida' => false,
                'recebido_em' => $agora,
                'situacao' => SituacaoWebhook::Ignorado,
                'erro' => 'Assinatura invalida.',
            ]);

            // Decisao D-18: a resposta e 200, igual a de um aviso legitimo.
            // Responder 401 diria a quem tenta forjar avisos que ele acertou o
            // endereco e errou so a assinatura. O aviso fica gravado como
            // invalido e nao produz efeito nenhum no dominio.
            return response()->json(['recebido' => true]);
        }

        // O mesmo aviso pode chegar varias vezes. A unicidade parcial no banco
        // e a fonte da verdade; a consulta abaixo apenas evita o caso comum.
        if ($resultado->eventId !== null && $this->jaRecebido($gateway->name(), $resultado->eventId)) {
            return response()->json(['recebido' => true, 'repetido' => true]);
        }

        try {
            $webhook = WebhookPagamento::create([
                'gateway' => $gateway->name(),
                'id_evento_externo' => $resultado->eventId,
                'tipo_evento' => $resultado->eventType,
                'payload' => $this->semDadoSensivel($dados->payload),
                'assinatura_valida' => true,
                'recebido_em' => $agora,
                'situacao' => SituacaoWebhook::Recebido,
            ]);
        } catch (QueryException $excecao) {
            if (str_contains($excecao->getMessage(), 'webhooks_pagamento_evento_externo_unique')) {
                return response()->json(['recebido' => true, 'repetido' => true]);
            }

            throw $excecao;
        }

        ProcessarWebhookPagamento::dispatch($webhook->id);

        return response()->json(['recebido' => true]);
    }

    private function jaRecebido(string $gateway, string $eventId): bool
    {
        return WebhookPagamento::query()
            ->where('gateway', $gateway)
            ->where('id_evento_externo', $eventId)
            ->exists();
    }

    /**
     * Remove do que sera guardado qualquer campo que costume carregar segredo
     * ou dado de cartao. Guardamos o aviso para investigar, nao para colecionar
     * dado sensivel.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function semDadoSensivel(array $payload): array
    {
        $proibidos = [
            'secret', 'token', 'authorization', 'password', 'api_key',
            'card', 'card_number', 'cvv', 'cvc', 'holder_document',
        ];

        foreach ($payload as $chave => $valor) {
            if (in_array(mb_strtolower((string) $chave), $proibidos, true)) {
                $payload[$chave] = '[removido]';

                continue;
            }

            if (is_array($valor)) {
                $payload[$chave] = $this->semDadoSensivel($valor);
            }
        }

        return $payload;
    }
}
