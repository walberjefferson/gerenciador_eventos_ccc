# Progresso

> Atualizado ao final de cada etapa de trabalho. Escrito para ser lido por qualquer pessoa da equipe.
> **Última atualização:** 2026-08-20 — Etapa 10 (prazo, expiração automática e reconciliação). **Fases 0 a 4 concluídas: o backend do MVP está completo.**

---

## Concluído

- [x] Etapa 1 — `docs/PRD.md` com as 24 seções exigidas + Glossário; `docs/PROGRESS.md`
- [x] Etapa 2 — `docs/ARCHITECTURE.md`, `docs/DATABASE.md` (ERD + dicionário de dados), `docs/BUSINESS_RULES.md` (RN-01 a RN-13 + regras de pagamento)
- [x] Etapa 3 — `docs/PAYMENTS.md` (matriz de provedores com data de consulta) e `docs/IMPLEMENTATION_PLAN.md`; revisão cruzada dos 7 documentos
- [x] Etapa 4 — Base do projeto: Laravel 12 com pacote inicial Vue (Inertia + TypeScript + Tailwind), Laravel Sail com PostgreSQL 18, Redis e Mailpit, `config/payments.php`, fuso `America/Sao_Paulo`, Pest e Pint funcionando. Migrações do framework aplicadas no PostgreSQL e 27 testes do pacote inicial passando

- [x] Etapa 5 — Domínio do evento: migrações de `cidades`, `grupos_participantes`, `eventos`, `dias_evento`, `grupos_atividades`, `atividades` e `conflitos_atividades` com todas as restrições `CHECK` e índices; Enum `SituacaoEvento`; sete modelos em português com relações, conversões e filtros

- [x] Etapa 6 — Fábricas de dados para os sete modelos, `CidadeSeeder`, `EventoDemoSeeder` (Copa CCC 2026, dois dias) e `tests/Feature/Dominio/EventoTest.php` com 29 testes provando relações, filtros e as restrições do banco

- [x] Etapa 7 — Inscrição: migrações de `inscricoes` (com as unicidades parciais de e-mail e CPF) e `inscricoes_atividades`; Enum `SituacaoInscricao`; modelos `Inscricao` e `InscricaoAtividade`; `DadosNovaInscricao`; `ValidadorSelecaoAtividades` (RN-03 a RN-08); `ReservarVagas` e `LiberarVagas` (contador atômico em ordem fixa); `CriarInscricao` (transação única, varredura sob demanda com uma retentativa, tradução das violações de unicidade em mensagem amigável); `ExpirarInscricoesVencidas` na versão mínima; `StoreInscricaoRequest`, `InscricaoController@store` e a rota `POST /inscricoes`; anúncio interno `InscricaoCriada`

- [x] Etapa 8 — Testes das regras de inscrição: `tests/Feature/Inscricoes/` com `InscricaoTest`, `SelecaoAtividadesTest`, `ConflitoAtividadeTest`, `CapacidadeAtividadeTest`, `InscricaoDuplicadaTest` e `ConcorrenciaTest`, apoiados no cenário compartilhado `Cenario.php`. A concorrência é provada de duas formas: pela gravação condicional (o `UPDATE` não altera nenhuma linha quando o contador alcançou a capacidade) e por seis processos de sistema operacional de verdade disputando a última vaga ao mesmo tempo, cada um com a sua própria conexão (`scripts/disputar-vaga.php`). **Suíte completa: 140 testes, 333 asserções, tudo passando**

- [x] Etapa 9 — Pagamento e webhook: migrações `pagamentos` e `webhooks_pagamento` (dinheiro em centavos, unicidade parcial do identificador externo); Enums `SituacaoPagamento`, `MetodoPagamento` e `SituacaoWebhook`; modelos `Pagamento` e `WebhookPagamento`; contrato `PaymentGateway` e DTOs somente leitura (`app/Contracts/Payments`, `app/DTOs/Payments` — em inglês, por espelharem a fronteira externa); `FakePaymentGateway` completo (emite cobrança Pix fictícia no formato EMV, paga, vence, falha, cancela, estorna e emite aviso assinado); `PaymentServiceProvider` escolhendo o provedor por `config/payments.php`; Actions `CriarPagamentoDaInscricao`, `ConfirmarPagamento` e `CancelarPagamento`; `PaymentWebhookController` (confere assinatura, grava o aviso cru sem dado sensível, responde imediatamente) e o job idempotente `ProcessarWebhookPagamento`; `routes/dev.php` com as rotas de simulação, com dupla trava de ambiente e configuração. A criação de inscrição já emite a cobrança, com `expira_em` igual ao `prazo_pagamento`. **Suíte completa: 160 testes, 424 asserções, tudo passando**

