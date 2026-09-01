# Execution Report — Mostrar o txid da Efí onde se concilia dinheiro

> **Plan:** txid-visivel-na-conciliacao
> **Executed:** 2026-08-31
> **Status:** ✅ COMPLETE

## O que foi feito

| Arquivo | Ação | Descrição |
|---|---|---|
| `database/migrations/2026_08_31_100001_add_id_externo_to_webhooks_pagamento_table.php` | create | Coluna `id_externo` (190, nullable), índice comum `(gateway, id_externo)` e backfill a partir de `payload->'pix'->0->>'txid'`. `down()` remove índice e coluna. |
| `app/Models/WebhookPagamento.php` | modify | `id_externo` no `$fillable` + docblock explicando que `id_evento_externo` (o aviso / fim a fim) e `id_externo` (a cobrança / txid) **não** são a mesma coisa. |
| `app/Http/Controllers/Webhooks/PaymentWebhookController.php` | modify | `guardar()` grava `id_externo` a partir de `$resultado->externalId`. O ramo da assinatura inválida continua sem gravá-lo, de propósito. |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | modify | `historicoDeCobrancas()` envia `id_externo` ao Inertia. |
| `app/Http/Controllers/Admin/AvisosPagamentoController.php` | modify | `index()` envia `id_externo` ao Inertia. |
| `app/Services/Admin/FiltroDeInscricoes.php` | modify | `porBusca()` ganha `orWhereHas('pagamentos', …->where('id_externo', $busca))` — igualdade exata (vira `where exists`). Docblock reescrito: dizia que a busca olhava só nome, e-mail e código. |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modify | "Cobrança" → "Código interno"; coluna nova "txid (Efí)" em `font-mono`, `—` quando vazio, com `data-testid="cobranca-txid-{id}"`. |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | modify | Coluna nova "Cobrança na Efí (txid)"; "Identificador no provedor" → "Fim a fim (E2E)". `colspan` do payload passou de 9 para 10. |
| `resources/js/types/admin.ts` | modify | `id_externo: string \| null` em `CobrancaDaFicha` e em `AvisoDoProvedor`, com o comentário que separa os três identificadores. |
| `tests/Feature/Admin/FichaDaInscricaoTest.php` | modify | +2 testes: o txid chega à ficha (e é diferente do `codigo_publico`); cobrança reconhecida na mão chega com `id_externo` nulo. |
| `tests/Feature/Admin/ListaInscricoesTest.php` | modify | +3 testes: acha pelo txid colado; txid inexistente não acha nada; pedaço de txid **não** acha (a igualdade é a decisão). |
| `tests/Feature/Pagamentos/WebhookPagamentoTest.php` | modify | +1 teste: o aviso recebido grava `id_externo`, e ele difere de `id_evento_externo`. |
| `tests/Feature/Pagamentos/AvisosDoProvedorTest.php` | modify | +2 testes: os dois identificadores chegam à tela, cada um no seu lugar; aviso sem txid chega nulo. O helper `avisoRecebido()` passou a gravar `id_externo`. |
| `tests/e2e/conciliacao-por-txid.spec.ts` | create | 2 cenários Playwright: colar o txid na busca leva à inscrição e a ficha mostra o mesmo txid (e o código interno é outro); txid inexistente devolve lista vazia com a mensagem escrita. |
| `docs/PAYMENTS.md` | modify | Nova seção **9.3 Os três identificadores** com a tabela e as três consequências práticas. As seções seguintes foram renumeradas (9.3→9.4, 9.4→9.5, 9.5→9.6). |

## Critérios de qualidade

| Critério | Status | Evidência |
|---|---|---|
| Migration roda em base com avisos gravados e preenche o txid deles; payload fora do formato vira `null`, sem exceção | ✅ | Provado contra o Postgres real: rollback da migration, 4 avisos inseridos à mão (Efí válido, payload do provedor simulado, `pix` como string e `pix[0]` sem txid), `migrate` novamente. Resultado: `efi/E111 → 01JBACKFILLDAEFI000000001`; os outros três → `NULL`. Nenhuma exceção. |
| `php artisan migrate:fresh --seed` continua funcionando | ✅ | `migrate:fresh --seed --force` no banco `testing`: todas as migrations DONE, `PapeisSeeder`, `CidadeSeeder` e `EventoDemoSeeder` DONE. |
| Nenhuma consulta nova sem índice | ✅ | `explain select 1 from pagamentos where id_externo = '…'` → `Index Only Scan using pagamentos_gateway_id_externo_unique`, `Index Cond: (id_externo = '01JTESTE'::text)`. (`enable_seqscan = off` para forçar a mão do planejador numa tabela vazia — sem isso ele varreria por ser mais barato em 0 linhas, o que não diz nada sobre produção.) Índices de `webhooks_pagamento` conferidos: `webhooks_pagamento_gateway_id_externo_index` criado, `..._evento_externo_unique` intacto. |
| Cobrança manual (sem txid) desenha `—` nas duas telas, sem quebrar | ✅ | Pest: "mostra a cobranca reconhecida na mao sem identificador de provedor" e "nao inventa identificador de cobranca quando o aviso nao trouxe nenhum". Nas telas, `{{ … ?? '—' }}`. |
| O participante continua sem receber `id_externo` | ✅ | `PagamentoHistoricoResource` intocado (não está no diff). `tests/Feature/Participante/AcompanhamentoTest.php`, que garante isso, continua passando na suíte completa. |
| Comentários no padrão do projeto | ✅ | PHP sem acentuação e explicando a decisão, como os arquivos vizinhos do domínio de pagamentos; Vue, TS e docs com acentuação, como os arquivos vizinhos. |
| Tests: Pest para os quatro pontos + o manual sem txid | ✅ | 8 testes novos, todos verdes (85 asserções). |
| Playwright E2E da conciliação por txid | ✅ | 2 passed (6.7s). |
| `pint --dirty`, `php artisan test` e `npm run lint` limpos | ✅ | Ver tabela abaixo. |

