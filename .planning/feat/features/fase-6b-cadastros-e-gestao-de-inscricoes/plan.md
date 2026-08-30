# Action Plan — Fase 6b: cadastros do evento, gestão de inscrições e ações administrativas

> **Type:** feature
> **Created:** 2026-08-20
> **Status:** pending
> **Ordem:** segundo plano do lote. **Depende da Fase 6a** (papéis, permissões, `AdminLayout`, grupo de rotas). **A Fase 7 depende deste** — o anúncio `InscricaoCancelada` nasce aqui.

---

## 1. Persona & Scope

**Persona:** Senior Fullstack Engineer **Laravel 12 + PHP 8.4** e **Vue 3.5 + TypeScript strict + Inertia 2 + Tailwind + shadcn-vue**, com prática em CRUD administrativo, filtros compostos sobre PostgreSQL e — crucialmente — em **escrita concorrente sobre contador de vaga**. Sabe que devolver vaga errado é pior do que não devolver.

**Scope — Fase 6b:** o organizador passa a operar o evento sem abrir o banco.

| Entrega | Nesta fase |
|---------|:----------:|
| CRUD de cidades e grupos de participantes | ✅ |
| CRUD de evento, dias, grupos de atividades, atividades e conflitos | ✅ |
| Lista de inscrições com filtros compostos | ✅ |
| Exportação da lista em CSV | ✅ |
| Ficha da inscrição com histórico da cobrança | ✅ |
| Cancelamento administrativo, com motivo obrigatório | ✅ |
| Confirmação manual de pagamento recebido por fora | ✅ |
| Painel de números | ❌ já entregue na **6a** |
| E-mails de aviso dessas ações | ❌ Fase 7 |
| Registro de auditoria | ❌ Fase 9 |

**Stack:** PHP 8.4 · Laravel 12 · Vue 3.5 · TypeScript strict · Inertia 2 · Tailwind · shadcn-vue sobre **`radix-vue`** · Playwright · Pest 4 · PostgreSQL 18.

---

## 2. Direct Objective

Dar ao organizador as duas coisas que faltam para o sistema ser usável de verdade: **cadastrar** o evento inteiro pela tela, e **agir** sobre uma inscrição concreta — achar pelo filtro, abrir a ficha, cancelar com motivo registrado ou reconhecer um pagamento que entrou em dinheiro no dia.

Diferente de todas as fases anteriores desde a 4, **esta cria regra de domínio nova**: duas Actions que mexem em vaga e em dinheiro. Elas são o ponto delicado do plano e recebem o mesmo rigor de teste que `CriarInscricao` recebeu.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas (NÃO reabrir)

| Tema | Decisão |
|------|---------|
| Ações administrativas (**DA-13**) | **Cancelar e confirmar manualmente**, ambas com motivo obrigatório. `pagamentos.confirmar-manual` é exclusiva do `administrador` (definido na 6a) |
| Autorização | Já existe da 6a: papéis `administrador` e `organizador`, permissões `catalogo.gerenciar`, `eventos.gerenciar`, `inscricoes.ver`, `inscricoes.exportar`, `inscricoes.cancelar`, `pagamentos.confirmar-manual` |
| Cancelamento **pelo participante** | Continua **fora de escopo** (D-45). O que nasce aqui é o cancelamento **pelo organizador**, que é outra coisa: tem responsável identificado e motivo registrado |

### 3.2 O que já existe (verificado — não reimplementar)

