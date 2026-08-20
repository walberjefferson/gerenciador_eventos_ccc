# Action Plan — Plataforma de Inscrições e Gestão de Eventos (Fases 0→4: documentação + backend)

> **Type:** feature
> **Created:** 2026-08-20
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Senior Software Architect + Senior Backend Engineer especialista em **PHP 8.4 + Laravel 12**, PostgreSQL 18, concorrência transacional, sistemas de pagamento (Pix/webhooks), filas e Domain-Driven Design pragmático (Actions + DTOs + Enums, sem overengineering). Escreve documentação em **linguagem simples**, compreensível por quem não é programador.

**Scope:** Fases 0 a 4 do projeto, e **somente** elas:

| Fase | Conteúdo | Neste plano |
|------|----------|-------------|
| 0 | PRD + documentação técnica + ERD | ✅ |
| 1 | Bootstrap Laravel 12 + Sail + auth admin | ✅ |
| 2 | Domínio de evento (Evento → DiaEvento → GrupoAtividade → Atividade, Cidade, GrupoParticipante) | ✅ |
| 3 | Inscrição + seleção de atividades + regras + capacidade + concorrência | ✅ |
| 4 | Pagamento + contrato PaymentGateway + FakePaymentGateway + prazo/expiração + webhook | ✅ |
| 5 | Frontend público (páginas de evento/inscrição/pagamento) | ❌ plano separado |
| 6 | Administração (dashboard, CRUDs, filtros) | ❌ plano separado |
| 7 | Comunicação (e-mails, lembretes) | ❌ plano separado |
| 8 | Gateway real (Efí / Pagar.me / outro) | ❌ plano separado |
| 9 | Hardening (auditoria, performance, revisão LGPD) | ❌ plano separado |

**Fora de escopo explícito:** nenhuma tela Inertia além das que o starter kit já entrega; nenhum e-mail real (apenas os domain events que os listeners consumirão na Fase 7); credenciamento/check-in; lista de espera; auditoria administrativa; exportações.

**Stack (fixada — não substituir):**
- PHP 8.4 · Laravel 12 · PostgreSQL 18 · Redis · Laravel Queue/Scheduler/Events/Policies/FormRequests
- Vue 3 + TypeScript + Inertia 2 + Tailwind 4 + shadcn-vue (via starter kit oficial; sem desenvolvimento de UI nesta fase)
- Laravel Sail (Docker) para pgsql + redis + mailpit
- Pest 4 para testes · Laravel Pint para formatação

---

## 2. Direct Objective

Produzir a documentação completa da plataforma (`docs/`) e, na sequência, implementar o **núcleo transacional do backend**: domínio de eventos configurável, criação de inscrição com reserva de vaga à prova de overbooking, validação completa das regras de seleção de atividades, e ciclo de vida do pagamento com `FakePaymentGateway`, webhook idempotente e expiração automática que devolve vagas.

Ao final, o fluxo `criar inscrição → reservar vagas → gerar cobrança Pix fake → simular webhook → confirmar inscrição` e o fluxo `não pagar → expirar → liberar vagas` devem funcionar **de ponta a ponta via testes e comandos artisan**, sem nenhuma tela pública.

---

## 3. Minimum Inputs

### 3.0 Idioma do código e da documentação (decisão do dono do produto — regra transversal)

| Camada | Idioma | Exemplo |
|--------|--------|---------|
| Tabelas e colunas | **pt-BR, sem acento nem cedilha** | `inscricoes`, `nome_completo`, `situacao`, `valor_centavos` |
| Models e relacionamentos | **pt-BR** | `app/Models/Inscricao.php`, `$inscricao->atividades` |
| Enums e seus valores gravados no banco | **pt-BR** | `SituacaoInscricao::AguardandoPagamento` → `'aguardando_pagamento'` |
| Actions, Services, Exceptions, Events de domínio | **pt-BR** | `CriarInscricao`, `ValidadorSelecaoAtividades`, `VagasEsgotadasException` |
| Infraestrutura Laravel (pastas e sufixos) | **inglês, convenção do framework** | `app/Http/Controllers/InscricaoController.php` (método `store`), `app/Jobs/`, `app/Providers/` |
| Tabelas do framework e timestamps | **inglês, intocados** | `users`, `sessions`, `jobs`, `cache`, `created_at`, `updated_at` |
| Contrato e DTOs do gateway de pagamento | **inglês** | `PaymentGateway`, `CreatePaymentData` — espelham a fronteira com serviços externos |
| Documentação em `docs/` | **pt-BR, linguagem acessível a leigos** | frases curtas; jargão explicado na primeira ocorrência |
| Nomes dos arquivos de documentação | **inglês** (exigência do briefing) | `PRD.md`, `ARCHITECTURE.md` |

**Motivo do "sem acento" no banco:** o PostgreSQL aceita `descrição`, mas passa a exigir aspas duplas em toda consulta e quebra clientes SQL e ferramentas de migração. Identificador acentuado é dívida técnica silenciosa.

### 3.1 Decisões de negócio já tomadas pelo dono do produto (NÃO reabrir)

