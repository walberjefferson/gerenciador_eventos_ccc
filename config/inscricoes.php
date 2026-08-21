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

    /*
    |--------------------------------------------------------------------------
    | Comunicacao com o participante
    |--------------------------------------------------------------------------
    |
    | Todo e-mail sai pela fila: servidor de e-mail lento nunca pode atrasar
    | uma inscricao. A conexao fica vazia de proposito — assim vale a conexao
    | padrao da aplicacao (redis em producao, "sync" em teste), e nao e preciso
    | um valor diferente em cada ambiente. A fila propria ("emails") existe
    | para que um dia um trabalhador dedicado nao dispute vez com outro tipo
    | de tarefa.
    |
    | As tentativas cuidam do caso comum de falha: servidor de e-mail fora do
    | ar por alguns minutos. Espera crescente (1, 5 e 15 minutos) da tempo de
    | ele voltar sem inundar a fila. Esgotadas as tentativas, o trabalho vai
    | para "failed_jobs" e nada acontece com a inscricao, a vaga ou o pagamento.
    |
    */

    'comunicacao' => [

        'conexao' => env('COMUNICACAO_CONEXAO_FILA') ?: null,

        'fila' => env('COMUNICACAO_FILA', 'emails'),

        'tentativas' => (int) env('COMUNICACAO_TENTATIVAS', 3),

        // Em segundos, uma espera por tentativa.
        'espera_entre_tentativas' => [60, 300, 900],

        /*
        | O lembrete de prazo. A janela e o quanto antes do vencimento a
        | mensagem sai: com 24 horas, quem tem prazo vencendo dentro desse
        | intervalo recebe o aviso. O lote e o tamanho da fatia lida por vez,
        | para que uma varredura grande nao carregue tudo na memoria.
        */
        'lembrete' => [
            'janela_horas' => (int) env('INSCRICOES_LEMBRETE_JANELA_HORAS', 24),
            'lote' => (int) env('INSCRICOES_LEMBRETE_LOTE', 100),
        ],

    ],

];
