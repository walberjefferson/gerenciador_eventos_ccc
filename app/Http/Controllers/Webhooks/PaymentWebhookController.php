<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\WebhookResult;
use App\Enums\SituacaoWebhook;
use App\Jobs\ProcessarWebhookPagamento;
use App\Models\WebhookPagamento;
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
 *
 * Este controller nao sabe o nome de nenhum fornecedor e nao sabe onde mora a
 * assinatura dele — cabecalho, parametro de URL ou qualquer outra coisa. Quem
 * sabe e o proprio provedor, atras do contrato.
 *
 * Um aviso pode trazer VARIOS pagamentos de uma vez: o aviso de Pix recebido e
 * uma lista. Cada item vira um registro proprio, com o seu recorte do aviso, e
 * um job proprio. Guardar so o primeiro perderia dinheiro em silencio.
 */
class PaymentWebhookController
{
    public function __invoke(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $dados = $gateway->webhookRequest($request);

        $assinaturaValida = $gateway->verifyWebhookSignature($dados);
        $resultados = $gateway->parseWebhook($dados);
        $agora = Carbon::now();

        if (! $assinaturaValida) {
            // Guardamos mesmo assim: tentativa de aviso forjado e informacao de
            // seguranca. Mas ela morre aqui, sem virar job.
            WebhookPagamento::create([
                'gateway' => $gateway->name(),
                'id_evento_externo' => null,
                'tipo_evento' => $resultados[0]->eventType ?? null,
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

        $guardados = 0;
        $repetidos = 0;

        foreach ($resultados as $resultado) {
            // Qualquer falha nossa daqui para baixo — banco fora do ar,
            // restricao inesperada — sobe e vira 5xx de proposito. Provedor
            // serio reentrega o aviso por horas; responder 200 sobre um erro
            // nosso jogaria fora a unica chance de receber o aviso de novo.
            $this->guardar($gateway->name(), $resultado, $dados->payload, $agora)
                ? $guardados++
                : $repetidos++;
        }

        return response()->json(array_filter([
            'recebido' => true,
            'repetido' => $guardados === 0 && $repetidos > 0,
        ]));
    }

    /**
     * Guarda um evento do aviso e agenda o processamento dele.
     *
     * Devolve false quando o evento ja tinha sido recebido antes — reenvio e
     * comportamento normal de provedor, nao erro.
     *
     * @param  array<string, mixed>  $payloadCompleto
     */
    private function guardar(
        string $gateway,
        WebhookResult $resultado,
        array $payloadCompleto,
        Carbon $agora,
    ): bool {
        // O mesmo aviso pode chegar varias vezes. A unicidade parcial no banco
        // e a fonte da verdade; a consulta abaixo apenas evita o caso comum.
        if ($resultado->eventId !== null && $this->jaRecebido($gateway, $resultado->eventId)) {
            return false;
        }

        // O recorte: cada registro guarda so o pedaco do aviso que fala do seu
        // evento, no formato em que o proprio provedor sabe reler depois. E o
        // que permite ao job continuar tratando um evento por vez.
        $payload = $resultado->raw !== [] ? $resultado->raw : $payloadCompleto;

        try {
            $webhook = WebhookPagamento::create([
                'gateway' => $gateway,
                'id_evento_externo' => $resultado->eventId,
                // O identificador da COBRANCA de que este aviso fala, em coluna
                // propria. Ele ja viajava dentro do payload, mas so ali ele nao
                // se cruza com `pagamentos.id_externo` por consulta — e cruzar
                // essas duas pontas e todo o trabalho de conciliar dinheiro.
                'id_externo' => $resultado->externalId,
                'tipo_evento' => $resultado->eventType,
                'payload' => $this->semDadoSensivel($payload),
                'assinatura_valida' => true,
                'recebido_em' => $agora,
                'situacao' => SituacaoWebhook::Recebido,
            ]);
        } catch (QueryException $excecao) {
            if (str_contains($excecao->getMessage(), 'webhooks_pagamento_evento_externo_unique')) {
                return false;
            }

            throw $excecao;
        }

        ProcessarWebhookPagamento::dispatch($webhook->id);

        return true;
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
            'hmac', 'chave',
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