- [x] Etapa 10 — Prazo, expiração e reconciliação: `ExpirarInscricoesVencidas` completa (marca a inscrição como expirada, devolve a vaga do evento e a de cada atividade, encerra a cobrança como "prazo vencido" e anuncia `InscricaoExpirada`, tudo em lotes de 100 com `chunkById`); anúncios internos `InscricaoConfirmada` e `InscricaoExpirada`; comandos `inscricoes:expirar-vencidas` (com a opção `--evento`) e `pagamentos:reconciliar` (com as opções `--margem` e `--lote`), agendados em `routes/console.php` a cada minuto e a cada cinco minutos; testes `PrazoPagamentoTest`, `ExpiracaoInscricaoTest` e `ReconciliacaoTest`. **Suíte completa: 177 testes, 521 asserções, tudo passando**

**Com isso, a Fase 4 — Pagamento está concluída**, e o fluxo do briefing foi verificado de ponta a ponta em banco recém-semeado, por comandos artisan: inscrição válida reserva a vaga do evento e a de cada atividade → cobrança Pix fictícia emitida com o mesmo prazo da inscrição → aviso assinado entregue na rota pública (`POST /webhooks/pagamentos` → HTTP 200) → inscrição confirmada com os contadores passando de reservada para confirmada; e, em seguida, uma segunda inscrição com o prazo vencido → `php artisan inscricoes:expirar-vencidas` → vaga do evento e de cada atividade de volta a zero reservadas, cobrança em "prazo vencido", **nenhuma linha apagada** (2 inscrições, 2 pagamentos e 4 vínculos inscrição-atividade continuam no banco) e a segunda execução do comando não muda mais nada.

**Com isso, a Fase 3 — Inscrição está concluída:** regras RN-01 a RN-13, reserva de vaga por contador atômico, varredura sob demanda das reservas vencidas e cobertura de teste dos seis cenários exigidos pelo briefing que dependem apenas do domínio de inscrição.

## Em andamento

- [ ] Nada em andamento. O backend do MVP (Fases 0 a 4) está fechado. A próxima entrega é a **Fase 5 — Frontend público**, em plano separado

## Próximas tarefas

O que **está pronto** hoje: todo o núcleo transacional. Evento configurável, inscrição com reserva de vaga à prova de venda a mais, regras de seleção de atividades, cobrança Pix simulada, aviso automático (webhook) idempotente, expiração agendada que devolve vaga e reconciliação server-to-server. Não existe nenhuma tela pública, nenhum e-mail sai e nenhum dinheiro real circula.

O que **falta**, por fase:

### Fase 5 — Frontend público
- [ ] Páginas Inertia + Vue: vitrine do evento, formulário de inscrição (com seleção de atividades respeitando `min_selecoes`/`max_selecoes`, conflitos de horário e faixa etária), página de pagamento com o Pix copia e cola e a contagem regressiva do prazo, e página de acompanhamento da inscrição
- [ ] Controller de leitura para a vitrine e para o acompanhamento (hoje só existe `POST /inscricoes`)
- [ ] Decidir como o participante volta à própria inscrição depois (pendência **P-05**: link assinado ou código por e-mail)
- [ ] Validação no navegador espelhando `ValidadorSelecaoAtividades` — sem nunca substituir a validação do servidor

### Fase 6 — Administração
- [ ] Painel: total de inscrições por situação, vagas restantes por atividade, receita reconhecida
- [ ] CRUDs de evento, dias, grupos de atividades, atividades, conflitos, cidades e grupos de participantes
- [ ] Lista de inscrições com filtros e exportação; visualização de uma inscrição com o histórico da cobrança
- [ ] Policies de acesso administrativo (hoje só existe a autenticação do pacote inicial)

### Fase 7 — Comunicação
- [ ] Ouvintes de `InscricaoCriada`, `InscricaoConfirmada` e `InscricaoExpirada` — os três anúncios já são disparados pelo domínio e **não têm nenhum ouvinte** (decisão D-12). É só plugar
- [ ] E-mails: confirmação de inscrição com o Pix, comprovante de pagamento, aviso de prazo vencido
- [ ] Lembrete de prazo próximo do fim (comando agendado, no mesmo molde do `inscricoes:expirar-vencidas`)