- Fases 0 a 5b e o plano 6a concluídos. **Leia `docs/PROGRESS.md` para o estado exato no momento em que você começar.**
- `LiberarVagas` (`app/Actions/Inscricoes/LiberarVagas.php`) — **já devolve vaga do evento e de cada atividade**, na ordem canônica. É ela que a Action de cancelamento usa. **Não escreva outra.**
- `ExpirarInscricoesVencidas` — o modelo a seguir: muda situação **condicionalmente à situação anterior**, devolve vaga, encerra a cobrança e anuncia, tudo com a transação certa. **Leia antes de escrever a Action de cancelamento.**
- `CancelarPagamento` (`app/Actions/Pagamentos/`) — encerra cobrança. Reaproveite.
- `ConfirmarPagamento` (`app/Actions/Pagamentos/`) — confirma cobrança e a inscrição. **Leia com atenção**: a confirmação manual precisa chegar ao mesmo estado final, sem fingir que houve aviso de provedor.
- Anúncios de domínio existentes: `InscricaoCriada`, `InscricaoConfirmada`, `InscricaoExpirada` — **todos ainda sem ouvinte** (D-12), e assim continuam nesta fase.
- Enums com `rotulo()` e `SituacaoInscricao::estaAtiva()`.
- Unicidades parciais de e-mail e CPF valem só para situações ativas (D-15) — cancelar **libera** a pessoa para se inscrever de novo. É consequência desejada.

### 3.3 As duas Actions novas — o coração desta fase

**`CancelarInscricaoAdministrativa`**

Recebe a inscrição, o usuário responsável e o motivo. Em transação:
1. Muda a situação para `cancelada` **condicionalmente** — o `UPDATE` só afeta a linha se a situação ainda for `aguardando_pagamento` ou `confirmada`. Zero linhas afetadas → alguém chegou primeiro, e a Action encerra sem efeito, sem exceção de erro.
2. Grava `cancelada_em` e `motivo_cancelamento`.
3. Chama `LiberarVagas` — evento e atividades, na ordem canônica (D-05).
4. Encerra a cobrança aberta, se houver, com `CancelarPagamento`.
5. **Depois** que a transação fecha, anuncia `InscricaoCancelada` (novo evento de domínio, **sem ouvinte** nesta fase — a Fase 7 o consome).

**Cancelar inscrição já confirmada é permitido** (o organizador precisa disso quando alguém desiste), mas **não gera estorno**: reembolso continua sem política definida (P-02) e o dinheiro não se mexe por conta própria. A ficha deixa isso explícito na tela: *"O valor pago não é devolvido automaticamente."*

**`ConfirmarPagamentoManual`**

Recebe a inscrição, o usuário responsável, o método declarado (dinheiro, transferência, outro) e uma observação obrigatória. Em transação:
1. Só age se a inscrição estiver em `aguardando_pagamento` — confirmada já está no destino, expirada ou cancelada **não** ressuscita por aqui.
2. Marca a cobrança aberta como paga, registrando em `metadados` que a origem foi **manual** e quem foi o responsável — nunca fingindo `id_externo` de provedor.
3. Leva a inscrição a `confirmada`, com `confirmada_em`, pelo mesmo caminho que `ConfirmarPagamento` já usa.
4. Anuncia `InscricaoConfirmada` depois da transação — o mesmo anúncio de sempre, porque para o resto do sistema o fato é o mesmo.

**Prazo vencido:** se a inscrição já expirou, a confirmação manual **é recusada** com mensagem clara. A vaga já voltou para a fila e pode ter dono; ressuscitar por cima seria vender duas vezes. Este é justamente o assunto da pendência **P-03**, que continua aberta — a recusa é a escolha segura enquanto ninguém decide.

**Ambas exigem motivo/observação não vazio.** Ação administrativa sem justificativa é rastro que não explica nada — e a Fase 9 vai transformá-los em auditoria.

### 3.4 Os CRUDs

Dois grupos, com pesos diferentes.

**Catálogo global** (`catalogo.gerenciar`) — `cidades` e `grupos_participantes`. São listas simples. Regra que importa: **não apagar** o que já está em uso; a chave estrangeira é `restrict` e a tela precisa explicar isso em português em vez de deixar estourar erro do banco. Ofereça desativar quando o registro tiver a coluna para isso; se não tiver, apenas recuse com explicação.

**Estrutura do evento** (`eventos.gerenciar`) — `eventos`, `dias_evento`, `grupos_atividades`, `atividades`, `conflitos_atividades`. Aqui as restrições do banco são muitas e todas com motivo (leia `docs/DATABASE.md`). Cada FormRequest **espelha** a restrição do banco para que o organizador receba frase amigável antes de o PostgreSQL recusar:

- data final não pode ser antes da inicial;
- capacidade não pode ser negativa, nem menor que o que já está reservado + confirmado;
- par de conflito é normalizado (`atividade_a_id < atividade_b_id`) e não se repete (D-14);
- atividade não conflita consigo mesma;
- mínimo de um grupo não pode ser maior que o máximo.

**Regra de ouro dos cadastros:** mexer na estrutura de um evento **com inscrição ativa** é perigoso. Reduzir capacidade abaixo do já ocupado, apagar atividade escolhida por alguém ou mudar valor do evento não podem passar silenciosamente. Recuse, explique, e ofereça o caminho certo (encerrar inscrições, cancelar inscrições, criar outro evento).

### 3.5 Lista de inscrições, filtros e exportação

Filtros combináveis: evento, situação, cidade, grupo de participantes, atividade escolhida, situação do pagamento e período de criação. Mais busca por nome, e-mail e **código público**.

**CPF não é filtro nem busca** — está cifrado (D-08) e a impressão digital serve só para comparar, não para procurar por pedaço. Se precisar achar alguém pelo CPF, procure pelo hash do CPF completo, nunca por parte dele.

Paginação sempre (padrão 25). Ordenação por criação, decrescente. As colunas mostram nome, e-mail, cidade, grupo, situação, valor e prazo — **nunca CPF**, nem na lista nem no CSV.

**Exportação CSV** (`inscricoes.exportar`): respeita exatamente os filtros aplicados, sai por *streamed response* (`response()->streamDownload()` com cursor), nunca montando o arquivo inteiro na memória. Cabeçalho em português, separador `;` e BOM UTF-8 — é o que faz o Excel brasileiro abrir sem embaralhar acento.

### 3.6 Armadilhas conhecidas deste projeto

- **`radix-vue`, não `reka-ui`.**
- **`php artisan test` roda no HOST** (`phpunit.xml` fixa `127.0.0.1:55432`); dentro do Sail a conexão é recusada e a suíte inteira falha por motivo nenhum.
- **Nunca** rodar Pest e Playwright ao mesmo tempo — dividem o banco `testing` (D-42).
- **`lockForUpdate()` é proibido** neste projeto (ver `BUSINESS_RULES.md`). Concorrência se resolve por gravação condicional, como em `ReservarVagas`/`LiberarVagas`.
- **Ordem canônica de vaga é evento → atividades por id crescente** (D-05). Inverter é criar impasse entre transações.
- **Anúncio só depois da transação fechar, e só na chamada que de fato mudou a situação** (D-32).
- **Executores morrem em ~60 chamadas de ferramenta.** Commite ao fim de cada step; pare em commit limpo e escreva o `plan.done.md`.

### 3.7 Arquivos a ler antes de começar