| Tema | Decisão |
|------|---------|
| Estado `pago` na inscrição | **Não existe.** "pago" pertence exclusivamente ao domínio `Pagamento` |
| Reserva de vaga | Contador atômico *compare-and-swap*, **não** `lockForUpdate()` na linha do evento |
| Expiração | Scheduler materializa + *varredura sob demanda* quando o CAS falha (detalhado em §3.4) |
| Preço | `inscricoes.valor_centavos` congelado na criação (snapshot) |
| Idempotência de submit | `chave_idempotencia` por evento, com unique no banco |
| Webhook | Persistir cru → responder 200 → processar em Job assíncrono |
| Fallback de webhook | Job de reconciliação consulta o gateway server-to-server |
| Nomenclatura | `grupos_atividades` (atividades de um dia) vs `grupos_participantes` (grupo vinculado à cidade) |
| Tempo | `comeca_em`/`termina_em` como `timestamptz`; app em `America/Sao_Paulo`, banco em UTC |
| Conflitos explícitos | Tabela `conflitos_atividades` com par normalizado (`atividade_a_id < atividade_b_id`) + unique |
| Dados pessoais coletados | nome, e-mail, telefone, cidade, grupo, **data de nascimento**, **CPF** |
| Finalidade do CPF (LGPD) | (a) exigência do gateway Pix para emissão da cobrança — *execução de contrato*; (b) deduplicação de participantes — *legítimo interesse*. Ambas devem constar no PRD |
| Menores de idade | **Sem bloqueio global.** Restrição etária é por atividade: `idade_minima`/`idade_maxima` em `atividades`, validada contra `data_nascimento` na data da atividade |
| Duplicidade | **1 inscrição ativa por e-mail por evento** (`aguardando_pagamento` ou `confirmada`); expirada/cancelada libera nova tentativa. Mesma regra para o CPF |
| Ambiente | Laravel Sail |
| Matriz de gateways | Pesquisar taxas atuais na web, registrando a data da consulta |

### 3.2 Modelo de dados a implementar

Todos os valores monetários são **`bigint` em centavos** com sufixo `_centavos`. Nunca `float`. Todos os timestamps de domínio são `timestamptz`.

**`cidades`** → Model `Cidade`
`id`, `nome`, `uf` char(2), `ativo` bool default true, timestamps · unique(`nome`,`uf`)

**`grupos_participantes`** → Model `GrupoParticipante`
`id`, `cidade_id` FK→cidades, `nome`, `ativo`, timestamps · unique(`cidade_id`,`nome`) · index(`cidade_id`,`ativo`)

**`eventos`** → Model `Evento`
`id`, `codigo_publico` ulid unique, `nome`, `slug` unique, `descricao` text null, `banner_caminho` null, `data_inicio` date, `data_fim` date, `inscricoes_abrem_em`, `inscricoes_fecham_em`, `capacidade` int null (null = ilimitado), `valor_centavos` bigint, `moeda` char(3) default `'BRL'`, `prazo_pagamento_minutos` int default 1440, `situacao` (enum `SituacaoEvento`), `regulamento` text, `versao_termos` string, `contato_email`, `contato_telefone` null, `configuracoes` jsonb default `'{}'`, `vagas_reservadas` int default 0, `vagas_confirmadas` int default 0, timestamps
- CHECK `capacidade IS NULL OR vagas_reservadas + vagas_confirmadas <= capacidade`
- CHECK `vagas_reservadas >= 0 AND vagas_confirmadas >= 0`
- CHECK `data_fim >= data_inicio`, CHECK `inscricoes_fecham_em > inscricoes_abrem_em`

**`dias_evento`** → Model `DiaEvento`
`id`, `evento_id` FK cascade, `nome`, `descricao` null, `data` date, `posicao` smallint, `ativo` bool, timestamps · unique(`evento_id`,`posicao`)

**`grupos_atividades`** → Model `GrupoAtividade`
`id`, `dia_evento_id` FK cascade, `nome`, `descricao` null, `obrigatorio` bool default false, `min_selecoes` smallint default 0, `max_selecoes` smallint null (null = ilimitado), `posicao` smallint, `ativo` bool, timestamps
- CHECK `min_selecoes >= 0`, CHECK `max_selecoes IS NULL OR max_selecoes >= min_selecoes`
- CHECK `NOT obrigatorio OR min_selecoes >= 1`

**`atividades`** → Model `Atividade`
`id`, `grupo_atividade_id` FK cascade, `nome`, `descricao` null, `comeca_em` timestamptz, `termina_em` timestamptz, `capacidade` int null, `idade_minima` smallint null, `idade_maxima` smallint null, `posicao` smallint, `ativo` bool, `configuracoes` jsonb, `vagas_reservadas` int default 0, `vagas_confirmadas` int default 0, timestamps
- CHECK `termina_em > comeca_em`
- CHECK `capacidade IS NULL OR vagas_reservadas + vagas_confirmadas <= capacidade`

**`conflitos_atividades`** → Model `ConflitoAtividade`
`id`, `atividade_a_id`, `atividade_b_id`, `motivo` null, timestamps
- CHECK `atividade_a_id < atividade_b_id` (par normalizado) · unique(`atividade_a_id`,`atividade_b_id`)

