# Execution Report — Fase 6b: cadastros do evento, gestão de inscrições e ações administrativas

> **Plan:** fase-6b-cadastros-e-gestao-de-inscricoes
> **Executed:** 2026-08-20 → 2026-08-21 (cinco execuções; ver "Histórico da execução")
> **Status:** ✅ COMPLETE

## What Was Done

Os oito passos do plano foram concluídos. Cada passo virou um commit, na ordem do plano.

| Passo | Commit | Descrição |
|-------|--------|-----------|
| 1 | `6e1a8ed` | `feat(admin): add administrative registration cancellation` |
| 2 | `e3ef635` | `feat(admin): add manual payment confirmation` |
| 3 | `bb8cac8` | `feat(admin): add city and participant group management` |
| 4 | `59f622f` | `feat(admin): add event structure management` |
| 5 | `40f0910` | `feat(admin): add registration list with filters` |
| 6 | `952a9b8` | `feat(admin): add registration detail and actions` |
| 7 | `35f6028` | `feat(admin): add registration csv export` |
| 8 | este commit — `feat(admin): polish registration management and close phase 6b` | Playwright e fechamento da fase |

### Passo 1 — Cancelamento administrativo (`6e1a8ed`)

| File | Action | Description |
|------|--------|-------------|
| `app/Events/InscricaoCancelada.php` | create | Anúncio novo, com inscrição, motivo, responsável e se estava confirmada. **Sem ouvinte** — a Fase 7 o consome |
| `app/Actions/Inscricoes/CancelarInscricaoAdministrativa.php` | create | Molde de `ExpirarInscricoesVencidas`: gravação condicional, `LiberarVagas` na ordem canônica, `CancelarPagamento` nas cobranças pendentes, anúncio depois do commit |
| `tests/Feature/Admin/CancelamentoAdministrativoTest.php` | create | Devolve vaga do evento e de cada atividade, exige motivo, é idempotente, corrida com a expiração |
| `tests/Feature/Admin/scripts/cancelar-ou-expirar.php` | create | Script de concorrência real, no molde de `scripts/disputar-vaga.php` |

### Passo 2 — Confirmação manual de pagamento (`e3ef635`)

| File | Action | Description |
|------|--------|-------------|
| `app/Actions/Pagamentos/ConfirmarPagamentoManual.php` | create | Delega para `ConfirmarPagamento`; grava origem manual, método declarado, observação e responsável em `metadados`; nunca forja `id_externo` |
| `app/Exceptions/Pagamentos/ConfirmacaoManualRecusadaException.php` | create | A recusa em português de inscrição expirada, cancelada ou fora de `aguardando_pagamento` |
| `app/Enums/MetodoPagamento.php` | modify | Casos `Dinheiro`, `Transferencia` e `Outro`, mais `manuais()`, `ehManual()` e `valoresManuais()` |
| `tests/Feature/Admin/ConfirmacaoManualTest.php` | create | Confirma quem aguarda, recusa expirada e cancelada, registra origem manual, exige observação, `organizador` recebe 403 |

### Passo 3 — Catálogo global (`bb8cac8`)

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Admin/{CidadeController,GrupoParticipanteController}.php` | create | CRUD com recusa amigável de apagar registro em uso |
| `app/Http/Requests/Admin/{CidadeRequest,GrupoParticipanteRequest}.php` | create | Restrições do banco espelhadas |
| `app/Policies/{CidadePolicy,GrupoParticipantePolicy}.php` | create | Amarradas a `catalogo.gerenciar` |
| `app/Http/Controllers/Controller.php` | modify | `AuthorizesRequests` na base — a Policy confere de novo, perto do recurso |
| `app/Models/GrupoParticipante.php` | modify | Relação `inscricoes()`, usada para saber se dá para excluir |
| `resources/js/pages/Admin/Catalogo/{Cidades,GruposParticipantes}.vue` | create | Telas do catálogo |
| `tests/Feature/Admin/CatalogoTest.php` | create | CRUD e recusa de exclusão em uso |

### Passo 4 — Estrutura do evento (`59f622f`)

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Admin/{EventoController,DiaEventoController,GrupoAtividadeController,AtividadeController,ConflitoAtividadeController}.php` | create | CRUD da estrutura, tudo aninhado na URL do evento |
| `app/Http/Controllers/Admin/Concerns/CuidaDaEstruturaDoEvento.php` | create | As travas comuns (evento com inscrição ativa, pertencimento do recurso ao evento) |
| `app/Http/Requests/Admin/{EventoRequest,DiaEventoRequest,GrupoAtividadeRequest,AtividadeRequest,ConflitoAtividadeRequest}.php` | create | Datas, capacidade acima do ocupado, par de conflito normalizado, mínimo ≤ máximo |
| `app/Http/Resources/Admin/EstruturaDoEventoResource.php` | create | Props da estrutura |
| `app/Policies/EventoPolicy.php`, `app/Models/Evento.php` | create/modify | Permissão `eventos.gerenciar` e relação `inscricoes()` |
| `resources/js/pages/Admin/Eventos/{Index,Formulario,Estrutura}.vue` | create | Telas do evento |
| `tests/Feature/Admin/CrudEventoTest.php` | create | Restrições espelhadas e recusa de apagar em uso |