- `app/Actions/Inscricoes/ExpirarInscricoesVencidas.php` — **o molde** de mudança condicional + devolução de vaga + anúncio
- `app/Actions/Inscricoes/LiberarVagas.php` — a devolução de vaga que você **não** vai reescrever
- `app/Actions/Pagamentos/{ConfirmarPagamento,CancelarPagamento}.php`
- `tests/Feature/Inscricoes/ConcorrenciaTest.php` e `Cenario.php` — o padrão de teste de concorrência a seguir
- `docs/DATABASE.md` — todas as restrições que os FormRequests precisam espelhar
- `docs/BUSINESS_RULES.md` — RN-01 a RN-13 e as regras de pagamento
- `docs/PROGRESS.md` — decisões e pendências, atualizadas pela 6a

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `app/Actions/Inscricoes/CancelarInscricaoAdministrativa.php` | create | §3.3 |
| `app/Actions/Pagamentos/ConfirmarPagamentoManual.php` | create | §3.3 |
| `app/Events/InscricaoCancelada.php` | create | anúncio novo, sem ouvinte nesta fase |
| `app/Http/Controllers/Admin/{CidadeController,GrupoParticipanteController}.php` | create | catálogo global |
| `app/Http/Controllers/Admin/{EventoController,DiaEventoController,GrupoAtividadeController,AtividadeController,ConflitoAtividadeController}.php` | create | estrutura do evento |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | create | lista com filtros + ficha |
| `app/Http/Controllers/Admin/ExportarInscricoesController.php` | create | CSV em streaming |
| `app/Http/Controllers/Admin/AcaoInscricaoController.php` | create | cancelar + confirmar manualmente |
| `app/Http/Requests/Admin/*.php` | create | um FormRequest por recurso, espelhando as restrições do banco |
| `app/Http/Resources/Admin/*.php` | create | props sem CPF nem hash |
| `app/Services/Admin/FiltroDeInscricoes.php` | create | monta a consulta filtrada, reaproveitada pela lista e pelo CSV |
| `app/Policies/*.php` | create | Policies por recurso, amarradas às permissões da 6a |
| `routes/web.php` | modify | rotas administrativas dos recursos |
| `resources/js/pages/Admin/Catalogo/*.vue` | create | cidades e grupos de participantes |
| `resources/js/pages/Admin/Eventos/*.vue` | create | evento, dias, grupos, atividades, conflitos |
| `resources/js/pages/Admin/Inscricoes/{Index,Show}.vue` | create | lista com filtros e ficha |
| `resources/js/components/admin/{FiltrosDeInscricao,TabelaDeInscricoes,DialogoDeAcao}.vue` | create | filtros, tabela e o diálogo de motivo obrigatório |
| `resources/js/types/admin.ts` | create | tipos dos props |
| `tests/Feature/Admin/CancelamentoAdministrativoTest.php` | create | devolve vaga, exige motivo, é idempotente, concorrência |
| `tests/Feature/Admin/ConfirmacaoManualTest.php` | create | confirma, recusa expirada, registra origem manual, exige observação |
| `tests/Feature/Admin/CrudEventoTest.php` | create | restrições espelhadas, recusa apagar em uso |
| `tests/Feature/Admin/ListaInscricoesTest.php` | create | filtros combinados, sem CPF nos props |
| `tests/Feature/Admin/ExportacaoTest.php` | create | CSV respeita filtro, não traz CPF, abre com acento correto |
| `tests/e2e/admin-inscricoes.spec.ts` | create | filtrar, abrir ficha, cancelar com motivo |
| `docs/PROGRESS.md`, `docs/IMPLEMENTATION_PLAN.md` | modify | fechamento da fase |

---

## 5. Quality Criteria

### Funcional — as Actions (o que mais importa)
- [ ] Cancelamento devolve a vaga do evento **e** a de cada atividade, e os contadores voltam ao que eram antes da inscrição
- [ ] Cancelamento **exige motivo**; sem motivo, recusa com erro de validação
- [ ] Cancelar duas vezes a mesma inscrição não devolve vaga duas vezes (gravação condicional)
- [ ] Cancelamento concorrente com a rotina de expiração **não** devolve vaga em dobro — provado por teste de concorrência real, no molde de `ConcorrenciaTest`
- [ ] Cancelar inscrição confirmada é permitido e **não** gera estorno; a tela avisa
- [ ] Confirmação manual leva a inscrição a `confirmada` e a cobrança a paga, com origem **manual** registrada em `metadados` e sem `id_externo` forjado
- [ ] Confirmação manual de inscrição **expirada** é recusada, com mensagem em português
- [ ] Confirmação manual exige observação não vazia
- [ ] `organizador` recebe **403** ao tentar confirmar manualmente; `administrador` consegue
- [ ] `InscricaoCancelada` é anunciado **depois** da transação e **só** na chamada que mudou a situação
- [ ] Nenhum ouvinte é registrado para ele nesta fase