**`inscricoes`** → Model `Inscricao`
`id`, `codigo_publico` ulid unique, `evento_id` FK, `grupo_participante_id` FK, `nome_completo`, `email`, `telefone`, `documento` text (**cast `encrypted`**), `documento_hash` char(64) (sha256 com pepper — usado só para unique/dedup), `data_nascimento` date, `situacao` (enum `SituacaoInscricao`), `valor_centavos` bigint (snapshot), `versao_termos` string (snapshot), `termos_aceitos_em`, `chave_idempotencia` uuid, `prazo_pagamento` timestamptz null, `confirmada_em` null, `expirada_em` null, `cancelada_em` null, `motivo_cancelamento` null, timestamps
- unique(`evento_id`,`chave_idempotencia`)
- unique parcial: `(evento_id, lower(email)) WHERE situacao IN ('aguardando_pagamento','confirmada')`
- unique parcial: `(evento_id, documento_hash) WHERE situacao IN ('aguardando_pagamento','confirmada')`
- index(`situacao`,`prazo_pagamento`) — usado pelo comando de expiração

**`inscricoes_atividades`** → Model `InscricaoAtividade`
`id`, `inscricao_id` FK cascade, `atividade_id` FK restrict, timestamps · unique(`inscricao_id`,`atividade_id`)

**`pagamentos`** → Model `Pagamento`
`id`, `codigo_publico` ulid unique, `inscricao_id` FK, `gateway` string, `id_externo` string null, `metodo` (enum `MetodoPagamento`), `valor_centavos` bigint, `situacao` (enum `SituacaoPagamento`), `pix_copia_e_cola` text null, `expira_em` null, `pago_em` null, `cancelado_em` null, `estornado_em` null, `valor_estornado_centavos` null, `metadados` jsonb, timestamps
- unique parcial(`gateway`,`id_externo`) `WHERE id_externo IS NOT NULL`

**`webhooks_pagamento`** → Model `WebhookPagamento`
`id`, `gateway`, `id_evento_externo` null, `tipo_evento` null, `payload` jsonb, `assinatura_valida` bool, `recebido_em`, `processado_em` null, `situacao` (enum `SituacaoWebhook`), `erro` text null, timestamps
- unique parcial(`gateway`,`id_evento_externo`) `WHERE id_evento_externo IS NOT NULL`

**Enums (valores gravados em português):**

| Enum | Valores |
|------|---------|
| `SituacaoEvento` | `rascunho`, `publicado`, `inscricoes_abertas`, `inscricoes_encerradas`, `finalizado`, `cancelado` |
| `SituacaoInscricao` | `aguardando_pagamento`, `confirmada`, `expirada`, `cancelada`, `lista_espera` |
| `SituacaoPagamento` | `pendente`, `pago`, `falhou`, `expirado`, `cancelado`, `estornado` |
| `MetodoPagamento` | `pix`, `cartao_credito` |
| `SituacaoWebhook` | `recebido`, `processado`, `ignorado`, `falhou` |

Todo enum expõe `rotulo(): string` com o texto amigável para exibição, e `SituacaoInscricao` expõe `estaAtiva(): bool` (true para `aguardando_pagamento` e `confirmada`).

**Decisões de modelagem a documentar (divergem do rascunho do briefing — isso é intencional):**
- `TermAcceptance` **não** vira tabela no MVP: `versao_termos` + `termos_aceitos_em` em `inscricoes` bastam. Se surgir mais de um termo por inscrição, promover a tabela e registrar no PROGRESS.
- `Checkin` é **pós-MVP**: apenas documentado no DATABASE.md como tabela planejada (`checkins`: `inscricao_id`, `dia_evento_id`, `feito_em`, `feito_por`, unique(`inscricao_id`,`dia_evento_id`)).
- `logs_auditoria` é da Fase 9 — documentar o schema, não migrar agora.
- Enums PHP nativos com backing `string` para todos os estados de domínio.

### 3.3 Regras de negócio a implementar (Fase 3)

Validadas **obrigatoriamente no backend**, em uma única transação:

1. **Janela de inscrição** — `now()` entre `inscricoes_abrem_em` e `inscricoes_fecham_em`, e `evento.situacao = inscricoes_abertas`.
2. **Cidade × grupo** — `grupo_participante.cidade_id` deve bater com a cidade enviada; ambos `ativo`.
3. **Grupos obrigatórios** — todo `GrupoAtividade` com `obrigatorio = true` do evento precisa de pelo menos `min_selecoes` atividades escolhidas.
4. **Mínimo/máximo por grupo** — a contagem por grupo respeita `min_selecoes` e `max_selecoes`.
5. **Atividades pertencem ao evento** — toda `atividade_id` recebida pertence a um `GrupoAtividade` → `DiaEvento` → do evento em questão, e está `ativo`.
6. **Conflito de horário** — para qualquer par escolhido: `comecaA < terminaB && terminaA > comecaB` → rejeita. Comparação em `timestamptz`, portanto atravessa dias corretamente.
7. **Conflito explícito** — par presente em `conflitos_atividades` (normalizado) → rejeita, exibindo o `motivo`.
8. **Faixa etária** — para cada atividade com `idade_minima`/`idade_maxima`, a idade do participante **na data da atividade** (`comeca_em`) deve estar na faixa.
9. **Capacidade da atividade** — CAS por atividade (§3.4).
10. **Capacidade do evento** — CAS no evento (§3.4).
11. **Duplicidade** — e-mail e CPF sem inscrição ativa no evento; violação de unique é capturada e traduzida em erro de validação amigável (não 500).
12. **Idempotência** — mesma `chave_idempotencia` retorna a inscrição já criada, sem reservar vaga de novo.
13. **Aceite dos termos** — obrigatório; grava `versao_termos` do evento e o instante do aceite.

