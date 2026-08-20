# Execution Report — Plataforma de Inscrições e Gestão de Eventos (Fases 0→4)

> **Plan:** plataforma-eventos-backend-mvp — Steps 1 a 10 (plano inteiro)
> **Executed:** 2026-08-20
> **Status:** ✅ COMPLETE

Relatório final. Cobre o plano completo; os relatórios por etapa continuam em
`step-N.done.md` neste mesmo diretório.

---

## 1. Visão geral do que foi construído

Oito commits, um por etapa coesa, todos com a suíte verde no momento do commit:

| Commit | Etapas | Entrega |
|--------|--------|---------|
| `480fc72` | 1-3 | `docs/` completo: PRD, ARCHITECTURE, DATABASE, BUSINESS_RULES, PAYMENTS, IMPLEMENTATION_PLAN, PROGRESS |
| `19f8abd` | 4 | Laravel 12 + pacote inicial Vue/Inertia/TS/Tailwind, Sail (PostgreSQL 18, Redis, Mailpit), `config/payments.php`, Pest, Pint |
| `8d66ce0` | 5 | Schema e models do domínio de evento (7 tabelas, todos os `CHECK` e índices) |
| `619afa0` | 6 | Fábricas, `CidadeSeeder`, `EventoDemoSeeder` (Copa CCC 2026) e `EventoTest` |
| `643f4e2` | 7 | Inscrição: schema, `ValidadorSelecaoAtividades` (RN-03…RN-08), reserva atômica de vaga, `CriarInscricao`, controller e rota |
| `ab0860c` | 8 | Suíte das regras de inscrição, incluindo concorrência com processos paralelos de verdade |
| `af3cc40` | 9 | Pagamento: schema, contrato `PaymentGateway` + DTOs, `FakePaymentGateway`, Actions, webhook (controller + job idempotente), rotas de simulação |
| `6530b2c` | 10 | Prazo, expiração agendada, reconciliação, anúncios de domínio, suíte da Fase 4, docs atualizados |

### O que a Etapa 10 acrescentou (escopo deste executor)

| File | Action | Description |
|------|--------|-------------|
| `app/Actions/Inscricoes/ExpirarInscricoesVencidas.php` | alterado | Completado o `TODO(Fase 4)`: além de marcar `expirada` e devolver os contadores, encerra toda cobrança pendente da inscrição como `expirado` e anuncia `InscricaoExpirada` **depois** do commit. Mantida a opção de limitar a varredura a um evento (usada pela varredura sob demanda) e o `chunkById(100)` |
| `app/Actions/Pagamentos/ConfirmarPagamento.php` | alterado | Anuncia `InscricaoConfirmada` no ponto marcado, fora da transação e apenas na chamada que de fato mudou a situação |
| `app/Events/InscricaoConfirmada.php` | criado | Anúncio interno (inscrição + pagamento). Sem ouvintes — Fase 7 |
| `app/Events/InscricaoExpirada.php` | criado | Anúncio interno. Sem ouvintes — Fase 7 |
| `app/Console/Commands/ExpirarInscricoesVencidas.php` | criado | `inscricoes:expirar-vencidas`, opção `--evento` (id ou código público) |
| `app/Console/Commands/ReconciliarPagamentosPendentes.php` | criado | `pagamentos:reconciliar`, opções `--margem` (15 min) e `--lote` (100). Consulta `getPayment()` server-to-server e aplica o mesmo `ConfirmarPagamento` |
| `routes/console.php` | alterado | Agendamento das duas rotinas, com o porquê da cadência escrito em português |
| `tests/Feature/Pagamentos/PrazoPagamentoTest.php` | criado | 5 testes: prazo = `created_at` + `prazo_pagamento_minutos`; prazo por evento; congelamento do valor; cobrança vence junto com a inscrição; escopo `vencidas()` só depois do prazo |
| `tests/Feature/Pagamentos/ExpiracaoInscricaoTest.php` | criado | 7 testes: devolve vaga do evento e de **cada** atividade; encerra a cobrança; não apaga nada; anuncia uma única vez; **rodar duas vezes não muda nada na segunda**; não toca em quem está no prazo; respeita `--evento` |
| `tests/Feature/Pagamentos/ReconciliacaoTest.php` | criado | 5 testes: pago no provedor **sem webhook** é confirmado pelo comando; rodar duas vezes não conta vaga em dobro; respeita a margem; fecha cobrança que o provedor deu por vencida sem devolver vaga; não confirma quem não pagou |
| `docs/PROGRESS.md` | atualizado | Etapa 10, Fases 0-4 marcadas como concluídas, decisões D-32 a D-35, e "Próximas tarefas" reescrita fase a fase (5 a 9) |
| `docs/IMPLEMENTATION_PLAN.md` | atualizado | Cabeçalho com o estado real, os três ajustes de rumo da Fase 4, a cadência do agendador e a evidência da prova ponta a ponta |

