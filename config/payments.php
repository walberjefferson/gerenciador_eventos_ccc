<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provedor de pagamento padrao
    |--------------------------------------------------------------------------
    |
    | Define qual implementacao de App\Contracts\Payments\PaymentGateway sera
    | resolvida pelo container. O dominio nunca cita um provedor concreto: a
    | escolha vive aqui e na variavel de ambiente PAYMENT_GATEWAY, e trocar de
    | fornecedor nao exige alterar nenhuma regra de inscricao.
    |
    | Suportados nesta fase: "fake".
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Moeda padrao
    |--------------------------------------------------------------------------
    */

    'currency' => env('PAYMENT_CURRENCY', 'BRL'),

    /*
    |--------------------------------------------------------------------------
    | Provedor simulado
    |--------------------------------------------------------------------------
    |
    | O provedor simulado permite desenvolver e testar o ciclo completo de
    | cobranca sem credencial de nenhuma instituicao financeira.
    |
    | "simulation_enabled" libera as rotas de simulacao de routes/dev.php.
    | Essas rotas SO existem quando o ambiente e local ou testing E esta flag
    | esta ligada. Em qualquer outro caso elas respondem 404.
    |
    | ATENCAO: nenhuma taxa comercial deve ser registrada neste arquivo.
    | Taxas mudam com frequencia e ficam apenas em docs/PAYMENTS.md, sempre
    | com a data da consulta.
    |
    */

    'fake' => [
        'simulation_enabled' => (bool) env('PAYMENT_FAKE_SIMULATION_ENABLED', false),
        'webhook_secret' => env('PAYMENT_FAKE_WEBHOOK_SECRET', ''),
        'pix_key' => env('PAYMENT_FAKE_PIX_KEY', 'chave-pix-ficticia@example.com'),
        'merchant_name' => env('PAYMENT_FAKE_MERCHANT_NAME', 'EVENTOS DEMO'),
        'merchant_city' => env('PAYMENT_FAKE_MERCHANT_CITY', 'SAO PAULO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | Caminho publico que recebe os avisos automaticos do provedor. Fica fora
    | da protecao de CSRF, porque quem chama e um servidor externo.
    |
    */

    'webhook' => [
        'path' => env('PAYMENT_WEBHOOK_PATH', 'webhooks/pagamentos'),
    ],

];
