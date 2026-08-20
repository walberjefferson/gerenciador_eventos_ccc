<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Payments\PaymentGateway;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Services\Payments\Fake\FakePaymentGateway;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Decide qual provedor de pagamento o sistema usa e registra as rotas de
 * pagamento.
 *
 * A escolha vive em config/payments.php: nenhuma regra de inscricao sabe o
 * nome do fornecedor. Trocar de instituicao financeira e trocar uma linha de
 * configuracao mais uma implementacao nova do contrato.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            $escolhido = (string) config('payments.default');

            return match ($escolhido) {
                'fake' => new FakePaymentGateway(
                    (array) config('payments.fake', []),
                    $app->make(FilesystemFactory::class)->disk('local'),
                ),
                default => throw new InvalidArgumentException(
                    "Provedor de pagamento nao suportado: {$escolhido}."
                ),
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // Quem chama e um servidor, nao um navegador: a rota fica fora do grupo
        // "web" de proposito — sem sessao, sem cookie e, portanto, sem CSRF.
        Route::post(
            (string) config('payments.webhook.path', 'webhooks/pagamentos'),
            PaymentWebhookController::class
        )->name('webhooks.pagamentos');

        // As rotas de simulacao so nascem em local/testing e com a chave ligada.
        // Ainda assim, cada uma passa por um middleware que confere as duas
        // condicoes de novo: uma configuracao trocada em producao nao pode
        // abrir uma porta de "pagar sem pagar".
        if ($this->simulacaoPermitida()) {
            Route::group([], base_path('routes/dev.php'));
        }
    }

    private function simulacaoPermitida(): bool
    {
        return $this->app->environment(['local', 'testing'])
            && (bool) config('payments.fake.simulation_enabled', false);
    }
}