---

## 2. Critérios de qualidade (§5 do plano)

### Idioma e clareza

| Critério | Status | Evidência |
|----------|--------|-----------|
| Nenhuma tabela/coluna de domínio em inglês, nenhuma com acento | ✅ | `inscricoes`, `pagamentos`, `grupos_atividades`, `valor_centavos`, `min_selecoes`… `grep` por acento nas migrations: nada |
| Nenhum Model/Enum/Action/Service/Exception de domínio em inglês | ✅ | `app/Models`, `app/Enums`, `app/Actions` todos em pt-BR; inglês só em `app/Contracts/Payments` e `app/DTOs/Payments`, como o plano exige |
| Tabelas do framework intocadas | ✅ | `users`, `sessions`, `jobs`, `cache` e `created_at`/`updated_at` sem alteração |
| Todo Enum com `rotulo()` | ✅ | `SituacaoEvento`, `SituacaoInscricao`, `SituacaoPagamento`, `MetodoPagamento`, `SituacaoWebhook` |
| Documentação legível por não-programador | ✅ | 7 documentos em pt-BR, frases curtas, Glossário no `PRD.md` |
| Mensagens de validação para o participante | ✅ | `ValidadorSelecaoAtividades` e `StoreInscricaoRequest` |

### Documentação

| Critério | Status | Evidência |
|----------|--------|-----------|
| 7 documentos existem e não se contradizem | ✅ | `ls docs/` → ARCHITECTURE, BUSINESS_RULES, DATABASE, IMPLEMENTATION_PLAN, PAYMENTS, PRD, PROGRESS. Revisão cruzada registrada no PROGRESS (etapa 3) |
| ERD Mermaid reflete as migrations | ✅ | `DATABASE.md`; os 11 diagramas foram renderizados com `mermaid-cli` na etapa 3 |
| `BUSINESS_RULES.md` numera RN-01… e aponta o teste | ✅ | mapeamento regra → teste presente |
| `PAYMENTS.md` com data de consulta e "a validar" | ✅ | só a Efí publica percentuais; as demais ficaram "a validar" (pendência P-06) |
| `PRD.md` justifica CPF e data de nascimento | ✅ | execução de contrato (Pix) + legítimo interesse (deduplicação); idade validada por atividade |
| `PROGRESS.md` atualizado ao final de cada step | ✅ | dez blocos "Concluído", um por etapa |

### Código

| Critério | Status | Evidência |
|----------|--------|-----------|
| Pint sem violações | ✅ | `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}` |
| Validação crítica no backend | ✅ | `ValidadorSelecaoAtividades` + `CHECK` no banco como última linha de defesa; a confirmação nunca vem de parâmetro do navegador |
| Enums para todos os estados | ✅ | nenhuma string mágica de estado — as comparações usam `->value` do Enum |
| Actions single-purpose, controllers finos | ✅ | `__invoke` em todas as Actions; `PaymentWebhookController` grava, responde 200 e despacha o job |
| DTOs `readonly` na fronteira; nenhum Model no gateway | ✅ | 6 DTOs `final readonly`; o contrato só recebe/devolve DTO ou `string` |
| Dinheiro sempre `int` em centavos | ✅ | `valor_centavos`/`valor_estornado_centavos` bigint; `amountCents` int |
| Zero segredo em log; webhook sem dado sensível | ✅ | `semDadoSensivel()` no controller; logs registram só `codigo_publico` e motivo |
| Fake e rotas de simulação inacessíveis em produção | ✅ | `SimulacaoBloqueadaTest` (4 testes): 404 em `production` e 404 com a chave desligada; a rota do webhook continua existindo |
| Nenhuma dependência externa nova | ✅ | PROGRESS: "Nenhuma até o momento além do que o framework já entrega" |