### Fase 8 — Provedor de pagamento real
- [ ] Escolher o provedor (pendência **P-01**) e confirmar as taxas (**P-06**)
- [ ] Implementar a classe do provedor real contra o mesmo contrato `PaymentGateway` e registrá-la no `match` do `PaymentServiceProvider`. **Nenhuma Action, Model, Job ou teste de domínio precisa mudar** — só o `PAYMENT_GATEWAY` do `.env`
- [ ] Conferir a assinatura do webhook real e o vocabulário de status do provedor em `SituacaoPagamento::deStatusExterno()`
- [ ] Definir a política de reembolso (**P-02**) e ligar `refundPayment()` a uma Action de estorno (hoje o contrato existe, o fluxo de domínio não)

### Fase 9 — Endurecimento
- [ ] Registro de auditoria das ações administrativas
- [ ] Revisão de desempenho com volume real (índices já existem; falta medir)
- [ ] Revisão de LGPD: prazo de retenção e descarte (**P-04**)
- [ ] Decidir o que fazer com pagamento reconhecido depois do prazo (**P-03**) — ver a decisão D-34

---

## Decisões

| # | Decisão | Motivo |
|---|---------|--------|
| D-01 | O domínio (banco, modelos, situações, regras) é escrito em **português, sem acento nem cedilha** | Quem discute as regras do evento fala português. Acento em nome de coluna obrigaria aspas em toda consulta no PostgreSQL |
| D-02 | A estrutura do framework permanece em **inglês** (`app/Http/Controllers`, `app/Jobs`, `created_at`) | É a convenção da ferramenta; mudar quebraria comportamentos automáticos |
| D-03 | A inscrição **não** tem estado "pago" | "Pago" é fato do dinheiro e pertence ao pagamento. Duas fontes da verdade discordariam em caso de estorno |
| D-04 | Reserva de vaga por **contador atômico** (compare-and-swap), não por trava na linha do evento | Evita fila única em eventos de alta procura, com a mesma garantia contra venda a mais |
| D-05 | Ordem de reserva sempre **evento → atividades em ordem crescente de id** | Ordem fixa elimina a possibilidade de duas inscrições travarem uma esperando a outra |
| D-06 | Dinheiro sempre em **centavos, número inteiro** | Número decimal aproximado gera erro de arredondamento em dinheiro |
| D-07 | **Sem tabela de aceite de termos** no MVP: dois campos na inscrição bastam | Menos superfície para o mesmo requisito. Se um dia houver mais de um termo por inscrição, vira tabela |
| D-08 | CPF **cifrado** no banco + **impressão digital** separada para duplicidade | Permite bloquear inscrição duplicada sem manter o número legível |
| D-09 | Cidades e grupos de participantes são **catálogo global**, não pertencem a um evento | Simplifica o cadastro. Se o negócio exigir cidades por evento, é mudança de escopo |
| D-10 | Fuso: aplicação em `America/Sao_Paulo`, banco em UTC, colunas com fuso embutido | Evita erro de horário de verão e de servidor em outro fuso |
| D-11 | `lista_espera` existe na lista de situações mas **não é alcançável** nesta entrega | Reserva o valor para a fase pós-MVP sem criar caminho de código morto |
| D-12 | Nenhum e-mail nesta entrega: apenas os anúncios internos (eventos de domínio) sem ouvintes | A fase 7 adiciona os ouvintes sem tocar nas regras de inscrição |
| D-13 | Situações gravadas como **texto** no banco, controladas por Enum do PHP, em vez do tipo `enum` do PostgreSQL | Acrescentar um valor ao tipo `enum` do PostgreSQL é alteração de esquema com restrições. A lista de situações vai crescer (`lista_espera`) |
| D-14 | O par de `conflitos_atividades` é **normalizado** (`atividade_a_id < atividade_b_id`), garantido por restrição do banco | Sem isso, (7,3) e (3,7) seriam duas linhas para o mesmo conflito e a unicidade não protegeria nada |
| D-15 | Unicidade de e-mail e CPF é **parcial** (só vale para inscrições ativas) | Uma unicidade comum bloquearia para sempre; o participante precisa poder tentar de novo depois da expiração |
| D-16 | Em RN-04, grupo **opcional** com mínimo maior que zero significa "ou nada, ou pelo menos o mínimo" | Interpretação mais restritiva entre as possíveis, conforme a política de ambiguidade do plano |
| D-17 | O provedor de pagamento devolve `parseWebhook` (tradução), não `handleWebhook` (ação) | Mantém a decisão sobre o domínio de um lado só da fronteira; trocar de provedor não muda o efeito na inscrição |
| D-18 | O endereço de webhook responde **200 mesmo com assinatura inválida**, gravando o aviso como inválido e sem produzir efeito | Responder 401 informaria a quem tenta forjar avisos que ele acertou o endereço e errou só a assinatura |
| D-19 | O PostgreSQL do Sail é publicado na porta **55432** do computador (variável `FORWARD_PGSQL_PORT`), e não na 5432 | A porta 5432 já estava ocupada por outro PostgreSQL instalado na máquina de desenvolvimento, que respondia no lugar do contêiner. A porta interna do contêiner continua sendo 5432 |
| D-20 | Os testes rodam no banco `testing` do mesmo PostgreSQL, com `DB_HOST`/`DB_PORT` fixados em `phpunit.xml` | Restrição parcial de unicidade, `CHECK`, `jsonb` e concorrência real só têm valor testados no mesmo motor de produção |
| D-21 | As recusas de inscrição partem de uma classe base comum (`InscricaoInvalidaException`), e RN-01/RN-02 ganharam a sua própria (`InscricaoIndisponivelException`), além das três previstas no plano | A classe base guarda as mensagens já agrupadas pelo campo do formulário, e o controller as devolve como erro de validação (422). Sem ela, cada recusa teria o seu próprio formato e o controller decidiria texto para o participante |
| D-22 | O envio do formulário é a rota `POST /inscricoes` em `routes/web.php` | O projeto não tem `routes/api.php`; criar um exigiria instalar o pacote de API só para uma rota. A proteção contra envio forjado (CSRF) do grupo `web` é bem-vinda em um formulário público |
| D-23 | `ExpirarInscricoesVencidas` foi antecipada da etapa de pagamento para a etapa de inscrição | A varredura sob demanda (quando o contador diz "lotado") depende dela. A versão atual apenas muda a situação e devolve as vagas; cancelar a cobrança e anunciar a expiração entram junto com o domínio de pagamento |
| D-24 | Envio repetido com a mesma chave de idempotência responde **200**, e não 201 | Nada de novo foi criado. O participante recebe a mesma inscrição, e o código de resposta diz a verdade sobre o que aconteceu |
| D-26 | O provedor simulado guarda o estado de cada cobrança em **arquivo no disco local** (`storage/app/private/pagamentos-simulados`), não em memória nem em cache | A cobrança é criada em uma requisição e paga em outra, inclusive em processos diferentes (o teste de concorrência dispara processos de sistema operacional). Arquivo funciona em qualquer máquina, sem depender de Redis para desenvolver |
| D-27 | A cobrança é emitida **fora** da transação que cria a inscrição | Conversa com serviço externo não pode segurar uma transação de banco aberta. Se a emissão falhar, a inscrição já existe e a cobrança sai na tentativa seguinte, porque `CriarPagamentoDaInscricao` é repetível |
| D-28 | A rota do webhook fica **fora do grupo `web`** | Quem chama é um servidor, não um navegador: sem sessão e sem cookie, não há proteção de CSRF a satisfazer nem por que criar sessão |
| D-29 | As rotas de simulação têm **duas travas**: só são registradas em `local`/`testing` com a chave ligada, e ainda passam por um middleware que confere as duas condições de novo | Uma configuração trocada por engano em produção não pode abrir uma porta de "pagar sem pagar". A resposta é 404, não 403: quem procura a porta nem descobre que ela existe |
| D-30 | A chave estrangeira de `pagamentos` para `inscricoes` é **restrict** | Apagar inscrição já é proibido; a restrição do banco garante que nenhum histórico de dinheiro suma junto com um apagamento acidental |
| D-31 | O aviso do provedor é guardado com os campos sensíveis substituídos por `[removido]` (segredo, token, cartão, CVV) | O aviso serve para investigar divergência, não para colecionar dado sensível |
| D-25 | O prazo de pagamento (`prazo_pagamento`) já é gravado na criação da inscrição | RN-P01 manda congelar o prazo no momento da inscrição. Sem ele gravado, a varredura sob demanda não teria como saber quais reservas venceram |
| D-32 | Os anúncios internos (`InscricaoConfirmada`, `InscricaoExpirada`) são disparados **depois** que a transação fecha, e só na chamada que de fato mudou a situação | Ninguém pode ser avisado de um fato que o banco ainda pode desfazer. E, como a mudança é condicional à situação anterior, aviso repetido do provedor não gera segundo anúncio |
| D-33 | A reconciliação roda **a cada cinco minutos** e olha apenas cobranças a até quinze minutos do vencimento | Perguntar de cinco em cinco minutos reconhece o pagamento bem antes do prazo vencer, e a margem estreita mantém o volume de consultas baixo — provedores cobram limite de chamadas por minuto |
| D-34 | A reconciliação **fecha** aqui a cobrança que o provedor já deu por vencida, mas **não devolve vaga** por conta própria | Vaga é assunto da inscrição, e quem devolve é sempre a expiração. Duas rotinas mexendo no mesmo contador seria a receita para contar errado |
| D-35 | A expiração processa em lotes de 100 com `chunkById` e cada inscrição em sua própria transação | Uma transação única sobre milhares de linhas seguraria bloqueios por tempo demais. Falha em uma inscrição não derruba o lote inteiro |