Toda mensagem de erro dessas regras é escrita **para o participante leigo**: "Você precisa escolher pelo menos 1 modalidade esportiva", não "min_selections constraint violated".

### 3.4 Estratégia de concorrência (o coração deste plano)

Reserva atômica sem lock pessimista, dentro de `DB::transaction()`:

```sql
-- 1) evento primeiro, sempre
UPDATE eventos
   SET vagas_reservadas = vagas_reservadas + 1
 WHERE id = :evento_id
   AND (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas < capacidade);

-- 2) depois cada atividade, ORDENADA POR id ASC (previne deadlock)
UPDATE atividades
   SET vagas_reservadas = vagas_reservadas + 1
 WHERE id = :atividade_id
   AND (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas < capacidade);
```

`affectedRows = 0` ⇒ esgotado. Ordem de aquisição **sempre** evento → atividades em `id` crescente, em todas as operações que tocam contadores.

**Varredura sob demanda (just-in-time):** quando qualquer CAS retorna 0, a Action chama `ExpirarInscricoesVencidas` **para aquele evento**, e faz **uma única retentativa** da transação inteira. Se falhar de novo, é esgotado de verdade. Isso resolve o problema de vaga presa entre execuções do agendador sem introduzir uma segunda fonte de verdade para disponibilidade.

**Transições de contador (todas idempotentes, sempre na mesma ordem):**

| Transição | Efeito |
|-----------|--------|
| criação | `vagas_reservadas += 1` (evento + atividades) |
| confirmação (webhook `pago`) | `vagas_reservadas -= 1`, `vagas_confirmadas += 1` |
| expiração | `vagas_reservadas -= 1` |
| cancelamento de inscrição aguardando pagamento | `vagas_reservadas -= 1` |
| cancelamento de inscrição confirmada | `vagas_confirmadas -= 1` |

Os `CHECK` de capacidade e de não-negatividade no banco são a **última linha de defesa**: se algum caminho de código errar a contabilidade, o banco recusa em vez de permitir overbooking silencioso.

### 3.5 Contrato de pagamento (Fase 4)

Fronteira com serviços externos — **este contrato e seus DTOs ficam em inglês**, por espelharem a API dos gateways:

```php
interface PaymentGateway
{
    public function createPayment(CreatePaymentData $data): PaymentResult;
    public function getPayment(string $externalId): PaymentStatusResult;
    public function cancelPayment(string $externalId): void;
    public function refundPayment(string $externalId, ?int $amountCents = null): RefundResult;
    public function verifyWebhookSignature(WebhookRequestData $request): bool;
    public function parseWebhook(WebhookRequestData $request): WebhookResult;
}
```

`parseWebhook` (e não `handleWebhook`) **apenas traduz** o payload do gateway em um `WebhookResult` neutro — quem altera o domínio é a Action da aplicação. Nenhum Model Eloquent atravessa a fronteira: só DTOs `readonly`.

Resolução via Service Container, lida de `config/payments.php` ← `PAYMENT_GATEWAY` (default `fake`). Nenhuma referência a gateway concreto dentro do domínio.

**`FakePaymentGateway`** deve simular: criar cobrança (payload Pix EMV fictício), pagar, expirar, falhar, reembolsar e emitir webhook. Endpoints de simulação sob middleware que exige `app()->environment(['local','testing'])` **e** `config('payments.fake.simulation_enabled')` — retorna 404 fora disso.

**Fluxo do webhook:** rota pública sem CSRF → valida assinatura → grava `webhooks_pagamento` (unique protege contra reprocesso) → **responde 200** → job `ProcessarWebhookPagamento` → atualiza `Pagamento` → confirma `Inscricao` + ajusta contadores → dispara `InscricaoConfirmada`.

**Reconciliação:** comando `pagamentos:reconciliar` no Scheduler, chama `getPayment()` para pagamentos `pendente` cujo `expira_em` esteja próximo/vencido, e aplica o mesmo caminho de confirmação. Idempotente.

**Expiração:** comando `inscricoes:expirar-vencidas` a cada minuto → `aguardando_pagamento` com `prazo_pagamento < now()` → `expirada` + devolve contadores + cancela o `Pagamento` + dispara `InscricaoExpirada`. Nunca deleta registros. Processa em lotes com `chunkById`.

### 3.6 Arquivos existentes a ler antes de começar

