# Action Plan — Mostrar o txid da Efí onde se concilia dinheiro

> **Type:** feature
> **Created:** 2026-08-31
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Engenheiro sênior PHP 8.4 + Laravel 12 + Inertia/Vue 3 + TypeScript,
acostumado a domínio em pt-BR e a comentários que explicam o *porquê*.

**Scope:** Tornar visível, na parte administrativa, o identificador que a Efí
usa para a cobrança (`txid`), hoje guardado em `pagamentos.id_externo` e nunca
exibido. Inclui: ficha da inscrição, tela de avisos do provedor, busca por txid
na listagem de inscrições, e o desfazer da ambiguidade de rótulos que originou
esta demanda. **Fora do escopo:** exportação CSV e qualquer tela do
participante.

**Stack:** Laravel 12, PostgreSQL, Inertia 2 + Vue 3 `<script setup>`,
TypeScript, Tailwind v4, Reka UI, Pest, Playwright.

## 2. Direct Objective

Quem administra deve conseguir pegar um `txid` no painel da Efí e (a) encontrar
a inscrição correspondente pela busca, (b) ver esse mesmo `txid` na ficha da
inscrição e no aviso automático que o confirmou — sem nunca mais confundir o
código interno da cobrança com o identificador da Efí.

## 3. Minimum Inputs

### O problema, em uma frase

`pagamentos.codigo_publico` e o `txid` são **dois ULIDs independentes**, ambos
de 26 caracteres. A tela mostra o primeiro sob o rótulo "Cobrança"; a Efí mostra
o segundo. Parecem a mesma coisa e nunca coincidem — foi exatamente essa
semelhança que gerou a dúvida que originou este plano.

| Campo | Quem gera | Onde vive | Onde aparece hoje |
|---|---|---|---|
| `codigo_publico` | `Pagamento::booted()` (`app/Models/Pagamento.php:45`) | `pagamentos.codigo_publico` | coluna "Cobrança" da ficha do admin |
| `txid` | `EfiPaymentGateway::novoTxid()` (`:337`) | `pagamentos.id_externo` | **em lugar nenhum** |
| `endToEndId` | a Efí | `webhooks_pagamento.id_evento_externo` | coluna "Identificador no provedor" da tela de avisos |

O terceiro é a mesma armadilha na outra tela: o rótulo "Identificador no
provedor" sugere txid e é o identificador **da transferência**.

### Entidades / Dados

- `pagamentos.id_externo` — `string(190)`, nullable, já existe, já indexado por
  índice único parcial `pagamentos_gateway_id_externo_unique (gateway,
  id_externo) WHERE id_externo IS NOT NULL`. **Nada a migrar aqui.**
- `webhooks_pagamento` — **falta** a coluna do txid. Hoje ele só existe dentro
  de `payload->pix[0]->txid`, o que impede ligar aviso ↔ cobrança por consulta.
  Acrescentar `id_externo string(190) nullable` + índice comum
  `(gateway, id_externo)` — comum, não único: um mesmo txid pode gerar mais de
  um aviso legítimo (reenvio com `endToEndId` diferente).

### Regras de negócio

1. **Confirmação manual não tem txid.** `ConfirmarPagamentoManual` grava
   `id_externo = null` de propósito (`:178-181`). Toda tela precisa desenhar o
   vazio como `—`, e a busca precisa ignorar esses registros.
2. **O participante continua sem ver o txid.** `PagamentoHistoricoResource`
   exclui `id_externo` por decisão registrada no próprio arquivo; este plano
   **não** mexe nisso.
3. **Busca por txid é igualdade exata, não `ilike '%…%'`.** O ULID é colado
   inteiro do painel da Efí, e igualdade usa o índice existente. `ilike` com
   curinga nos dois lados faria varredura de tabela na busca mais usada do
   sistema.
4. **Backfill sem perder aviso.** Os avisos já gravados têm o txid no payload;
   a migration deve preenchê-los, e um payload fora do formato esperado apenas
   fica `null` — nunca derruba a migration.

### Arquivos a ler antes de começar

- `app/Models/Pagamento.php` e `app/Models/WebhookPagamento.php`
- `app/Services/Payments/Efi/EfiPaymentGateway.php` (`createPayment`,
  `parseWebhook`, `novoTxid`)