---

## Pendências

| # | Pendência | Responsável |
|---|-----------|-------------|
| P-01 | Escolher o provedor de pagamento real | Dono do produto |
| P-02 | Definir política de reembolso | Dono do produto |
| P-03 | Definir o que fazer com pagamento recebido após o prazo | Dono do produto |
| P-04 | Definir prazo de retenção e descarte de dados pessoais | Dono do produto |
| P-05 | Definir como o participante acessa a inscrição depois (link assinado, código por e-mail) | Fase 5 |
| P-06 | Confirmar as taxas de Pagar.me, Mercado Pago e Asaas diretamente com o comercial | Dono do produto. Ver seção 6.3 de `PAYMENTS.md` |
| P-08 | Conferir no `.env` local as chaves `PAYMENT_GATEWAY`, `PAYMENT_FAKE_SIMULATION_ENABLED` e `PAYMENT_FAKE_WEBHOOK_SECRET`, como já estão em `.env.example`. Sem o segredo do webhook, o provedor simulado recusa todo aviso (falha para o lado seguro) | Pessoa desenvolvedora, na própria máquina |
| P-07 | Ajustar o arquivo `.env` local para `DB_PORT=55432` e `FORWARD_PGSQL_PORT=55432`, como já está em `.env.example` (decisão D-19) | Pessoa desenvolvedora, na própria máquina |

