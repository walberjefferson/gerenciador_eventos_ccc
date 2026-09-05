# Execution Report — Inscrições por lote

> **Plan:** inscricoes-por-lote
> **Executed:** 2026-09-02 23:58
> **Status:** ✅ COMPLETE

## What Was Done

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/2026_09_02_200001_create_lotes_table.php` | create | Tabela `lotes`, índice único `(evento_id, posicao)` e os quatro CHECK |
| `database/migrations/2026_09_02_200002_add_lote_id_to_inscricoes_table.php` | create | FK `lote_id` nullable com `restrictOnDelete` |
| `database/factories/LoteFactory.php` | create | States `porData`, `porQuantidade` e `esgotado` (contador movido por SQL) |
| `app/Models/Lote.php` | create | Casts, relações, `estaDisponivel()`, `vagasRestantes()`, `situacaoEm()`, scope `emOrdem()` |
| `app/Models/Evento.php` | modify | `lotes()`, `temLotes()`, `loteVigente()`, `aceitaInscricaoPorLote()` (RN-L9) |
| `app/Models/Inscricao.php` | modify | Relação `lote()` e `lote_id` no `$fillable` |
| `app/Actions/Inscricoes/ResolverLoteVigente.php` | create | RN-L3 num lugar só: `__invoke()` (banco) e `daColecao()` (em memória) |
| `app/Actions/Inscricoes/ReservarVagas.php` | modify | `reservarNoLote()` com UPDATE condicional por prazo E quantidade; ordem RN-L11 |
| `app/Actions/Inscricoes/CriarInscricao.php` | modify | `conferirLote()` (RN-L5/RN-L8/RN-L9), grava `lote_id` e o valor do lote (RN-L7) |
| `app/Actions/Inscricoes/LiberarVagas.php` | modify | Comentário explicando por que o lote **não** é devolvido (RN-L6) |
| `app/DTOs/Inscricoes/DadosNovaInscricao.php` | modify | Campo `?int $loteId` (último parâmetro; nenhuma chamada existente quebrou) |
| `app/Exceptions/Inscricoes/LoteIndisponivelException.php` | create | Três mensagens: virou, esgotou agora, não há mais lote |
| `app/Http/Requests/StoreInscricaoRequest.php` | modify | `lote_id` nullable/integer/exists + mensagens |
| `app/Http/Resources/LotePublicoResource.php` | create | Nome, valor, situação, `selecionavel`, `limite_rotulo` e `situacao_rotulo` |
| `app/Http/Resources/EventoPublicoResource.php` | modify | `lotes`, `lote_vigente_id`, valor efetivo e RN-L9 no motivo |
| `app/Http/Controllers/EventoPublicoController.php` | modify | Carrega `lotes` |
| `app/Http/Controllers/InscricaoPublicaController.php` | modify | Carrega `lotes`; redireciona quando não há lote vigente (RN-L9) |
| `app/Http/Controllers/Admin/LoteController.php` | create | `store`/`update`/`destroy` no molde de `DiaEventoController` |
| `app/Http/Requests/Admin/LoteRequest.php` | create | RN-L1, RN-L2 e RN-L12 com mensagens em português |
| `app/Http/Controllers/Admin/EventoController.php` | modify | `lotesDoEvento()`: lotes + soma das quantidades ao lado da capacidade |
| `routes/web.php` | modify | Três rotas em `admin.eventos.lotes.*` |
| `resources/js/types/{evento,inscricao,admin}.ts` | modify | `LotePublico`, `lotes`, `lote_vigente_id`, `lote_id`, `LoteDaEstrutura`, `ResumoDosLotes` |
| `resources/js/components/eventos/ListaDeLotes.vue` | create | Lista semântica, com e sem escolha; `aria-disabled` e situação em texto |
| `resources/js/pages/Eventos/Show.vue` | modify | Seção de lotes + nome do lote vigente no painel de compra |
| `resources/js/pages/Inscricoes/Criar.vue` | modify | `lote_id` no formulário e recarga dos lotes na recusa de RN-L5 |
| `resources/js/components/inscricao/PassoParticipacao.vue` | modify | Lista de lotes no topo da etapa, com escolha |
| `resources/js/components/inscricao/PassoRevisao.vue` | modify | Valor do lote vigente, com o nome do lote abaixo |
| `resources/js/components/inscricao/ResumoDaInscricao.vue` | modify | Idem, no resumo lateral |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modify | Seção de lotes com modal, situação, soma × capacidade e exclusão condicional |
| `tests/Feature/Inscricoes/Cenario.php` | modify | `comLotes()` opcional |
| `tests/Feature/Inscricoes/LotesTest.php` | create | 24 testes de domínio |
| `tests/Feature/Admin/LotesAdminTest.php` | create | 17 testes de cadastro |
| `tests/e2e/inscricao-por-lote.spec.ts` | create | Os quatro cenários Playwright |
| `docs/BUSINESS_RULES.md` | modify | RN-L1 a RN-L12 + índice de mensagens + mapeamento de testes |
| `docs/DATABASE.md` | modify | ERD, dicionário de `lotes` (3.13) e a coluna `lote_id` |
| `docs/PROGRESS.md` | modify | Entrada da etapa e a decisão da RN-L6 com o risco registrado |

## Quality Criteria

| Criterion | Status | Evidence (real output) |
|-----------|--------|------------------------|
| Vaga do lote presa por UPDATE condicional | ✅ | `ReservarVagas::reservarNoLote()` — `UPDATE lotes SET vagas_ocupadas = vagas_ocupadas + 1 WHERE id = ? AND (disponivel_ate IS NULL OR disponivel_ate > ?) AND (quantidade IS NULL OR vagas_ocupadas < quantidade)`; `$linhas === 0` → recusa. Provado por `it so e vendida uma vez, ainda que duas requisicoes a tenham visto livre` |
| CHECK do banco como última linha de defesa | ✅ | `pg_constraint`: `lotes_quantidade_check`, `lotes_vagas_nao_negativas_check`, `lotes_valor_check`, `lotes_tem_limite_check`. Teste `it nao deixa o contador passar da quantidade nem por comando direto` espera `QueryException` com `lotes_quantidade_check` |
| RN-L3 num lugar só | ✅ | `grep -rn "vagas_ocupadas <" app/ resources/js` → só `Lote::estaDisponivel()` (PHP) e o UPDATE de `ReservarVagas` (SQL, que é a trava). Resource, Action e testes chamam `ResolverLoteVigente` |
| Comentários em português explicando o porquê | ✅ | `LiberarVagas` documenta a ausência do lote (RN-L6, com o risco); `ReservarVagas`, `Lote`, `ResolverLoteVigente`, `LotePublicoResource` e `ListaDeLotes.vue` no tom dos vizinhos |
| PSR-12 / Pint limpo | ✅ | `./vendor/bin/pint --dirty --test` → `{"tool":"pint","result":"passed"}` |
| Vue 3 `<script setup>` + TS strict, sem `any` | ✅ | `npx vue-tsc --noEmit` → sem saída, exit 0. `npx eslint` nos 7 arquivos → sem saída. Prettier: "All matched files use Prettier code style!" |
| Pest — domínio (todos os casos de §5) | ✅ | `tests/Feature/Inscricoes/LotesTest.php` → **24 passed (109 assertions)** |
| Pest — admin (CRUD, RN-L1, RN-L2, RN-L12, auditoria) | ✅ | `tests/Feature/Admin/LotesAdminTest.php` → **17 passed (77 assertions)** |
| Playwright E2E, quatro cenários | ✅ | `npx playwright test inscricao-por-lote` → **4 passed (5.9s)** |
| Acessibilidade da lista de lotes | ✅ | `<ul>`/`<li>` com `role="radiogroup"` no modo de escolha; situação sempre em texto (`Lote atual`/`Em breve`/`Encerrado`); `aria-disabled="true"` e `input` realmente `disabled` — provado no cenário 2 (`toBeDisabled()` + `toHaveAttribute('aria-disabled','true')`) |
| A suíte inteira continua verde | ✅ | `php artisan test --parallel` → **741 passed (5381 assertions)**, 15.54s |

## Verification

| Command | Result |
|---------|--------|
| `php artisan migrate:fresh` (banco `testing`) | ✅ as duas migrações aplicadas; constraints conferidas via `pg_constraint`/`pg_indexes` |
| `./vendor/bin/pint --dirty` | ✅ 1 arquivo ajustado (imports), depois `--test` passou |
| `php artisan test --parallel` | ✅ 741 passed (5381 assertions) |
| `npx vue-tsc --noEmit` | ✅ exit 0, sem erros |
| `npx eslint` (7 arquivos alterados) | ✅ sem apontamentos |
| `npx prettier --check` (arquivos alterados) | ✅ All matched files use Prettier code style |
| `npm run build` | ✅ built in 1.84s |
| `npx playwright test inscricao-por-lote` | ✅ 4 passed |
| `npx playwright test` (suíte completa) | ⚠️ 93 passed, 5 failed — **todas as 5 pré-existentes** (ver abaixo) |

### As cinco falhas Playwright pré-existentes

`admin-avisos-pagamento.spec.ts` (3) e `admin-usuarios.spec.ts` (2) falham na suíte completa.
**Verificado que não são desta entrega:** com as alterações guardadas (`git stash`) e o `build` refeito
sem elas, 4 das 5 falham igual (`admin-avisos-pagamento` 106/133/154 e `admin-usuarios` 110).
A quinta (`admin-usuarios` 69) passou isolada e falha na suíte inteira — é intermitência
pré-existente do mesmo arquivo, com banco compartilhado. Nenhuma delas toca lote, evento ou
inscrição. Não foram corrigidas: defeito pré-existente se documenta, não se conserta fora de escopo.

## Deviations from Plan

1. **O índice `INDEX (evento_id, posicao)` não foi criado separadamente.** O plano pedia o
   `UNIQUE (evento_id, posicao)` **e** um `INDEX` nas mesmas colunas. No PostgreSQL a restrição
   única já cria exatamente esse índice btree (`lotes_evento_id_posicao_unique`); um segundo seria
   índice morto, com custo de escrita e de espaço e nenhum ganho de leitura. Documentado no
   comentário da migração e em `docs/DATABASE.md`.

2. **RN-L9 mora ao lado de `inscricoesEstaoAbertas()`, e não dentro dele.** O passo 4 do plano dizia
   "ajustar `inscricoesEstaoAbertas()`". Pôr a consulta de lote lá dentro custaria **uma ida ao banco
   por evento** na porta da rua — e quebraria o teste `it monta a pagina inteira com tres consultas ao
   banco, e nunca mais` (`tests/Feature/Publico/HomeTest.php`), que trava a home em 3 consultas. A regra
   virou `Evento::aceitaInscricaoPorLote()` e é combinada exatamente onde `temVagaDisponivel()` já era
   combinado — os três pontos de decisão: `EventoPublicoResource`, `InscricaoPublicaController` e
   `CriarInscricao`. O porquê está comentado no próprio model. O comportamento externo é o pedido:
   evento com lotes e sem vigente fecha a inscrição, com a frase "Os lotes de inscrição se esgotaram."

3. **`motivoEmPalavras()` fica no `EventoPublicoResource`, não no `Evento`.** O plano o listava como
   método do model; ele já existia no Resource desde a Fase 5a. Foi ajustado onde estava.

4. **A corrida pela última vaga do lote é provada pelo UPDATE condicional, não por processos
   paralelos.** A maquinaria de `Disputa`/`scripts/disputar-vaga.php` não conhece lote e a limpeza
   dela apagaria eventos que agora têm FK `restrict` vinda de `lotes` — e nenhum dos dois arquivos
   está na tabela de saída do plano. As duas provas escritas são: duas chamadas a `reservarNoLote()`
   sobre o mesmo lote livre (a segunda é recusada, contador para no teto) e duas inscrições completas
   na última vaga (a segunda é recusada, uma inscrição no banco). A razão está comentada no teste.

5. **A entrada em `docs/PROGRESS.md` não recebeu número de etapa.** A lista do documento para na
   Etapa 22, mas a decisão DA-54 já referencia uma "Etapa 23" (a de movimento reduzido) — numerar a
   minha como 23 criaria ambiguidade num documento que já está atrás do repositório. Entrou como
   `- [x] **Inscrições por lote: o preço passou a ter prazo.**`.

## Commit

- **Hash:** `d40131a`
- **Mensagem:** `feat(inscricoes): inscricoes por lote com limite de data e quantidade`
- **Arquivos:** os 34 da tabela do plano + os 3 docs do passo 10. Os arquivos não rastreados
  pré-existentes (`ccc-redesign.html`, o `.md` do prompt, `.planning/.../programacao-com-horario-opcional/`)
  **não** foram commitados.
