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
        // Envio do formulario de inscricao, por endereco de internet (IP).
        //
        // Muita gente sai pelo mesmo endereco sem ter nada a ver uma com a
        // outra: a familia inteira no sofa, a fila do salao da paroquia com
        // uma pessoa ajudando a inscrever os outros no mesmo celular, o wi-fi
        // da escola. Punir esse pessoal e pior do que nao ter limite nenhum,
        // porque o prejuizo aparece justamente no dia da abertura.
        //
        // Por isso o teto e alto para gente e baixo para programa: trinta por
        // minuto e mais rapido do que qualquer mao consegue preencher quatro
        // etapas de formulario, e duzentas por hora e mais do que um evento
        // deste porte recebe num dia inteiro. Um script, sem limite, faria
        // milhares por minuto.
        //
        // Os dois numeros sao ajustaveis por variavel de ambiente: se a
        // organizacao planejar um mutirao de inscricao num unico lugar, e so
        // subir o teto naquele dia em vez de tirar a protecao do ar.
        'criar_por_minuto' => (int) env('INSCRICOES_LIMITE_CRIAR_MINUTO', 30),
        'criar_por_hora' => (int) env('INSCRICOES_LIMITE_CRIAR_HORA', 200),

        // Aviso do provedor de pagamento. Limite alto de proposito: quem chama
        // e um servidor, e um pico legitimo de avisos (varias confirmacoes ao
        // mesmo tempo) nao pode ser confundido com enxurrada.
        'webhook_por_minuto' => (int) env('PAGAMENTOS_LIMITE_WEBHOOK_MINUTO', 300),

        // Login administrativo, por IP. E um teto grosso, POR CIMA do limite
        // que o proprio Laravel ja aplica por e-mail (cinco tentativas): este
        // aqui pega quem tenta muitos e-mails diferentes do mesmo lugar.
        'login_por_minuto' => (int) env('ADMIN_LIMITE_LOGIN_MINUTO', 20),

        // Conferencia de ingresso na portaria, por endereco de internet.
        //
        // O teto e alto de proposito, e pelo mesmo motivo do limite de criar
        // inscricao: no dia do evento o portao inteiro sai por UM endereco — o
        // wi-fi do salao, ou o celular de alguem repartindo internet — e sao
        // varios voluntarios conferindo ao mesmo tempo, cada um com a fila na
        // frente. Um teto justo transformaria a defesa em porta trancada
        // justamente na hora em que a tela precisa funcionar.
        //
        // Ele existe mesmo assim porque rota de conferencia sem limite nenhum
        // e convite a varredura. Com ~60 bits de entropia no codigo, 240
        // tentativas por minuto levariam bilhoes de anos para acertar um
        // ingresso — e ainda assim o limite corta o script que tentasse.
        'validar_ingresso' => env('PORTARIA_LIMITE_VALIDAR_INGRESSO', '240,1'),

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
        | O lembrete de prazo.
        |
        | A hora de avisar nao e um numero fixo de horas: e uma fracao do prazo
        | que AQUELA inscricao recebeu. Com 0.5, o aviso sai quando resta
        | metade ou menos do tempo — 12 horas para quem teve 24, 30 minutos
        | para quem teve uma hora.
        |
        | Uma janela fixa nao serviria, porque o prazo e escolhido evento a
        | evento (eventos.prazo_pagamento_minutos, de 5 minutos a 30 dias).
        | Vinte e quatro horas de antecedencia em um evento com prazo de 24
        | horas fariam o "lembrete" chegar junto com o e-mail de inscricao
        | recebida: aviso que chega antes de a pessoa ter tido tempo de pagar
        | nao lembra nada, so ensina a ignorar o proximo.
        |
        | O prazo concedido e medido na propria inscricao (do momento em que
        | ela foi criada ate o prazo dela), e nao no evento: mudar o prazo do
        | evento depois nao pode reescrever a conta de quem ja se inscreveu.
        |
        | O lote e o tamanho da fatia lida por vez, para que uma varredura
        | grande nao carregue tudo na memoria.
        */
        'lembrete' => [
            'fracao_restante' => (float) env('INSCRICOES_LEMBRETE_FRACAO_RESTANTE', 0.5),
            'lote' => (int) env('INSCRICOES_LEMBRETE_LOTE', 100),
        ],

    ],

];
