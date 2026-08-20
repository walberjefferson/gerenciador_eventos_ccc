<?php

declare(strict_types=1);

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\WebhookRequestData;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Middleware\PermitirSimulacaoDePagamento;
use App\Services\Payments\Fake\FakePaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas de simulacao de pagamento
|--------------------------------------------------------------------------
|
| Existem para que seja possivel percorrer o ciclo inteiro — cobranca criada,
| paga, vencida, falhada, estornada — sem credencial de nenhuma instituicao
| financeira e sem esperar dinheiro de verdade.
|
| Elas so sao registradas em local/testing com a simulacao ligada, e ainda
| passam por um middleware que confere as duas condicoes. Em producao a
| resposta e 404.
|
| Repare que nenhuma delas confirma inscricao diretamente: cada uma muda o
| estado no provedor simulado e faz o provedor emitir o aviso assinado. O
| caminho percorrido e exatamente o mesmo de um pagamento real.
|
*/

/**
 * Entrega ao sistema o aviso que o provedor simulado acabou de emitir,
 * passando pela mesma porta publica que uma instituicao real usaria.
 */
$entregarAviso = function (WebhookRequestData $aviso): JsonResponse {
    $requisicao = Request::create(
        '/'.ltrim((string) config('payments.webhook.path', 'webhooks/pagamentos'), '/'),
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.str_replace('-', '_', strtoupper(FakePaymentGateway::SIGNATURE_HEADER)) => (string) $aviso->signature,
        ],
        $aviso->rawBody,
    );

    return app(PaymentWebhookController::class)($requisicao, app(PaymentGateway::class));
};

Route::middleware(PermitirSimulacaoDePagamento::class)
    ->prefix('dev/pagamentos')
    ->name('dev.pagamentos.')
    ->group(function () use ($entregarAviso): void {
        Route::get('{idExterno}', function (string $idExterno): JsonResponse {
            $situacao = app(PaymentGateway::class)->getPayment($idExterno);

            return response()->json([
                'id_externo' => $situacao->externalId,
                'status' => $situacao->status,
                'valor_centavos' => $situacao->amountCents,
                'pago_em' => $situacao->paidAt?->toIso8601String(),
            ]);
        })->name('mostrar');

        $simular = function (string $acao, string $status) use ($entregarAviso): callable {
            return function (string $idExterno) use ($acao, $status, $entregarAviso): JsonResponse {
                /** @var FakePaymentGateway $gateway */
                $gateway = app(PaymentGateway::class);

                match ($acao) {
                    'pagar' => $gateway->simulatePayment($idExterno),
                    'expirar' => $gateway->simulateExpiration($idExterno),
                    'falhar' => $gateway->simulateFailure($idExterno),
                    'estornar' => $gateway->refundPayment($idExterno),
                };

                $resposta = $entregarAviso($gateway->emitWebhook($idExterno, $status));

                return response()->json([
                    'simulado' => $acao,
                    'id_externo' => $idExterno,
                    'aviso' => json_decode((string) $resposta->getContent(), true),
                ], $resposta->getStatusCode());
            };
        };

        Route::post('{idExterno}/pagar', $simular('pagar', 'paid'))->name('pagar');
        Route::post('{idExterno}/expirar', $simular('expirar', 'expired'))->name('expirar');
        Route::post('{idExterno}/falhar', $simular('falhar', 'failed'))->name('falhar');
        Route::post('{idExterno}/estornar', $simular('estornar', 'refunded'))->name('estornar');
    });
