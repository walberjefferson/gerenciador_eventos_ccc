<?php

use App\Http\Middleware\CabecalhosDeSeguranca;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    // Descoberta automatica de ouvintes desligada: quem escuta cada anuncio do
    // dominio esta escrito a mao em AppServiceProvider. Ver o motivo la.
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Global, e nao so no grupo "web": o aviso do provedor de pagamento
        // roda fora do grupo web, e cabecalho de seguranca que depende de a
        // rota estar no grupo certo e cabecalho que um dia vai faltar.
        $middleware->append(CabecalhosDeSeguranca::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Apelidos do spatie/laravel-permission. Sao eles que amarram a
        // permissao na rota: "permission:painel.ver" recusa com 403 quem nao
        // tiver a permissao, mesmo estando autenticado.
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
