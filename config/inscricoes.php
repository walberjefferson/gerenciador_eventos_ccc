<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Validade do link de acesso enviado por e-mail
    |--------------------------------------------------------------------------
    |
    | Em dias. E de proposito mais curto que a vida util da inscricao: link que
    | fica parado em caixa de entrada precisa envelhecer sozinho. Vencido, a
    | pessoa pede outro pela pagina de recuperacao de acesso.
    |
    */

    'validade_link_acesso' => (int) env('INSCRICOES_VALIDADE_LINK_ACESSO', 7),

    /*
    |--------------------------------------------------------------------------
    | Limites de tentativa
    |--------------------------------------------------------------------------
    |
    | No formato do middleware throttle: "tentativas,minutos". Servem para que
    | ninguem use as rotas publicas do participante para varrer e-mails ou para
    | pedir cobranca em serie.
    |
    */

    'limites' => [
        'segunda_via' => env('INSCRICOES_LIMITE_SEGUNDA_VIA', '5,1'),
        'acesso_por_minuto' => env('INSCRICOES_LIMITE_ACESSO_MINUTO', '5,1'),
        'acesso_por_hora' => env('INSCRICOES_LIMITE_ACESSO_HORA', '15,60'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tempo minimo de resposta do pedido de link de acesso
    |--------------------------------------------------------------------------
    |
    | Em milissegundos. O pedido responde sempre a mesma coisa, exista ou nao
    | inscricao para o e-mail informado — mas enviar mensagem demora mais do
    | que nao enviar. Este piso apaga essa diferenca: sem ele, dava para
    | descobrir quem esta inscrito cronometrando a resposta.
    |
    */

    'tempo_minimo_resposta_ms' => (int) env('INSCRICOES_TEMPO_MINIMO_RESPOSTA_ACESSO', 400),

];
