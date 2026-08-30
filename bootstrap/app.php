<?php

use App\Http\Middleware\CabecalhosDeSeguranca;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
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
        // O Traefik termina o TLS e conversa com este conteiner em HTTP simples,
        // pela rede interna do stack. Sem confiar nos cabecalhos que ele envia,
        // o framework julga que TODA requisicao e http — e o estrago aparece
        // longe daqui:
        //
        // 1. As URLs assinadas param de validar. O link da inscricao sai por
        //    e-mail com "https" (quem gera e o trabalhador da fila, que usa
        //    APP_URL), e chega numa requisicao que o framework le como "http".
        //    A assinatura confere a URL inteira, esquema incluido: nao bate, e
        //    o participante recebe 403 no link que acabou de receber.
        // 2. O Strict-Transport-Security nao sai, porque CabecalhosDeSeguranca
        //    so o emite em resposta segura.
        // 3. Todo url() e route() sai com esquema errado.
        //
        // Confiar em "*" e seguro AQUI porque nada alem do Traefik alcanca este
        // conteiner: a porta 80 nao e publicada no host (docker/compose.portainer.yaml).
        // O IP do Traefik tambem nao e fixo — ele muda a cada recriacao do
        // conteiner dele —, entao uma lista de IP daria falso negativo em dia
        // de manutencao, que e o pior momento para o site quebrar.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

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