### Passo 5 — Lista de inscrições (`40f0910`)

| File | Action | Description |
|------|--------|-------------|
| `app/Services/Admin/FiltroDeInscricoes.php` | create | Monta a consulta filtrada, reaproveitada pela lista e pelo CSV. **CPF não filtra e não busca** |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | create | `index` com filtros combináveis e paginação de 25 preservando o filtro |
| `app/Http/Resources/Admin/LinhaDaInscricaoResource.php` | create | Props sem `documento` nem `documento_hash` |
| `app/Policies/InscricaoPolicy.php` | create | `ver`, `exportar`, `cancelar`, `confirmarPagamento` |
| `resources/js/components/admin/{FiltrosDeInscricao,TabelaDeInscricoes}.vue`, `resources/js/pages/Admin/Inscricoes/Index.vue`, `resources/js/types/admin.ts` | create | Tela da lista |
| `tests/Feature/Admin/ListaInscricoesTest.php` | create | Filtros combinados, sem CPF nos props |

### Passo 6 — Ficha e ações na tela (`952a9b8`)

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Admin/AcaoInscricaoController.php` | create | `cancelar` e `confirmarPagamento`, cada um atrás da sua permissão |
| `app/Http/Requests/Admin/{CancelarInscricaoRequest,ConfirmarPagamentoManualRequest}.php` | create | Motivo e observação obrigatórios; método restrito aos valores manuais |
| `resources/js/pages/Admin/Inscricoes/Show.vue`, `resources/js/components/admin/DialogoDeAcao.vue` | create | Ficha com histórico da cobrança e o diálogo de justificativa, com o aviso de que o valor pago não volta sozinho |
| `tests/Feature/Admin/FichaDaInscricaoTest.php` | create | Histórico da cobrança, props sem CPF, ações conforme a permissão |
| `routes/web.php` | modify | Rotas administrativas dos recursos |

### Passo 7 — Exportação CSV (`35f6028`)

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Admin/ExportarInscricoesController.php` | create | `streamDownload` com cursor, `;`, BOM UTF-8, sem CPF, respeitando os filtros |
| `tests/Feature/Admin/ExportacaoTest.php` | create | Filtro respeitado, ausência de CPF, acento correto |

### Passo 8 — Playwright e fechamento (este commit)

| File | Action | Description |
|------|--------|-------------|
| `tests/e2e/admin-inscricoes.spec.ts` | create | 3 cenários: achar pelo filtro + cancelar com motivo + vaga voltando no painel; cancelamento sem motivo barrado na tela, com `Esc` devolvendo o foco; `organizador` sem o botão de confirmação manual, e 403 no pedido feito por fora da tela |
| `resources/js/pages/Admin/Inscricoes/Index.vue` | modify | `data-teste` → `data-testid` no link de exportação |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modify | `data-teste` → `data-testid` nos dois botões de ação |
| `resources/js/components/admin/DialogoDeAcao.vue` | modify | Guarda o elemento em foco na abertura e o devolve no `close-auto-focus` |
| `resources/js/layouts/app/AppSidebarLayout.vue` | modify | `min-w-0` na coluna de conteúdo |
| `resources/js/components/admin/TabelaDeInscricoes.vue` | modify | `relative` no link "Abrir", para ancorar o texto de leitor de tela |
| `docs/PROGRESS.md` | modify | Etapa 14, Fase 6 concluída, decisões D-55 a D-64, P-02 e P-03 explicitamente abertas, nenhuma dependência nova |
| `docs/IMPLEMENTATION_PLAN.md` | modify | Fase 6b marcada como ✅, versão 1.5, estado real atualizado |

## Histórico da execução — leia antes de rodar a Fase 7