- `Prompt para Claude Code — Plataforma de Inscrições e Gestão de Eventos.md` (raiz) — briefing original completo, **leitura obrigatória integral**. Atenção: o briefing usa nomes em inglês (`registrations`, `payments`); este plano os substitui pelo glossário de §3.0/§3.2 — o glossário **vence** o briefing em qualquer divergência de nomenclatura.
- `.planning/config.json` — idioma do projeto (`pt-BR`)
- Nada mais existe: o diretório está vazio e **não é um repositório git** (o Step 4 inicializa)

---

## 4. Output Format

### Fase 0 — Documentação

| File | Action | Description |
|------|--------|-------------|
| `docs/PRD.md` | create | As 24 seções exigidas pelo §3 do briefing + **seção Glossário** explicando em linguagem simples cada termo do domínio e cada jargão técnico usado |
| `docs/ARCHITECTURE.md` | create | Camadas, Actions/DTOs/Enums, estratégia de concorrência (§3.4), ciclo do webhook, diagramas Mermaid, e a convenção de idioma de §3.0 |
| `docs/DATABASE.md` | create | ERD Mermaid + dicionário de dados (§3.2) + índices/constraints + justificativa de cada decisão |
| `docs/BUSINESS_RULES.md` | create | As 13 regras de §3.3, cada uma com ID (`RN-01`…), gatilho, mensagem exibida ao participante e teste correspondente |
| `docs/PAYMENTS.md` | create | Matriz Efí × Pagar.me × Mercado Pago × Asaas com data da consulta + contrato + fluxos Pix/webhook |
| `docs/IMPLEMENTATION_PLAN.md` | create | Fases 0→9, com 0→4 detalhadas e 5→9 em alto nível |
| `docs/PROGRESS.md` | create | Concluído / Em andamento / Próximas / Decisões / Pendências — atualizado a cada step |

### Fase 1 — Bootstrap

| File | Action | Description |
|------|--------|-------------|
| projeto Laravel 12 na raiz | create | `laravel new` com starter kit Vue (Inertia 2 + TS + Tailwind 4 + shadcn-vue) |
| `docker-compose.yml` (Sail) | create | pgsql 18, redis, mailpit |
| `.env.example` / `.env` | create | pgsql, redis, `PAYMENT_GATEWAY=fake`, `APP_TIMEZONE=America/Sao_Paulo` |
| `config/payments.php` | create | default gateway, config do fake, flags de simulação |
| `.gitignore`, commit inicial | create | repositório git inicializado |