### Funcional — cadastros e lista
- [ ] Cada restrição de `DATABASE.md` tem mensagem amigável antes de o banco recusar
- [ ] Apagar registro em uso é recusado com explicação, não com erro de banco na tela
- [ ] Reduzir capacidade abaixo do já ocupado é recusado
- [ ] Par de conflito é normalizado e duplicata é recusada
- [ ] Filtros combinam entre si e a paginação preserva os filtros
- [ ] Busca acha por nome, e-mail e código público — **nunca** por pedaço de CPF
- [ ] CSV respeita os filtros, sai em streaming e abre no Excel com acento correto

### Qualidade
- [ ] `vendor/bin/pint --test` limpo · `npm run lint` limpo · `vue-tsc --noEmit` com **zero** erros
- [ ] Toda a suíte Pest continua verde
- [ ] Os cenários Playwright anteriores continuam verdes, **sem serem editados**
- [ ] **Nenhum** `lockForUpdate()` em lugar nenhum
- [ ] Nenhum `documento` ou `documento_hash` nos props do Inertia nem no CSV
- [ ] Ordem canônica de vaga respeitada (evento → atividades por id crescente)
- [ ] Cor só via token semântico; nenhuma dependência nova

### Acessibilidade
- [ ] Tabelas com `<th scope>` e cabeçalho associado
- [ ] O diálogo de ação prende o foco, fecha com `Esc` e devolve o foco ao botão de origem
- [ ] Erro de formulário ligado por `aria-describedby` e anunciado com `role="alert"`
- [ ] Contraste AA nos dois modos; navegação completa por teclado

### Playwright E2E
- [ ] Filtrar a lista, abrir a ficha e cancelar com motivo, vendo a vaga voltar
- [ ] `organizador` não enxerga o botão de confirmação manual
- [ ] Tentativa de cancelar sem motivo é barrada na tela

---

## 6. Ambiguity Handling

**Assumptions made:**
- **Cancelamento de inscrição confirmada não estorna.** Reembolso depende de política que não existe (P-02). Devolver dinheiro por conta própria seria decisão financeira tomada por software.
- **Confirmação manual não ressuscita inscrição expirada.** A vaga já voltou para a fila e pode ter dono novo. É a leitura segura de P-03 enquanto ela não for decidida.
- **A confirmação manual reaproveita `InscricaoConfirmada`**, em vez de criar anúncio próprio: para o resto do sistema o fato é o mesmo — a pessoa está confirmada. A origem fica registrada no pagamento, que é onde ela importa.
- **Motivo e observação são texto livre obrigatório**, não lista fechada. Ninguém acerta a lista de motivos antes de ver os casos reais; a Fase 9 pode fechar a lista depois de ler o que foi escrito.
- **CSV com `;` e BOM UTF-8** — é o que abre corretamente no Excel em português. Vírgula pura embaralha.
- **Os CRUDs não têm exclusão em cascata.** Tudo é `restrict` no banco por decisão anterior; a tela explica em vez de forçar.

**If unsure during execution:**
- Dúvida sobre restrição de banco → **leia a migration e `DATABASE.md`**; nunca deduza.
- Dúvida sobre concorrência → siga `ExpirarInscricoesVencidas` linha a linha. Se a sua Action não se parece com ela, provavelmente está errada.
- Ação administrativa que exigiria apagar linha de inscrição ou pagamento → **PARE**. Nada é apagado neste sistema.
- Uma regra de cadastro que exigiria mudar migration existente → **PARE e pergunte**. Alterar esquema com dado de inscrição dentro não é decisão de execução.

---

## 7. Prohibitions

- ❌ **Nunca** usar `lockForUpdate()` — a concorrência aqui é por gravação condicional
- ❌ **Nunca** reescrever `LiberarVagas`, `ReservarVagas`, `ConfirmarPagamento` ou `CancelarPagamento` — reaproveite
- ❌ **Nunca** apagar linha de `inscricoes`, `pagamentos` ou `inscricoes_atividades`
- ❌ **Nunca** devolver vaga fora da ordem canônica
- ❌ **Nunca** anunciar evento de domínio dentro da transação
- ❌ **Nunca** registrar ouvinte para anúncio nenhum (Fase 7)
- ❌ **Nunca** forjar `id_externo` de provedor na confirmação manual
- ❌ **Nunca** permitir confirmação manual sem a permissão `pagamentos.confirmar-manual`
- ❌ **Nunca** expor `documento` ou `documento_hash` em props, tela ou CSV
- ❌ **Nunca** permitir busca por pedaço de CPF
- ❌ **Nunca** montar o CSV inteiro em memória
- ❌ **Nunca** criar tabela de auditoria (Fase 9)
- ❌ **Nunca** editar os cenários Playwright existentes
- ❌ **Nunca** dar `git push` sem autorização explícita

