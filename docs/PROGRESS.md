# Progresso

> Atualizado ao final de cada etapa de trabalho. Escrito para ser lido por qualquer pessoa da equipe.
> **Última atualização:** 2026-08-20 — Etapa 8 (testes das regras de inscrição). **Fase 3 — Inscrição: concluída.**

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

**Com isso, a Fase 3 — Inscrição está concluída:** regras RN-01 a RN-13, reserva de vaga por contador atômico, varredura sob demanda das reservas vencidas e cobertura de teste dos seis cenários exigidos pelo briefing que dependem apenas do domínio de inscrição.

## Em andamento

- [ ] Nada em andamento. Próxima entrega: Fase 4 — Pagamento simulado (Etapa 9)

## Próximas tarefas

### Fase 4 — Pagamento simulado (Etapa 9)

O que ainda falta construir, na ordem:

- [ ] Migrações `pagamentos` e `webhooks_pagamento` (dinheiro em centavos, número inteiro; identificadores sem acento)
- [ ] Enum `SituacaoPagamento` e modelos `Pagamento` e `WebhookPagamento`
- [ ] Contrato do provedor de pagamento com `parseWebhook` (tradução, não ação — decisão D-17) e a implementação simulada, ligada por `config/payments.php`
- [ ] Action `IniciarPagamento`, criada a partir da inscrição recém-nascida (o valor já vem congelado em `valor_centavos`)
- [ ] Action `ConfirmarPagamento`: transforma reserva em vaga confirmada, movendo o contador de `vagas_reservadas` para `vagas_confirmadas` na mesma transação, sem apagar registro
- [ ] Endereço de webhook que responde **200 mesmo com assinatura inválida** (decisão D-18), gravando o aviso como inválido e sem produzir efeito; proteção contra aviso repetido
- [ ] Completar `ExpirarInscricoesVencidas` com o `TODO(Fase 4)` que ficou no código: cancelar o pagamento pendente e disparar o anúncio `InscricaoExpirada` (decisão D-23)
- [ ] Ouvintes dos anúncios internos `InscricaoCriada` e `InscricaoConfirmada` (ainda sem e-mail — decisão D-12)
- [ ] Testes de pagamento, incluindo o aviso repetido e o pagamento que chega depois do prazo (pendência P-03)

### Fase 5 (Etapa 10)

- [ ] Prazo, expiração agendada, reconciliação e fechamento do evento

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
| D-25 | O prazo de pagamento (`prazo_pagamento`) já é gravado na criação da inscrição | RN-P01 manda congelar o prazo no momento da inscrição. Sem ele gravado, a varredura sob demanda não teria como saber quais reservas venceram |

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