### Testes (Pest) — **177 passando, 521 asserções**

| Critério | Status | Evidência |
|----------|--------|-----------|
| Testes do mapeamento de §4 existem e passam | ✅ | `php artisan test` → 177 passed (521 assertions) |
| Última vaga concedida a exatamente um | ✅ | `ConcorrenciaTest`, `CapacidadeAtividadeTest` |
| Min/max por grupo e grupo obrigatório ausente | ✅ | `SelecaoAtividadesTest` |
| Conflito de horário, inclusive `terminaA == comecaB` permitido | ✅ | `ConflitoAtividadeTest` |
| Conflito explícito nos dois sentidos | ✅ | `ConflitoAtividadeTest` |
| Faixa etária abaixo e acima | ✅ | `SelecaoAtividadesTest` |
| Duplicidade bloqueada; liberada após expiração | ✅ | `InscricaoDuplicadaTest` |
| Idempotência de duplo submit | ✅ | `InscricaoTest` |
| Webhook duplicado ⇒ 1 confirmação | ✅ | `WebhookPagamentoTest` — "processa o mesmo aviso duas vezes e confirma uma vez so" |
| Expiração libera evento **e** cada atividade; 2ª execução não altera nada | ✅ | `ExpiracaoInscricaoTest` — 7 testes; a idempotência compara `situacao`, `expirada_em`, `updated_at` e os quatro contadores antes/depois |
| Varredura sob demanda concede a vaga sem esperar o agendador | ✅ | `InscricaoTest`/`CapacidadeAtividadeTest` (Etapa 8) |
| Reconciliação confirma pagamento sem webhook | ✅ | `ReconciliacaoTest` — "confirma a inscricao de quem pagou mesmo quando o aviso nunca chega", com `WebhookPagamento::count() === 0` antes e depois |
| Concorrência: CAS determinístico **e** N processos paralelos | ✅ | `ConcorrenciaTest` + `tests/Feature/Inscricoes/scripts/disputar-vaga.php` (6 processos reais, conexões próprias) |
| Playwright E2E | n/a | o próprio plano isenta: não há UI nesta entrega |

---

## 3. Verificação

| Command | Result |
|---------|--------|
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `php artisan test tests/Feature/Pagamentos/PrazoPagamentoTest.php` | 5 passed (13 assertions) |
| `php artisan test tests/Feature/Pagamentos/ExpiracaoInscricaoTest.php` | 7 passed (40 assertions) |
| `php artisan test tests/Feature/Pagamentos/ReconciliacaoTest.php` | 5 passed (43 assertions) |
| `php artisan test` (suíte completa) | **177 passed (521 assertions)** — 160 antes desta etapa, nenhuma regressão |
| `CACHE_STORE=array php artisan schedule:list` | `*   * * * *  php artisan inscricoes:expirar-vencidas` · `*/5 * * * *  php artisan pagamentos:reconciliar` |
| `php artisan migrate:fresh --seed --force` (banco de desenvolvimento) | migrations aplicadas + `CidadeSeeder` e `EventoDemoSeeder` |

---

## 4. Prova ponta a ponta (critério "Done" do plano)

Executada **de verdade**, contra o banco de desenvolvimento `gestao_eventos` recém-recriado
(`migrate:fresh --seed`), por script artisan sobre o evento de demonstração. Saída literal:

```
===== ANTES — banco recem-semeado =====
evento  Copa CCC 2026                reservadas=0 confirmadas=0
  atividade #1   Futebol                  reservadas=0 confirmadas=0
  atividade #5   Trilha leve              reservadas=0 confirmadas=0
inscricoes no banco: 0 | pagamentos: 0

===== FLUXO 1 — inscricao valida reserva vagas =====
inscricao 01M0G82PE0TWC4XY20CHM8DSPD situacao=aguardando_pagamento valor_centavos=12000 prazo=2026-08-21 15:49:03
evento  Copa CCC 2026                reservadas=1 confirmadas=0
  atividade #1   Futebol                  reservadas=1 confirmadas=0
  atividade #5   Trilha leve              reservadas=1 confirmadas=0

===== FLUXO 1 — cobranca Pix fake emitida =====
pagamento 01M0G82PF4S2P9QQF6SE3F4M81 gateway=fake externo=fake_01m0g82pf3vr2ecqr226r8jnsb situacao=pendente valor=12000 expira_em=2026-08-21 15:49:03
pix copia e cola: 00020126520014br.gov.bcb.pix0130chave-pix-ficticia@example.com5204000053039865406120.00580...

===== FLUXO 1 — pagamento no provedor + aviso (webhook) entregue na rota publica =====
POST /webhooks/pagamentos -> HTTP 200 {"recebido":true}

===== FLUXO 1 — DEPOIS: inscricao confirmada, reservada -> confirmada =====
inscricao situacao=confirmada confirmada_em=2026-08-20 15:49:03
pagamento situacao=pago pago_em=2026-08-20 15:49:03
evento  Copa CCC 2026                reservadas=0 confirmadas=1
  atividade #1   Futebol                  reservadas=0 confirmadas=1
  atividade #5   Trilha leve              reservadas=0 confirmadas=1

===== FLUXO 2 — segunda inscricao, prazo deixado vencer =====
inscricao 01M0G82PH2TKT4DRA9DZ8B5V50 situacao=aguardando_pagamento prazo=2026-08-20 15:50:03
evento  Copa CCC 2026                reservadas=1 confirmadas=1
  atividade #1   Futebol                  reservadas=1 confirmadas=1
  atividade #5   Trilha leve              reservadas=1 confirmadas=1

===== FLUXO 2 — artisan inscricoes:expirar-vencidas =====
INFO  Inscricoes expiradas nesta execucao: 1.

===== FLUXO 2 — DEPOIS: vagas devolvidas, nada deletado =====
inscricao situacao=expirada expirada_em=2026-08-20 15:49:03
pagamento situacao=expirado
evento  Copa CCC 2026                reservadas=0 confirmadas=1
  atividade #1   Futebol                  reservadas=0 confirmadas=1
  atividade #5   Trilha leve              reservadas=0 confirmadas=1
inscricoes no banco: 2 (confirmada=1, expirada=1)
pagamentos no banco: 2 | vinculos inscricao-atividade: 4

===== FLUXO 2 — rodando o comando de novo (idempotencia) =====
INFO  Nenhuma inscricao vencida: nada a devolver.
evento  Copa CCC 2026                reservadas=0 confirmadas=1
  atividade #1   Futebol                  reservadas=0 confirmadas=1
  atividade #5   Trilha leve              reservadas=0 confirmadas=1
```

Os dois critérios do "Done" estão nessa saída: a inscrição paga foi confirmada com os
contadores migrando de reservada para confirmada, e a inscrição vencida devolveu a vaga do
evento **e a de cada atividade**, com 2 inscrições, 2 pagamentos e 4 vínculos ainda no banco —
**nenhuma linha apagada** — e a segunda execução do comando sem efeito.

