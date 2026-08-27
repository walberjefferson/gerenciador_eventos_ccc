<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resend — o servico que entrega os e-mails em producao
    |--------------------------------------------------------------------------
    |
    | Em desenvolvimento o e-mail para no Mailpit e nenhuma chave e necessaria.
    | Em producao (docker/compose.portainer.yaml) o MAIL_MAILER e "resend" e a
    | chave vem de RESEND_API_KEY, cadastrada so no Portainer.
    |
    | O pacote resend/resend-laravel le "resend.api_key" (RESEND_API_KEY) e cai
    | aqui como reserva. Os dois nomes ficam aceitos de proposito: quem chega ao
    | painel da Resend recebe a variavel chamada RESEND_API_KEY, e quem conhece
    | o Laravel procura por RESEND_KEY. Errar o nome da variavel e uma falha
    | silenciosa — o e-mail simplesmente nao sai.
    |
    */

    'resend' => [
        'key' => env('RESEND_API_KEY', env('RESEND_KEY')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