---

## Execution Steps

1. **Cancelamento administrativo.** `InscricaoCancelada`, `CancelarInscricaoAdministrativa` no molde de `ExpirarInscricoesVencidas`, e `tests/Feature/Admin/CancelamentoAdministrativoTest.php`: devolve vaga do evento e de cada atividade, exige motivo, cancelar duas vezes não duplica devolução, corrida com a expiração não devolve em dobro, confirmada pode ser cancelada sem estorno. → commit `feat(admin): add administrative registration cancellation`

2. **Confirmação manual de pagamento.** `ConfirmarPagamentoManual` e `ConfirmacaoManualTest`: confirma quem está aguardando, recusa expirada e cancelada, registra origem manual e responsável em `metadados`, exige observação, `organizador` recebe 403. → commit `feat(admin): add manual payment confirmation`

3. **Catálogo global.** CRUD de cidades e grupos de participantes, com FormRequests, Policies e a recusa amigável de apagar registro em uso. → commit `feat(admin): add city and participant group management`

4. **Estrutura do evento.** CRUD de evento, dias, grupos de atividades, atividades e conflitos, com **todas** as restrições de `DATABASE.md` espelhadas em FormRequest, mais as travas de §3.4 para evento com inscrição ativa. `CrudEventoTest`. → commit `feat(admin): add event structure management`

5. **Lista de inscrições.** `FiltroDeInscricoes`, `InscricaoAdminController@index`, filtros combináveis, busca sem CPF, paginação que preserva filtro, `ListaInscricoesTest`. → commit `feat(admin): add registration list with filters`

6. **Ficha e ações na tela.** `InscricaoAdminController@show` com histórico da cobrança, `AcaoInscricaoController`, `DialogoDeAcao` com motivo obrigatório e o aviso de que valor pago não volta sozinho. → commit `feat(admin): add registration detail and actions`

7. **Exportação CSV.** `ExportarInscricoesController` em streaming, respeitando os filtros, com `;` e BOM, sem CPF. `ExportacaoTest`. → commit `feat(admin): add registration csv export`

8. **Playwright e fechamento.** `tests/e2e/admin-inscricoes.spec.ts` (filtrar, abrir ficha, cancelar com motivo, `organizador` sem o botão de confirmação manual). `pint`, `lint`, `vue-tsc`, Pest e Playwright completos. Atualizar `docs/PROGRESS.md` (Etapa 14, **Fase 6 concluída**, decisão DA-13 promovida, P-02 e P-03 seguem abertas) e `docs/IMPLEMENTATION_PLAN.md`. → commit `feat(admin): polish registration management and close phase 6b`

## Done

O organizador cadastra o evento inteiro pela tela, acha qualquer inscrição por filtro ou busca, abre a ficha com o histórico da cobrança e age: cancela com motivo registrado — e a vaga volta na hora — ou, sendo administrador, reconhece um pagamento que entrou em dinheiro. As duas ações que mexem em vaga e em dinheiro têm teste de concorrência real provando que não contam errado, e o anúncio `InscricaoCancelada` já sai, esperando a Fase 7 escutá-lo.

## Commit

```
feat(admin): add administrative registration cancellation
feat(admin): add manual payment confirmation
feat(admin): add city and participant group management
feat(admin): add event structure management
feat(admin): add registration list with filters
feat(admin): add registration detail and actions
feat(admin): add registration csv export
feat(admin): polish registration management and close phase 6b
```