### Fase 2 — Domínio do evento

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*` | create | cidades, grupos_participantes, eventos, dias_evento, grupos_atividades, atividades, conflitos_atividades |
| `app/Enums/SituacaoEvento.php` | create | enum com `rotulo()` |
| `app/Models/{Cidade,GrupoParticipante,Evento,DiaEvento,GrupoAtividade,Atividade,ConflitoAtividade}.php` | create | relações em pt-BR, casts, scopes (`ativos`, `comInscricoesAbertas`) |
| `database/factories/*` | create | factories para todos os models acima |
| `database/seeders/{CidadeSeeder,EventoDemoSeeder}.php` | create | evento CCC de 2 dias: esportes + trilha |
| `tests/Feature/Dominio/EventoTest.php` | create | relações, scopes e constraints do banco |

### Fase 3 — Inscrição

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*` | create | inscricoes, inscricoes_atividades |
| `app/Enums/SituacaoInscricao.php` | create | valores em pt-BR + `rotulo()` + `estaAtiva()` |
| `app/DTOs/Inscricoes/DadosNovaInscricao.php` | create | DTO readonly de entrada |
| `app/Actions/Inscricoes/CriarInscricao.php` | create | orquestra validação + CAS + persistência + evento de domínio |
| `app/Actions/Inscricoes/ReservarVagas.php` | create | CAS de evento e atividades, com ordenação determinística |
| `app/Actions/Inscricoes/LiberarVagas.php` | create | devolução de contadores por transição |
| `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` | create | regras RN-03 a RN-08 |
| `app/Exceptions/Inscricoes/*` | create | `VagasEsgotadasException`, `SelecaoAtividadesInvalidaException`, `InscricaoDuplicadaException` |
| `app/Events/InscricaoCriada.php` | create | domain event (sem listener nesta fase) |
| `app/Http/Requests/StoreInscricaoRequest.php` | create | FormRequest com validação de formato + chave de idempotência + mensagens em linguagem simples |
| `app/Http/Controllers/InscricaoController.php` | create | apenas `store` (JSON); telas ficam na Fase 5 |
| `tests/Feature/Inscricoes/*` | create | InscricaoTest, SelecaoAtividadesTest, CapacidadeAtividadeTest, ConflitoAtividadeTest, InscricaoDuplicadaTest, ConcorrenciaTest |

### Fase 4 — Pagamento

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*` | create | pagamentos, webhooks_pagamento |
| `app/Enums/{SituacaoPagamento,MetodoPagamento,SituacaoWebhook}.php` | create | valores em pt-BR + `rotulo()` |
| `app/Contracts/Payments/PaymentGateway.php` | create | contrato de §3.5 (em inglês) |
| `app/DTOs/Payments/*` | create | CreatePaymentData, PaymentResult, PaymentStatusResult, RefundResult, WebhookRequestData, WebhookResult |
| `app/Services/Payments/Fake/FakePaymentGateway.php` | create | implementação completa de simulação |
| `app/Providers/PaymentServiceProvider.php` | create | binding via `match` em `config('payments.default')` |
| `app/Actions/Pagamentos/{CriarPagamentoDaInscricao,ConfirmarPagamento,CancelarPagamento}.php` | create | transições de estado + contadores |
| `app/Actions/Inscricoes/ExpirarInscricoesVencidas.php` | create | idempotente, com escopo opcional por evento (usado na varredura sob demanda) |
| `app/Jobs/ProcessarWebhookPagamento.php` | create | processamento assíncrono e idempotente |
| `app/Http/Controllers/Webhooks/PaymentWebhookController.php` | create | recebe, valida assinatura, persiste, responde 200 |
| `app/Console/Commands/{ExpirarInscricoesVencidas,ReconciliarPagamentosPendentes}.php` | create | agendados em `routes/console.php` |
| `routes/dev.php` | create | endpoints de simulação do fake, bloqueados fora de local/testing |
| `tests/Feature/Pagamentos/*` | create | PaymentGatewayTest, PrazoPagamentoTest, WebhookPagamentoTest, ExpiracaoInscricaoTest, ReconciliacaoTest |

**Mapeamento com os testes obrigatórios do §39 do briefing** (documentar em `docs/BUSINESS_RULES.md`): `RegistrationTest`→`InscricaoTest`, `ActivitySelectionTest`→`SelecaoAtividadesTest`, `ActivityCapacityTest`→`CapacidadeAtividadeTest`, `ActivityConflictTest`→`ConflitoAtividadeTest`, `PaymentDeadlineTest`→`PrazoPagamentoTest`, `PaymentWebhookTest`→`WebhookPagamentoTest`, `RegistrationExpirationTest`→`ExpiracaoInscricaoTest`, `PaymentGatewayTest`→mantido.

---

## 5. Quality Criteria

### Idioma e clareza
- [ ] Nenhuma tabela ou coluna de domínio em inglês, e **nenhuma** com acento ou cedilha
- [ ] Nenhum Model, Enum, Action, Service ou Exception de domínio em inglês
- [ ] Tabelas do framework (`users`, `sessions`, `jobs`, `cache`) e `created_at`/`updated_at` intocadas
- [ ] Todo Enum tem `rotulo()` com texto amigável em pt-BR
- [ ] Documentação legível por não-programador: frases curtas, jargão explicado na primeira ocorrência, e `PRD.md` com seção Glossário
- [ ] Mensagens de validação escritas para o participante, não para o desenvolvedor

### Documentação
- [ ] Os 7 documentos existem e **não se contradizem** — em especial estados, nomes de tabelas/colunas e regras de capacidade
- [ ] `DATABASE.md` tem ERD Mermaid que reflete exatamente as migrations criadas
- [ ] `BUSINESS_RULES.md` numera cada regra (`RN-01`…) e cada uma aponta para o teste que a cobre
- [ ] `PAYMENTS.md` traz a data da consulta em toda taxa pesquisada; o que não for confirmável fica **"a validar"** — nunca um número inventado
- [ ] `PRD.md` justifica explicitamente a coleta de CPF e de data de nascimento com as finalidades de §3.1
- [ ] `PROGRESS.md` é atualizado ao final de **cada** step

### Código
- [ ] Laravel Pint sem violações (`vendor/bin/pint --test`)
- [ ] Toda validação crítica no backend; nada de regra de negócio confiando no cliente
- [ ] Enums PHP para todos os estados; nenhuma string mágica de estado no código
- [ ] Actions single-purpose (`__invoke` ou `handle`); controllers finos
- [ ] DTOs `readonly` na fronteira de pagamento; **nenhum** Model Eloquent passado ao gateway
- [ ] Valores monetários sempre `int` em centavos — `float` para dinheiro é falha de revisão
- [ ] Zero credencial ou segredo em log; payload de webhook armazenado sem dado sensível desnecessário
- [ ] `FakePaymentGateway` e rotas de simulação **inacessíveis** em produção (teste que prova o 404)
- [ ] Nenhuma dependência externa nova sem justificativa registrada no PROGRESS.md

### Testes (Pest)
- [ ] Todos os testes do mapeamento em §4 existem e passam
- [ ] Capacidade: última vaga é concedida a exatamente um; a seguinte falha com `VagasEsgotadasException`
- [ ] Min/max por grupo: abaixo do mínimo, acima do máximo e grupo obrigatório ausente
- [ ] Conflito de horário: sobreposição parcial, contenção total e limites que se tocam (`terminaA == comecaB` **é permitido**)
- [ ] Conflito explícito: rejeita nos dois sentidos do par
- [ ] Faixa etária: participante abaixo da `idade_minima` e acima da `idade_maxima` na data da atividade
- [ ] Duplicidade: segunda inscrição `aguardando_pagamento` com mesmo e-mail é bloqueada; após `expirada`, é permitida
- [ ] Idempotência: dois POSTs com a mesma `chave_idempotencia` ⇒ 1 inscrição, 1 reserva
- [ ] Webhook duplicado: mesmo `id_evento_externo` processado duas vezes ⇒ 1 confirmação, contadores intactos
- [ ] Expiração: libera vaga do evento **e** de cada atividade; rodar o comando duas vezes não altera nada na segunda
- [ ] Varredura sob demanda: evento lotado só por reservas vencidas concede a vaga sem esperar o agendador
- [ ] Reconciliação: pagamento pago no gateway sem webhook recebido é confirmado pelo comando
- [ ] **Concorrência:** um teste determinístico do CAS (`affectedRows = 0`) **e** um teste com N conexões/processos paralelos disputando a última vaga, provando `vagas_confirmadas + vagas_reservadas <= capacidade`
- [ ] Playwright E2E: **não se aplica a este plano** — não há UI nova. A suíte E2E entra no plano da Fase 5 (frontend público), cobrindo happy path, erro de validação, esgotado e conflito

---

## 6. Ambiguity Handling

**Assumptions made:**
- **Laravel 12 + starter kit Vue** em vez de montar Inertia/Vue/TS/Tailwind manualmente: entrega autenticação administrativa pronta e elimina a maior parte da Fase 1. Se o starter kit não estiver disponível na versão instalada, montar manualmente e registrar no PROGRESS. As tabelas e telas que o starter kit traz em inglês **não** são traduzidas.
- **Sem tabela de aceite de termos** no MVP — dois campos em `inscricoes` cobrem o requisito de §38 do briefing com menos superfície.
- **CPF criptografado + hash separado**: permite unique/dedup sem manter o documento em claro no banco, atendendo ao princípio de segurança da LGPD.
- **`lista_espera` existe no enum mas não é alcançável** nesta fase — o estado é reservado para a lista de espera pós-MVP; documentar no PRD como planejado, não implementado.
- **Sem e-mails nesta fase**: os domain events (`InscricaoCriada`, `InscricaoConfirmada`, `InscricaoExpirada`) são disparados sem listeners, para que a Fase 7 apenas registre os canais sem tocar no domínio.
- **`Cidade`/`GrupoParticipante` são catálogos globais**, não pertencem a um evento — se o negócio exigir cidades por evento, é mudança de escopo e vira decisão do dono do produto.
- **Timezone**: `APP_TIMEZONE=America/Sao_Paulo`, colunas `timestamptz`, banco operando em UTC.

**If unsure during execution:**
- Nome em português sem tradução óbvia → escolher o termo que o **dono do evento usaria em voz alta**, registrar em `docs/PRD.md > Glossário` e seguir.
- Regra de negócio ambígua → implementar a alternativa **mais restritiva** (a que rejeita), documentar em `PROGRESS.md > Pendências` e seguir.
- Detalhe técnico reversível (organização de pasta, formato de mensagem) → decidir, registrar em `PROGRESS.md > Decisões`, **não interromper**.
- Qualquer coisa com impacto financeiro, jurídico ou de dados pessoais além do já decidido em §3.1 → **PARAR e perguntar**.
- Taxa de gateway não confirmável por fonte oficial → escrever **"a validar"**. Inventar valor comercial é falha grave.

---

## 7. Prohibitions

- ❌ **Nunca** criar tabela, coluna, Model ou Enum de domínio em inglês — e **nunca** usar acento ou cedilha em identificador de banco
- ❌ **Nunca** renomear tabelas do framework Laravel nem `created_at`/`updated_at`
- ❌ **Nunca** usar `float`/`double` para dinheiro
- ❌ **Nunca** confiar em parâmetro do frontend para confirmar pagamento, nem tratar redirect de sucesso como pagamento concluído
- ❌ **Nunca** deletar inscrições ou pagamentos — transição de estado, sempre
- ❌ **Nunca** referenciar `FakePaymentGateway`, Efí ou Pagar.me dentro do domínio: só o contrato `PaymentGateway`
- ❌ **Nunca** expor endpoints de simulação fora de `local`/`testing`
- ❌ **Nunca** armazenar dado completo de cartão, CVV ou secret; nem logar payload com dado sensível
- ❌ **Nunca** usar `lockForUpdate()` na linha do evento como mecanismo primário de reserva (decisão arquitetural revertida por §3.4)
- ❌ **Nunca** adquirir locks/updates de contador fora da ordem canônica (evento → atividades por `id` ASC)
- ❌ **Nunca** implementar Fases 5 a 9 neste plano — nem "só uma telinha rápida"
- ❌ **Nunca** criar Repository sem necessidade, abstração especulativa ou camada extra "para o futuro"
- ❌ **Nunca** instalar biblioteca externa sem antes verificar se Laravel/Vue já resolvem
- ❌ **Nunca** dar `git push` sem autorização explícita

---

## Execution Steps

1. **Fase 0.1 — PRD.** Reler o briefing completo na raiz. Escrever `docs/PRD.md` com as 24 seções exigidas + seção **Glossário**, em linguagem acessível a leigos, incorporando todas as decisões de §3.0 e §3.1 (com destaque para as justificativas de finalidade de CPF e data de nascimento, e para a ausência do estado "pago" na inscrição). Criar `docs/PROGRESS.md` inicial.

2. **Fase 0.2 — Arquitetura e dados.** Escrever `docs/ARCHITECTURE.md` (camadas, convenção de idioma, estratégia de concorrência de §3.4 com diagrama Mermaid de sequência, ciclo do webhook, decisão de gateway por config) e `docs/DATABASE.md` (ERD Mermaid + dicionário completo de §3.2 + constraints e índices, cada decisão justificada). Escrever `docs/BUSINESS_RULES.md` com as 13 regras numeradas (`RN-01`…) e o mapeamento de testes de §4.

3. **Fase 0.3 — Pagamentos e plano.** Pesquisar na web as condições atuais de Efí, Pagar.me, Mercado Pago e Asaas; preencher a matriz de `docs/PAYMENTS.md` com data de consulta e "a validar" onde não houver fonte confiável. Escrever `docs/IMPLEMENTATION_PLAN.md`. **Revisar cruzadamente os 6 documentos** procurando contradições — corrigir e registrar as correções no PROGRESS.

4. **Fase 1 — Bootstrap.** `git init` + `laravel new` (Laravel 12, starter kit Vue). Configurar Sail com pgsql 18 + redis + mailpit, `.env`, `APP_TIMEZONE`, Pest, Pint. Criar `config/payments.php`. Subir o ambiente, rodar as migrations do starter kit e confirmar que a autenticação administrativa funciona. Commit inicial.

5. **Fase 2a — Domínio do evento (schema).** Migrations de cidades, grupos_participantes, eventos, dias_evento, grupos_atividades, atividades, conflitos_atividades com **todos** os CHECK e índices de §3.2. Enum `SituacaoEvento`. Models em pt-BR com relações, casts e scopes.

6. **Fase 2b — Dados e verificação.** Factories para todos os models; `CidadeSeeder` + `EventoDemoSeeder` reproduzindo o evento real (2 dias: modalidades esportivas `obrigatorio, min 1, max 2` + trilha `opcional, max 1`). `tests/Feature/Dominio/EventoTest.php` cobrindo relações, scopes e o fato de que as constraints do banco realmente rejeitam capacidade/ordem inválidas.

7. **Fase 3a — Inscrição (núcleo).** Migrations de inscricoes e inscricoes_atividades com as unique parciais. `SituacaoInscricao`. DTO, `ValidadorSelecaoAtividades` (RN-03…RN-08), `ReservarVagas`/`LiberarVagas` (CAS ordenado), `CriarInscricao` com transação, varredura sob demanda + retentativa única, e tradução de violação de unique em erro de validação amigável. FormRequest + controller `store`. Evento `InscricaoCriada`.

8. **Fase 3b — Provar as regras.** Suíte Pest completa da Fase 3, incluindo os limites que se tocam (`terminaA == comecaB` permitido), a duplicidade liberada após expiração, a idempotência de duplo submit e o **teste de concorrência com processos paralelos** disputando a última vaga.

9. **Fase 4a — Pagamento e webhook.** Migrations de pagamentos e webhooks_pagamento. Enums, contrato `PaymentGateway`, DTOs readonly, `FakePaymentGateway` completo, binding no provider via config. Actions de criação/confirmação/cancelamento com ajuste correto de contadores. Controller de webhook (persiste → 200 → job) + `ProcessarWebhookPagamento` idempotente. `routes/dev.php` de simulação, bloqueado fora de local/testing.

10. **Fase 4b — Prazo, expiração e fechamento.** `ExpirarInscricoesVencidas` idempotente com `chunkById`, `ReconciliarPagamentosPendentes`, ambos agendados em `routes/console.php`. Eventos `InscricaoConfirmada`/`InscricaoExpirada`. Suíte Pest da Fase 4 (webhook duplicado, expiração, reconciliação, bloqueio do fake em produção). Rodar `pint`, suíte completa e o fluxo ponta a ponta por artisan. Atualizar `PROGRESS.md` e `IMPLEMENTATION_PLAN.md` com o estado real.

## Done

Os 7 documentos existem, são consistentes entre si, estão escritos em linguagem que um leigo entende e refletem o código; a suíte Pest passa inteira, incluindo os testes de concorrência; o banco inteiro do domínio está em português; e é possível, a partir de um banco recém-semeado, criar uma inscrição válida que reserva vagas, gerar a cobrança Pix fake, simular o webhook e ver a inscrição confirmada — assim como deixar o prazo vencer e ver o agendador devolver a vaga do evento e a de cada atividade, com contadores corretos e sem nenhum registro deletado.

## Commit

Sequência sugerida (um commit por step, escopos coesos):

```
docs: add product requirements and technical documentation
chore: bootstrap laravel 12 with vue starter kit and sail
feat(eventos): add configurable event domain schema and models
feat(eventos): add factories and demo event seeder
feat(inscricoes): add registration workflow with atomic capacity reservation
test(inscricoes): cover selection rules, capacity and concurrency
feat(pagamentos): add payment gateway abstraction and fake pix provider
feat(pagamentos): add deadline expiration, webhook processing and reconciliation
```