O script da prova ficou fora do repositório (é ferramenta de verificação, não código de
produção). O mesmo caminho está coberto por teste automatizado permanente em
`ExpiracaoInscricaoTest` e `WebhookPagamentoTest` ("percorre o ciclo completo pela rota de
simulacao, como um pagamento de verdade").

---

## 5. Desvios e ressalvas

### Desta etapa

1. **A reconciliação também fecha cobrança que o provedor deu por vencida/falha** (decisão
   D-34). O plano só pedia o caminho da confirmação. Fechar a cobrança morta é barato,
   idempotente e não toca em contador de vaga — quem devolve vaga continua sendo apenas a
   expiração da inscrição. Há teste provando que a vaga **não** é devolvida pela reconciliação.
2. **Cadência da reconciliação: a cada cinco minutos**, olhando só cobranças a até quinze
   minutos do vencimento (`--margem`, ajustável). O plano dizia "cadência sensata"; a escolha
   e o motivo estão escritos em `routes/console.php` e no PROGRESS (D-33).
3. **Opção `--evento` no comando de expiração**, não prevista no plano: a Action já aceitava o
   recorte por evento (usado pela varredura sob demanda) e expor isso ajuda na operação.
4. **`withoutOverlapping()` nas duas tarefas** usa o cache configurado. Na máquina do
   desenvolvedor, `php artisan schedule:list` falha com `Class "Redis" not found` porque o PHP
   do host não tem a extensão `redis` — dentro do Sail funciona. Verificado com
   `CACHE_STORE=array`. Não afeta produção nem os testes (`phpunit.xml` já usa `array`).

### Herdadas, ainda válidas

5. **Assinatura de webhook inválida responde 200** (D-18), não 401: não se informa a quem
   forja que ele acertou o endereço. "Rejeitar" significa "sem efeito no domínio", e isso é
   provado em teste.
6. **`PaymentGateway::name()`** foi acrescentado aos seis métodos de §3.5 — sem ele, gravar
   `pagamentos.gateway` obrigaria o domínio a ler `config()`, recriando o acoplamento que o
   contrato existe para evitar.
7. **Estado do provedor simulado em arquivo** (`storage/app/private/pagamentos-simulados`),
   não em cache (D-26), para que o teste de concorrência com processos filhos funcione.

### Pendências abertas (não são bugs — são decisões de produto)

- **P-01/P-06** provedor real e taxas · **P-02** política de reembolso · **P-03** pagamento
  reconhecido depois do prazo (hoje o aviso é registrado como *ignorado*; a mudança, se
  houver, é em um único ponto de `ConfirmarPagamento`) · **P-04** retenção de dados ·
  **P-05** como o participante volta à própria inscrição (é bloqueante para a Fase 5) ·
  **P-07/P-08** ajustes de `.env` na máquina de cada pessoa.

---

## 6. O que o executor da Fase 5 (frontend público) precisa saber

1. **Só existe uma rota de escrita:** `POST /inscricoes` (`InscricaoController@store` +
   `StoreInscricaoRequest`). Não há endpoint de leitura da vitrine nem de acompanhamento da
   inscrição — precisam ser criados.
2. **Resolva a P-05 antes de desenhar telas.** Sem decidir como a pessoa volta à própria
   inscrição (link assinado ou código por e-mail), a página de pagamento não tem endereço.
3. **`chave_idempotencia` é obrigatória e por evento.** O formulário precisa gerar um UUID por
   tentativa de envio e reenviar o mesmo em caso de retentativa — é isso que impede inscrição
   dobrada no duplo clique.
4. **A inscrição nunca fica "paga".** Os estados visíveis são `aguardando_pagamento`,
   `confirmada`, `expirada` e `cancelada`. `SituacaoInscricao::rotulo()` já entrega o texto
   para a tela.
5. **A tela nunca confirma pagamento.** Voltar do Pix não muda nada: a confirmação só vem do
   aviso assinado ou da reconciliação. A página deve consultar a situação, não afirmá-la.
6. **Prazo:** `inscricoes.prazo_pagamento` (mesmo instante de `pagamentos.expira_em`) serve
   para a contagem regressiva. Quando ele passa, o agendador expira em até um minuto — a tela
   precisa lidar com o estado "expirada" aparecendo sozinho.
7. **Regras de seleção** estão em `ValidadorSelecaoAtividades` (RN-03 a RN-08): mínimo/máximo
   por grupo, grupo obrigatório, sobreposição de horário (`terminaA == comecaB` **é
   permitido**), conflitos explícitos e faixa etária por atividade na data da atividade.
   Espelhe no navegador para dar retorno imediato, **nunca** para substituir o servidor.
8. **Vagas restantes** = `capacidade - (vagas_reservadas + vagas_confirmadas)`, com
   `capacidade IS NULL` significando ilimitado. Trate como número que envelhece em segundos:
   a resposta de `VagasEsgotadasException` é o que vale.
9. **Para desenvolver:** `PAYMENT_GATEWAY=fake` e `PAYMENT_FAKE_SIMULATION_ENABLED=true` no
   `.env`; as rotas `dev/pagamentos/{id}/{pagar,expirar,falhar,estornar}` fazem a cobrança
   andar sem nenhum serviço externo. Elas devolvem 404 fora de `local`/`testing`.
10. **Playwright** é obrigatório na Fase 5 (o plano isentou esta entrega por não haver UI):
    caminho feliz, erro de validação, vagas esgotadas e conflito de horário.

---

## Commit

- **Etapa 10:** `6530b2c` — `feat(pagamentos): add deadline expiration, webhook processing and reconciliation`
- **Arquivos:** 12 (5 criados em `app/`, 3 testes criados, 2 alterados em `app/`, `routes/console.php`, `docs/PROGRESS.md`, `docs/IMPLEMENTATION_PLAN.md`)
- **Plano completo:** 8 commits, de `480fc72` a `6530b2c`. Nenhum `git push` executado.