O plano foi executado em **cinco corridas**. **Quatro delas terminaram por esgotamento de contexto**, sempre em commit limpo: cada executor conseguiu fechar um ou dois passos e parou. O plano previa isso ("executores morrem em ~60 chamadas de ferramenta") e a instrução de commitar ao fim de cada passo foi o que salvou o trabalho — nenhuma corrida perdeu código.

**Nenhuma das quatro primeiras corridas escreveu o `plan.done.md`.** Este relatório é da quinta, que fez apenas o fechamento: commit do trabalho pendente do passo 8, documentação e relatório. **Recomendação para a Fase 7:** quebrar o plano em lotes menores, ou reservar explicitamente uma corrida final só para o fechamento, como foi feito aqui.

**Os três cenários de ponta a ponta falharam na primeira escrita** — todos com tempo esgotado em `locator.fill`, dois deles na mesma linha de um auxiliar compartilhado (o campo "Buscar" da lista). A causa não era o teste, e foram três problemas distintos:

1. **Seletor em português.** As telas marcavam os botões com `data-teste`; o Playwright lê `data-testid`. O `getByTestId` não achava nada, e a espera até o tempo acabar era a única resposta.
2. **A tela estava mesmo quebrada no celular.** Os cenários rodam num navegador do tamanho de um Pixel 5. Sem `min-w-0`, a coluna de conteúdo se recusava a ficar mais estreita que a tabela de inscrições dentro dela, e o navegador reagia afastando a página inteira; o campo de busca ficava fora de alcance. O irmão do problema: o texto que só o leitor de tela ouve (`sr-only`, posicionado de forma absoluta) não tinha âncora por perto e se prendia à página inteira, esticando o documento. **O teste não estava exigindo demais — ele encontrou um defeito real de responsividade.**
3. **O foco não voltava.** O cenário que aperta `Esc` exige que o foco retorne ao botão de origem. O diálogo do projeto faz isso sozinho quando é aberto por um gatilho próprio, mas o nosso é comandado por propriedade — ninguém sabia de onde a pessoa veio. Foi preciso guardar o elemento em foco na abertura e devolvê-lo no `close-auto-focus`.

Tudo isso está registrado como decisões **D-62**, **D-63** e **D-64** em `docs/PROGRESS.md`.

## Quality Criteria

| Criterion | Status | Evidence |
|-----------|:------:|----------|
| Cancelamento devolve vaga do evento e de cada atividade | ✅ | `CancelamentoAdministrativoTest` (evento + atividades) — `php artisan test` verde, 370 testes |
| Cancelamento exige motivo | ✅ | `InvalidArgumentException` na Action + `CancelarInscricaoRequest`; coberto por teste |
| Cancelar duas vezes não devolve vaga duas vezes | ✅ | Gravação condicional (`where('situacao', $origem)` + `update`), teste de idempotência |
| Corrida com a expiração não devolve vaga em dobro | ✅ | `tests/Feature/Admin/scripts/cancelar-ou-expirar.php` — processos reais, no molde de `ConcorrenciaTest` |
| Cancelar confirmada é permitido e não estorna | ✅ | `liberarConfirmada()` + cobrança paga intocada; aviso na `Show.vue` (decisão D-56) |
| Confirmação manual registra origem manual sem `id_externo` forjado | ✅ | `metadados['origem'] = 'manual'`, `gateway = 'manual'`, `id_externo = null` |
| Confirmação manual de expirada é recusada em português | ✅ | `ConfirmacaoManualRecusadaException` (decisão D-57) |
| `organizador` recebe 403 ao confirmar manualmente | ✅ | `permission:pagamentos.confirmar-manual` na rota + `InscricaoPolicy`; provado em Pest **e** no cenário Playwright (pedido por fora da tela → 403) |
| `InscricaoCancelada` anunciado depois da transação, só na chamada que mudou | ✅ | `dispatch` fora do `DB::transaction`, após `if (! $cancelou) return false` |
| Nenhum ouvinte registrado | ✅ | `grep -R "InscricaoCancelada" app/Listeners` → nada; não existem listeners no projeto |
| Restrições do banco espelhadas em mensagem amigável | ✅ | FormRequests em `app/Http/Requests/Admin/`; `CrudEventoTest`, `CatalogoTest` |
| Apagar registro em uso é recusado com explicação | ✅ | "Esta cidade não pode ser excluída porque tem N grupo(s)…" |
| Filtros combinam e a paginação preserva os filtros | ✅ | `FiltroDeInscricoes` + `ListaInscricoesTest` |
| Busca nunca acha por pedaço de CPF | ✅ | `FiltroDeInscricoes` busca só em nome, e-mail e código público; testado |
| CSV em streaming, com filtro, `;` e BOM | ✅ | `streamDownload` + `cursor()`; `ExportacaoTest` |
| `pint`, `lint`, `vue-tsc` limpos | ✅ | Ver "Verification" |
| Toda a suíte Pest verde | ✅ | 370 passed, 1805 assertions |
| Cenários Playwright anteriores verdes e **não editados** | ✅ | 28 passed; `git status` mostra só o arquivo novo `admin-inscricoes.spec.ts` |
| Nenhum `lockForUpdate()` | ✅ | Concorrência por gravação condicional em ambas as Actions |
| Nenhum `documento`/`documento_hash` em props ou CSV | ✅ | `LinhaDaInscricaoResource` e `ExportarInscricoesController`; cenário E2E confere o corpo da página |
| Ordem canônica de vaga | ✅ | `LiberarVagas` reaproveitada, não reescrita |
| Nenhuma dependência nova | ✅ | `git diff 35f6028~7 35f6028 -- composer.json package.json` → vazio |
| Diálogo prende o foco, fecha com `Esc` e devolve o foco | ✅ | `DialogoDeAcao.vue` + cenário E2E `toBeFocused()` (decisão D-64) |