- `app/Http/Controllers/Webhooks/PaymentWebhookController.php` (método `guardar`)
- `app/Http/Controllers/Admin/InscricaoAdminController.php` (`historicoDeCobrancas`)
- `app/Http/Controllers/Admin/AvisosPagamentoController.php` (`index`)
- `app/Services/Admin/FiltroDeInscricoes.php` (`porBusca`, linhas 211-223)
- `database/migrations/2026_08_20_100011_create_webhooks_pagamento_table.php`
- `resources/js/pages/Admin/Inscricoes/Show.vue` (tabela, linhas 155-186)
- `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` (tabela, linhas 236-260)
- `tests/Feature/Admin/FichaDaInscricaoTest.php`,
  `tests/Feature/Admin/ListaInscricoesTest.php`,
  `tests/Feature/Pagamentos/AvisosDoProvedorTest.php`,
  `tests/Feature/Pagamentos/WebhookPagamentoTest.php`

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/{ts}_add_id_externo_to_webhooks_pagamento_table.php` | create | Coluna + índice `(gateway, id_externo)` + backfill a partir de `payload->pix[0]->txid` |
| `app/Http/Controllers/Webhooks/PaymentWebhookController.php` | modify | Gravar `id_externo` com `$resultado->externalId` em `guardar()` |
| `app/Models/WebhookPagamento.php` | modify | `id_externo` no `$fillable` |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | modify | `historicoDeCobrancas()` passa a enviar `id_externo` |
| `app/Http/Controllers/Admin/AvisosPagamentoController.php` | modify | `index()` passa a enviar `id_externo` |
| `app/Services/Admin/FiltroDeInscricoes.php` | modify | `porBusca()` ganha o ramo do txid (igualdade exata) |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modify | Coluna "Código interno" (renomeada) + coluna "txid (Efí)" |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | modify | Coluna "Cobrança na Efí (txid)"; "Identificador no provedor" → "Fim a fim (E2E)" |
| `resources/js/types/admin.ts` | modify | `id_externo: string \| null` em `CobrancaDaFicha` e no tipo do aviso |
| `tests/Feature/Admin/FichaDaInscricaoTest.php` | modify | O txid chega à ficha; manual aparece como vazio |
| `tests/Feature/Admin/ListaInscricoesTest.php` | modify | Busca por txid acha a inscrição; txid inexistente não acha nada |
| `tests/Feature/Pagamentos/WebhookPagamentoTest.php` | modify | O aviso recebido grava `id_externo` |
| `tests/Feature/Pagamentos/AvisosDoProvedorTest.php` | modify | O txid chega à tela de avisos |
| `tests/e2e/conciliacao-por-txid.spec.ts` | create | Colar o txid na busca leva à inscrição; a ficha mostra o mesmo txid |
| `docs/PAYMENTS.md` | modify | Seção curta "Os três identificadores" com a tabela da §3 |

## 5. Quality Criteria

- [ ] A migration roda em base com avisos já gravados e preenche o txid deles;
      payload fora do formato vira `null`, sem exceção.
- [ ] `php artisan migrate:fresh --seed` continua funcionando.
- [ ] Nenhuma consulta nova sem índice: a busca por txid usa igualdade e cai no
      índice `pagamentos_gateway_id_externo_unique`.
- [ ] Cobrança manual (sem txid) desenha `—` nas duas telas, sem quebrar.
- [ ] O participante continua sem receber `id_externo` — `PagamentoHistoricoResource`
      intocado, e o teste que garante isso continua passando.
- [ ] Comentários no padrão do projeto: explicam a decisão, não a sintaxe; em
      pt-BR; domínio em português e infra em inglês.
- [ ] Tests: Pest para os quatro pontos (webhook grava, ficha exibe, avisos
      exibem, busca encontra) + o caso do pagamento manual sem txid.
- [ ] Playwright E2E: buscar pelo txid na listagem → abrir a ficha → conferir
      que o txid mostrado é o mesmo que foi buscado.
- [ ] `./vendor/bin/pint --dirty`, `php artisan test` e `npm run lint` limpos.

## 6. Ambiguity Handling

**Assumptions made:**

- **Coluna própria em `webhooks_pagamento`, e não leitura do payload na hora de
  montar a tela.** Ler do payload sairia mais barato e não permitiria filtrar
  nem ligar aviso ↔ cobrança por consulta — que é justamente o trabalho de
  conciliar. O custo é uma migration simples com backfill.
- **Renomear "Cobrança" para "Código interno".** O rótulo atual é a causa direta
  da confusão que originou este plano; mantê-lo ao lado de uma coluna de txid
  deixaria a tela pior do que está.
- **A tela de avisos ganha exibição, não busca.** Busca por txid foi pedida na
  listagem de inscrições; a coluna nos avisos já resolve a leitura, e a busca
  ali pode vir depois se fizer falta.
- **A exportação CSV fica fora**, conforme a escolha na entrevista — mesmo que
  ela use o mesmo `FiltroDeInscricoes`, e portanto passe a *filtrar* por txid
  sem ganhar coluna nova. Isso é coerente: quem exporta filtrando por um txid
  quer aquela linha, não a coluna.

**If unsure during execution:**

- Se a busca por igualdade exata falhar em teste manual por diferença de caixa
  (ULID é maiúsculo), normalize o termo com `Str::upper()` antes de comparar —
  nunca troque para `ilike '%…%'`.
- Se o backfill encontrar payload de outro provedor (fake), deixe `null` e siga.
- Qualquer dúvida que envolva mostrar `id_externo` ao participante: **pare e
  pergunte**. É decisão de privacidade já tomada em sentido contrário.

## 7. Prohibitions

- ❌ NUNCA expor `id_externo` em `PagamentoHistoricoResource` ou em qualquer
  tela pública.
- ❌ NUNCA transformar a busca por txid em `ilike` com curinga nos dois lados.
- ❌ NUNCA alterar `EfiPaymentGateway::novoTxid()` nem tentar unificar
  `codigo_publico` e `txid` — a separação é deliberada e evita o 409 da segunda
  cobrança (comentário em `EfiPaymentGateway.php:37-42`).
- ❌ NUNCA gravar payload de webhook sem passar por `semDadoSensivel()`.
- ❌ NUNCA mexer em arquivo fora da tabela da §4 (o repositório não está
  formatado pelo Prettier — rodar `npm run format` sujaria 30+ arquivos alheios).

---

## Execution Steps

1. Criar a migration de `webhooks_pagamento.id_externo` (coluna + índice
   `(gateway, id_externo)`) com backfill a partir de `payload->pix[0]->txid`, e
   `down()` que remove índice e coluna.
2. Acrescentar `id_externo` ao `$fillable` de `WebhookPagamento` e gravá-lo em
   `PaymentWebhookController::guardar()` a partir de `$resultado->externalId`.
3. Enviar `id_externo` ao Inertia em `InscricaoAdminController::historicoDeCobrancas()`
   e em `AvisosPagamentoController::index()`; atualizar `resources/js/types/admin.ts`.
4. Acrescentar o ramo do txid em `FiltroDeInscricoes::porBusca()` — `whereExists`
   em `pagamentos` com igualdade em `id_externo` — e atualizar o comentário da
   classe, que hoje afirma que a busca olha só nome, e-mail e código.
5. Ajustar `Admin/Inscricoes/Show.vue`: "Cobrança" → "Código interno" e coluna
   nova "txid (Efí)" em `font-mono`, com `—` quando vazio.
6. Ajustar `Admin/Pagamentos/Avisos/Index.vue`: coluna nova "Cobrança na Efí
   (txid)" e "Identificador no provedor" → "Fim a fim (E2E)".
7. Escrever/ajustar os testes Pest dos quatro pontos, mais o caso do pagamento
   manual sem txid, mais a garantia de que o participante continua sem ver.
8. Escrever o E2E Playwright da conciliação por txid.
9. Acrescentar a seção "Os três identificadores" em `docs/PAYMENTS.md`.
10. Rodar `./vendor/bin/pint --dirty`, `php artisan test`, `npm run lint`,
    `npm run build` e `npm run test:e2e`; corrigir o que acusar.

## Done

Um `txid` copiado do painel da Efí, colado na busca de inscrições, leva à
inscrição certa; a ficha dela e o aviso automático que a confirmou mostram esse
mesmo `txid` sob rótulos que não se confundem com o código interno — e o
participante continua sem ver nada disso.

## Commit

`feat(admin): mostrar o txid da Efi na ficha, nos avisos e na busca`