## Verificação

| Comando | Resultado |
|---|---|
| `./vendor/bin/pint --dirty` | `{"tool":"pint","result":"passed"}` |
| `php artisan migrate` (banco `testing`, porta 55432) | `2026_08_31_100001_add_id_externo_to_webhooks_pagamento_table .. 17.22ms DONE` |
| `php artisan migrate:rollback --step=1` | `DONE` (19.08ms) — o `down()` funciona |
| `php artisan migrate:fresh --seed --force` | todas DONE + seeders DONE |
| `php artisan test` | **622 passed** (4398 asserções), 68.40s |
| `php artisan test --filter=` (os 8 novos) | **8 passed** (85 asserções) |
| `npm run lint` | limpo, sem saída; e não tocou em arquivo nenhum (`git status` idêntico antes e depois) |
| `npm run build` | `✓ built in 1.72s` |
| `vue-tsc --noEmit` | limpo |
| `tsc --noEmit` + `eslint` no spec E2E novo | limpos (o `tsconfig.json` do projeto não inclui `tests/e2e`, então o spec foi checado à parte) |
| `npx playwright test tests/e2e/conciliacao-por-txid.spec.ts` | **2 passed** (6.7s) |
| `npx playwright test` (suíte completa) | **71 passed, 4 failed** — as 4 falhas são anteriores a este trabalho (ver abaixo) |

### As 4 falhas de Playwright são pré-existentes

Não foram causadas por este plano, e isso foi **medido**, não deduzido: com as
minhas mudanças guardadas no stash e os assets reconstruídos a partir do HEAD,
`npx playwright test admin-avisos-pagamento.spec.ts admin-usuarios.spec.ts`
falha exatamente nos mesmos 4 cenários.

- **3 em `admin-avisos-pagamento.spec.ts`** — `getByRole('link', { name: 'Avisos do provedor' })` casa com 2 elementos (o item da barra lateral e o botão "Ver os avisos do provedor" do painel, que entrou no commit `e90cb09`). É violação de strict mode no clique do menu, antes de a tabela sequer ser desenhada.
- **1 em `admin-usuarios.spec.ts`** — a própria linha do usuário tem 1 botão onde o cenário espera 0 (o commit `0efef64` acrescentou a ação de editar).

Ambos os arquivos estão fora da tabela da §4 do plano, e a §7 proíbe tocá-los.
Ficam registrados aqui.

## Desvios do plano

- **`webhooks_pagamento.id_externo` no ramo da assinatura inválida:** o plano manda gravar o txid em `guardar()`, e foi só ali que gravei. O aviso de assinatura inválida continua nascendo sem `id_externo`, como já nascia sem `id_evento_externo` — preencher a coluna faria um aviso forjado aparecer, por consulta, atrelado a uma cobrança de verdade.
- **`docs/PAYMENTS.md` renumerado:** a seção nova entrou como 9.3 e empurrou as antigas 9.3–9.5 para 9.4–9.6. Só o arquivo do plano foi tocado; não há referência a esses números em nenhum outro arquivo (conferido com grep).
- **CPF do E2E corrigido durante a execução:** o primeiro CPF fictício que escrevi para a segunda pessoa (`60620630018`) tinha dígito verificador errado e o formulário — corretamente — recusava. Passou a ser `60620630019`. Foi o único ajuste que a verificação exigiu.
- **`php artisan migrate` no banco de desenvolvimento não roda neste ambiente** (`password authentication failed for user "sail"` na porta 5432). Toda a prova de migration e backfill foi feita contra o Postgres real da porta 55432 (banco `testing`), que é o mesmo que o Pest e o Playwright usam.
- **`php artisan test` de um arquivo isolado** (`WebhookPagamentoTest.php`) falha com `Call to undefined function cobrancaDeTeste()` — a função mora em `PaymentGatewayTest.php`. Dependência entre arquivos anterior a este trabalho; a suíte completa passa. Documentado, não corrigido.

## Commit

- **Hash:** `b4e0520`
- **Mensagem:** `feat(admin): mostrar o txid da Efi na ficha, nos avisos e na busca`
- **Arquivos:** os 15 da tabela da §4, e apenas eles (15 files changed, 414 insertions, 12 deletions).