## Verification

Medições feitas sobre esta mesma árvore, antes do commit de fechamento.

| Command | Result |
|---------|--------|
| `vendor/bin/pint --test` | **passou** |
| `npm run lint` | **limpo** |
| `npx vue-tsc --noEmit` | **zero erros** |
| `php artisan test` (no HOST, como manda o `phpunit.xml`) | **370 passed, 1805 assertions** |
| `npm run test:e2e` | **28 passed (40.9s)** |

Comparação com a linha de base da fase (fim da 6a): 268 testes Pest / 1207 asserções e 25 cenários E2E. Saldo da Fase 6b: **+102 testes, +598 asserções, +3 cenários**, e os 25 cenários anteriores intactos.

## Deviations from Plan

- **`app/Enums/MetodoPagamento.php` foi modificado.** O plano não previa mexer em Enum, mas não havia como gravar "pagou em dinheiro" sem inventar que foi Pix. Entraram `Dinheiro`, `Transferencia` e `Outro`, e Pix e cartão ficaram **proibidos** de declaração manual (decisão **D-59**).
- **Arquivos além do Output Format do plano**, todos consequência direta dos passos: `app/Exceptions/Pagamentos/ConfirmacaoManualRecusadaException.php`, `app/Http/Controllers/Admin/Concerns/CuidaDaEstruturaDoEvento.php`, `tests/Feature/Admin/FichaDaInscricaoTest.php`, `tests/Feature/Admin/CatalogoTest.php` e `tests/Feature/Admin/scripts/cancelar-ou-expirar.php`.
- **`app/Http/Controllers/Controller.php`** ganhou `AuthorizesRequests` para que as Policies pudessem ser chamadas nos controllers — segunda tranca além da permissão na rota.
- **`resources/js/layouts/app/AppSidebarLayout.vue`** foi tocado. Não estava no plano, é arquivo do pacote inicial, e a mudança é de uma classe (`min-w-0`): sem ela, qualquer tela administrativa com tabela larga quebra no celular (decisão **D-63**).
- **`resources/js/components/AppSidebar.vue`** ganhou os itens de navegação das telas novas (passo 5).
- **Nenhuma migração, nenhuma tabela de auditoria, nenhum ouvinte** — como o plano exigia.

## Commit

- **Mensagem:** `feat(admin): polish registration management and close phase 6b`
- **Arquivos:**
  - `tests/e2e/admin-inscricoes.spec.ts` (novo)
  - `resources/js/components/admin/DialogoDeAcao.vue`
  - `resources/js/components/admin/TabelaDeInscricoes.vue`
  - `resources/js/layouts/app/AppSidebarLayout.vue`
  - `resources/js/pages/Admin/Inscricoes/Index.vue`
  - `resources/js/pages/Admin/Inscricoes/Show.vue`
  - `docs/PROGRESS.md`
  - `docs/IMPLEMENTATION_PLAN.md`
  - `.planning/feat/features/fase-6b-cadastros-e-gestao-de-inscricoes/plan.done.md`
- **Fora do commit, de propósito:** o arquivo solto na raiz `Prompt para Claude Code — Plataforma de Inscrições e Gestão de Eventos.md`, que é anterior a esta fase.
- **Nenhum `git push` foi executado.**