---

## Revisão cruzada dos documentos (etapa 3)

Verificações feitas nos sete documentos, com o resultado:

| Verificação | Resultado |
|-------------|-----------|
| Nomes de tabela iguais em todos os documentos | Sem divergência |
| Situações de inscrição e de pagamento iguais em todos os documentos | Sem divergência |
| A palavra "pago" nunca aparece como situação de inscrição | Confirmado: só aparece como situação de pagamento ou na justificativa da decisão D-03 |
| Nenhum identificador de banco com acento ou cedilha | Confirmado |
| `float`/`double` só aparecem como o que **não** se deve usar | Confirmado |
| `lockForUpdate()` só aparece como prática proibida, com o motivo | Confirmado |
| Regra de capacidade descrita igual no PRD, na arquitetura, no banco e nas regras | Sem divergência |
| Mapeamento dos oito testes exigidos pelo briefing | Presente em `BUSINESS_RULES.md` |
| Diagramas Mermaid válidos | Os 11 diagramas foram renderizados com `@mermaid-js/mermaid-cli`; todos passaram |

Nenhuma contradição exigiu correção. As decisões D-13 a D-18 foram registradas durante a redação dos documentos técnicos.

**Sobre as taxas de gateway:** apenas a Efí publica os percentuais em página aberta. Pagar.me apresenta preço negociado, e Mercado Pago e Asaas não expõem valores em conteúdo público acessível. Essas células ficaram como **"a validar"**, conforme a regra de nunca registrar número comercial não confirmado.

---

## Dependências externas adicionadas

Nenhuma até o momento além do que o framework já entrega.
