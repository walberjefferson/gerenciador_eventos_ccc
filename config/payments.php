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
    | Suportados: "fake" (provedor simulado) e "efi" (Efi, API Pix).
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
    | Efi — provedor real de Pix
    |--------------------------------------------------------------------------
    |
    | Credenciais, certificado e chave Pix da conta recebedora. Tudo vem do
    | ambiente e nenhum valor util fica escrito aqui: sem credencial e sem
    | certificado o provedor recusa operar, que e a falha para o lado seguro.
    |
    | NADA aqui e lido diretamente pelo gateway. A unica classe autorizada a
    | ler este bloco e App\Services\Payments\Efi\ConfiguracaoEfi — e e por isso
    | que a fase seguinte consegue trocar a fonte da configuracao (do ambiente
    | para o banco) mexendo em um arquivo so.
    |
    | As URLs base NAO ficam aqui: sao constantes do provedor, derivadas do
    | ambiente escolhido. Deixa-las configuraveis seria permitir que uma linha
    | errada no ambiente mandasse cobranca de teste para producao.
    |
    | ATENCAO: nenhuma taxa comercial deve ser registrada neste arquivo.
    |
    */

    'efi' => [
        // "homologacao" ou "producao". Qualquer outro valor e tratado como
        // homologacao: errar para o lado que nao move dinheiro de verdade.
        'environment' => env('EFI_ENVIRONMENT', 'homologacao'),
        'client_id' => env('EFI_CLIENT_ID', ''),
        'client_secret' => env('EFI_CLIENT_SECRET', ''),
        // Caminho absoluto do .pem convertido a partir do .p12 do painel.
        // O arquivo fica FORA do repositorio (ver .gitignore).
        'cert_path' => env('EFI_CERT_PATH', ''),
        // Chave Pix da conta que RECEBE, nunca a de quem paga.
        'pix_key' => env('EFI_PIX_KEY', ''),
        // Valor conferido no parametro "hmac" da URL do webhook.
        'webhook_hmac' => env('EFI_WEBHOOK_HMAC', ''),
        // Segundos de espera por resposta. Conservador de proposito: a emissao
        // da cobranca acontece com a pessoa esperando na tela.
        'timeout' => env('EFI_TIMEOUT', 20),
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
