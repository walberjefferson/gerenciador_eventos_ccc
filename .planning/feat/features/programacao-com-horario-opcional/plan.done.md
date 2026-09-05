# Execution Report — Programação com horário opcional e dia automático

> **Plan:** programacao-com-horario-opcional
> **Executed:** 2026-09-02
> **Status:** ⚠️ WITH CAVEATS

## What Was Done

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/2026_09_02_100001_tornar_horario_da_atividade_opcional.php` | create | `comeca_em`/`termina_em` nuláveis; CHECK recriado como "par nulo ou par válido"; `down()` aborta com mensagem quando há atividade sem horário |
| `app/Models/Atividade.php` | modify | `temHorario()`, `data()` (data efetiva), `sobrepoe()` com dia inteiro (RN-06 revisada), `idadeNaData()` pela data efetiva |
| `app/Services/Inscricoes/ValidadorSelecaoAtividades.php` | modify | Eager load de `grupoAtividade.diaEvento`; recusa própria: "{nome} ocupa o dia inteiro e não pode ser escolhida junto com {outra}." |
| `app/Http/Requests/Admin/AtividadeRequest.php` | modify | Horário `nullable` em par (`required_with` cruzado), `after:comeca_em` só quando os dois vierem, mensagens em pt-BR, `dadosDaAtividade()` devolvendo `null` |
| `app/Http/Resources/AtividadeResource.php` | modify | `comeca_em`/`termina_em`/`horario_rotulo` nuláveis + novo `data` (AAAA-MM-DD) |
| `app/Http/Resources/InscricaoAcompanhamentoResource.php` | modify | Mesmos campos nuláveis |
| `app/Http/Controllers/InscricaoController.php` | modify | Payload da seleção com horário nulável + `data` |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | modify | Horário nulável na ficha administrativa |
| `app/Http/Resources/Admin/EstruturaDoEventoResource.php` | modify | Horário nulável + `evento.dias_total` |
| `app/Http/Controllers/Admin/EventoController.php` | modify | `store()` cria evento + "Dia 1" + grupo "Atividades" em `DB::transaction` (RN-A3), auditoria mantida |
| `resources/js/types/{evento,participante,admin}.ts` | modify | Horários nuláveis, `data` na atividade pública, `dias_total` no evento da estrutura |
| `resources/js/composables/useSelecaoAtividades.ts` | modify | `temHorario()`, `haChoqueDeHorario` com dia inteiro, idade pela `data`, motivo "ocupa o dia inteiro" |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modify | Horário "(opcional)" com texto de apoio, `—` na listagem, seção de dias recolhida com `dias_total === 1` (botão com `aria-expanded`/`aria-controls`) |
| `resources/js/components/eventos/ProgramacaoDoDia.vue` | modify | Horário só com `v-if` |
| `resources/js/components/inscricao/CartaoDeAtividade.vue` | modify | Idem |
| `resources/js/components/inscricao/PassoRevisao.vue` | modify | Idem, sem separador `·` órfão |
| `resources/js/components/inscricao/ResumoDaInscricao.vue` | modify | Tipo local com `horario_rotulo: string \| null` |
| `resources/js/components/participante/ResumoDaInscricao.vue` | modify | `quando()` junta dia e horário só quando existem |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modify | **Fora da tabela do plano** — ver Desvios |
| `database/factories/AtividadeFactory.php` | modify | Estado `semHorario()` |
| `tests/Feature/Admin/CrudEventoTest.php` | modify | Dia 1 + grupo padrão no `store`; nada pela metade quando o cadastro é recusado; editar não cria dia |
| `tests/Feature/Admin/CrudAtividadeHorarioOpcionalTest.php` | create | Grava sem horário, recusa horário pela metade, CHECK do Postgres, estrutura e tela pública sem horário |
| `tests/Feature/Inscricoes/SelecaoAtividadesTest.php` | modify | Choque de dia inteiro, dias diferentes não chocam, idade pela data do dia |
| `tests/e2e/atividade-sem-horario.spec.ts` | create | Admin cadastra sem horário, recusa de meio horário, cartão do participante sem horário e inscrição concluída |

## Quality Criteria

| Criterion | Status | Evidence (real output) |
|-----------|--------|------------------------|
| Migration roda e reverte em base com dados | ✅ | `migrate:fresh --seed` + `\d atividades`: `comeca_em ... (sem not null)`, índice `atividades_comeca_em_termina_em_index` preservado |
| `down()` aborta com atividade sem horário | ✅ | `migrate:rollback` → `RuntimeException: Não é possível reverter: 1 atividade(s) estão gravadas sem horário...`; após apagar a linha, rollback e `migrate` de volta rodaram DONE |
| CHECK recusa meio horário no banco | ✅ | `INSERT ... comeca_em=now(), termina_em=NULL` → `ERROR: new row for relation "atividades" violates check constraint "atividades_horario_check"` |
| `php artisan test` (paralelo) verde | ✅ | `Tests: 694 passed (5171 assertions)` — base antes da feature: 675 passed |
| `./vendor/bin/pint --test` sem erro novo | ⚠️ | Única falha: `database/seeders/DatabaseSeeder.php` (`statement_indentation`) — **pré-existente**, medida antes de qualquer alteração e em arquivo não tocado |
| `./vendor/bin/phpstan analyse` | ❌ | PHPStan **não está instalado** no projeto (`vendor/bin/phpstan` inexistente, sem `phpstan.neon`, ausente do `composer.json`). Instalar seria acrescentar dependência fora do plano |
| `npm run build` | ✅ | `✓ built in 3.79s` |
| `vue-tsc --noEmit` | ✅ | `VUE_TSC_EXIT=0` |
| Nenhum `comeca_em` desprotegido | ✅ | grep em `app/`, `resources/views/`, `resources/js/`: só restam `?->`, guardas `temHorario()` e `=== null` |
| Sem `!` (non-null assertion) novo no TS | ✅ | Uso de `?? ''` e checagens explícitas |
| Playwright — cenário novo | ✅ | `atividade-sem-horario.spec.ts`: 3 passed |
| Playwright — `conflito-de-horario` e `caminho-feliz` | ✅ | 2 passed na execução conjunta e verdes na suíte completa |
| Suíte Playwright completa | ⚠️ | `89 passed, 5 failed` — falhas em `admin-avisos-pagamento.spec.ts` (3) e `admin-usuarios.spec.ts` (2), **pré-existentes e alheias** |

## Verification

| Command | Result |
|---------|--------|
| `php artisan test --parallel` | 694 passed (5171 assertions) |
| `./vendor/bin/pint --test` | 1 falha pré-existente (`DatabaseSeeder.php`) |
| `./vendor/bin/phpstan analyse` | não executável — ferramenta ausente do projeto |
| `npx vue-tsc --noEmit` | exit 0 |
| `npm run build` | ✓ built in 3.79s |
| `npx playwright test atividade-sem-horario` | 3 passed |
| `npx playwright test atividade-sem-horario conflito-de-horario caminho-feliz` | os 2 cenários exigidos passaram |
| `npx playwright test` (suíte inteira) | 89 passed, 5 failed (pré-existentes) |
| `npx playwright test admin-avisos-pagamento admin-usuarios` (isolado, banco recém-semeado) | 7 passed, 4 failed — as mesmas falhas, sem nenhuma alteração desta feature envolvida |

### Sobre as 5 falhas do Playwright

Elas não vêm daqui, e há três evidências:

1. **Reproduzem isoladas**, com banco recém-semeado e sem nenhum cenário desta feature ter rodado antes.
2. **O motivo é outro assunto**: `strict mode violation: getByRole('link', { name: 'Avisos do provedor' }) resolved to 2 elements` — ambiguidade de seletor entre o item da barra lateral e o botão do painel de pagamentos. Nada de horário, atividade ou programação.
3. **Ordem de execução**: `admin-avisos-pagamento` e `admin-usuarios` rodam ANTES de `atividade-sem-horario` (ordem alfabética, `workers: 1`), então nem o evento criado por este cenário poderia tê-las afetado.

Corrigi-las estaria fora do escopo (proibição: não consertar defeito pré-existente).

## Deviations from Plan

- **`resources/js/pages/Admin/Inscricoes/Show.vue` (fora da tabela da seção 4).** A ficha administrativa da inscrição escrevia `{{ atividade.nome }} — {{ momento(atividade.comeca_em) }}`. Com o horário nulável, a linha sairia como "Futebol — —" (o helper `momento()` já devolvia travessão para nulo), o que parece defeito de tela e não ausência de dado. Seguindo a seção 6 do plano ("tratar o nulo do mesmo jeito e registrar o arquivo"), a linha passou a mostrar só o nome quando não há hora marcada.
- **`dias_total` foi exposto dentro de `evento`** (e não como prop de primeiro nível): a tela já recebe `evento` e o número é atributo dele. `EventoDaEstrutura` ganhou o campo.
- **PHPStan não pôde ser executado** — não está instalado no projeto. Parar por isso deixaria a feature inacabada; registrei como caveat em vez de inventar aprovação.
- **Pint continua acusando `DatabaseSeeder.php`**, falha medida antes de qualquer alteração (arquivo não tocado por esta feature).
- **Cenário E2E roda os dois passos de administração em tela de 1280px** (`test.use`), como já fazem `admin-usuarios` e `admin-avisos-pagamento`: no aparelho de 393px do padrão, as tabelas largas da tela de programação se sobrepõem e interceptam o clique — defeito de responsividade pré-existente da tela administrativa, documentado aqui e não corrigido.
- **O evento do cenário E2E é próprio dele** e volta a `rascunho` no `afterAll`: a suíte compartilha um banco só, e uma atividade de dia inteiro no evento de demonstração bloquearia as escolhas dos outros cenários.
- **`Atividade::data()` faz `loadMissing` quando não há horário.** O eager load pedido pelo plano entrou no validador; nas telas públicas, a relação é carregada sob demanda apenas para atividades sem hora marcada. Nenhum teste de contagem de consultas foi afetado (694 verdes, incluindo os de desempenho).

## Commit

- **Message:** `feat(programacao): horario opcional na atividade e dia inicial automatico`
- **Hash:** `101373c`
- **Files:** 26 (os 24 da tabela da seção 4 + `resources/js/pages/Admin/Inscricoes/Show.vue`, contando os três arquivos criados). Ficaram de fora, como manda o escopo: `ccc-redesign.html`, o arquivo "Prompt para Claude Code — ...md" e o diretório `.planning/`.
