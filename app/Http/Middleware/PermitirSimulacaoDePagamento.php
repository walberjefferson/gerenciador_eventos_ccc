<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda das rotas de simulacao de pagamento.
 *
 * Elas so existem no computador de quem desenvolve e na suite de testes. Em
 * qualquer outro ambiente a resposta e 404 — nao 403: quem tenta descobrir a
 * porta nem fica sabendo que ela existe.
 */
class PermitirSimulacaoDePagamento
{
    public function handle(Request $request, Closure $next): Response
    {
        $ambientePermitido = app()->environment(['local', 'testing']);
        $chaveLigada = (bool) config('payments.fake.simulation_enabled', false);

        abort_unless($ambientePermitido && $chaveLigada, 404);

        return $next($request);
    }
}
