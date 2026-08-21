# Execution Report — Fase 6a: controle de acesso administrativo e painel de números

> **Plan:** fase-6a-acesso-e-painel-administrativo
> **Executed:** 2026-08-20 (duas execuções: passos 1–5 numa primeira, 6–7 nesta)
> **Status:** ✅ COMPLETE

---

## Resumo

Os sete passos do plano estão entregues e commitados. Esta execução retomou o
trabalho no passo 6 (a tela do painel, que a execução anterior deixou começada
e não commitada) e fechou o passo 7 (cenários Playwright, portão de qualidade
completo e documentação).

A verificação de tipos do projeto passou a rodar **limpa pela primeira vez**:
`npx vue-tsc --noEmit` termina com zero erros, e a pendência **P-09** foi
fechada.

---

## What Was Done

### Passo 1 — Papéis e permissões · commit `9b38b1d`

| File | Action | Description |
|------|--------|-------------|
| `composer.json`, `composer.lock` | modify | `spatie/laravel-permission` |
| `config/permission.php` | create | configuração publicada pelo pacote |
| `database/migrations/2026_08_20_232401_create_permission_tables.php` | create | tabelas do pacote |
| `database/seeders/PapeisSeeder.php` | create | dois papéis, nove permissões em português, idempotente |
| `database/seeders/DatabaseSeeder.php` | modify | chama `PapeisSeeder` e `AdminDemoSeeder` |
| `app/Models/User.php` | modify | trait `HasRoles` |
| `bootstrap/app.php` | modify | apelidos `permission` e `role` |
| `tests/Feature/Admin/AutorizacaoTest.php` | create | sem papel → 403; organizador barrado no que é do administrador; seeder duas vezes sem duplicar |
| `tests/Feature/Admin/Cenario.php` | create | cenário compartilhado dos testes administrativos |

### Passo 2 — Fechar o cadastro público · commit `ffd6858`

| File | Action | Description |
|------|--------|-------------|
| `routes/auth.php` | modify | rotas `register` removidas |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | delete | sem rota, o controller não tem por que existir |
| `resources/js/pages/auth/Register.vue` | delete | tela do cadastro público |
| `resources/js/pages/auth/Login.vue` | modify | link "Sign up" removido |
| `app/Console/Commands/CriarAdministrador.php` | create | `usuario:criar-administrador`, senha por `secret()` |
| `database/seeders/AdminDemoSeeder.php` | create | conta de desenvolvimento, travada em `local` |
| `tests/Feature/Auth/RegistrationTest.php` | modify | passa a **provar o 404** nas duas rotas |
| `tests/Feature/Admin/CriarAdministradorTest.php` | create | cria, recusa e-mail repetido, atribui papel |

### Passo 3 — Fechar a pendência P-09 · commit `095e49a`

| File | Action | Description |
|------|--------|-------------|
| `resources/js/types/index.ts` | modify | `Auth.user` vira `User \| null`; `SharedData` ganha índice aberto |
| `resources/js/types/vite-env.d.ts` | create | `ImportMetaEnv` no escopo global (D-54) |
| `resources/js/app.ts` | modify | `declare module 'vite/client'` removido |
| `resources/js/components/NavMain.vue` | modify | `NavItem` duplicado eliminado; passa a usar `href` (D-53) |
| `resources/js/components/{AppHeader,AppSidebarHeader,NavUser,UserInfo,TextLink}.vue` | modify | tipagem real, sem `any` nem `@ts-ignore` |
| `resources/js/pages/{Welcome.vue,auth/Login.vue,settings/Profile.vue}`, `layouts/auth/AuthSplitLayout.vue` | modify | idem |

### Passo 4 — Layout e navegação administrativa · commit `969682a`

| File | Action | Description |
|------|--------|-------------|
| `resources/js/layouts/AdminLayout.vue` | create | moldura administrativa com `<h1>`, descrição e "pular para o conteúdo" |
| `resources/js/components/AppSidebar.vue` | modify | navegação administrativa real; links do starter kit removidos |
| `routes/web.php` | modify | grupo `admin` com `auth` + `verified` + `permission:painel.ver` |

### Passo 5 — Os números · commit `29424a1`

| File | Action | Description |
|------|--------|-------------|
| `app/Services/Admin/NumerosDoEvento.php` | create | três consultas agregadas (`count`/`sum` + `group by`) |
| `app/Http/Controllers/Admin/PainelController.php` | create | `index`, com seletor de evento e rascunho fora da lista |
| `tests/Feature/Admin/PainelTest.php` | create | cada número conferido contra o banco semeado, inclusive o evento sem inscrição |

### Passo 6 — A tela do painel · commit `ca8f638` (esta execução)

| File | Action | Description |
|------|--------|-------------|
| `resources/js/types/painel.ts` | create | formato dos props do painel; dinheiro sempre em centavos |
| `resources/js/components/admin/CartaoDeNumero.vue` | create | número com rótulo, significado e tom semântico |
| `resources/js/components/admin/TabelaDeVagas.vue` | create | vagas por atividade, com `<th scope>`, `<caption>` e "sem limite" ≠ zero |
| `resources/js/pages/Admin/Painel.vue` | create | a tela: seletor de evento, carregamento, vazio e os três blocos |

Ajustes feitos nesta execução sobre o que a execução anterior deixou começado:

1. **Nenhuma situação some da tela.** Os cartões de inscrição passaram a ser
   renderizados a partir do array que o servidor manda, em vez de cinco cartões
   fixos. Antes, `lista_espera` não aparecia, mas entrava no total — a soma dos
   cartões não fechava com o total mostrado ao lado.
2. **Zero com explicação.** Evento publicado e ainda sem ninguém inscrito passou
   a mostrar um aviso (`data-testid="painel-sem-inscricao"`) dizendo que os zeros
   são a resposta certa, e não falha de leitura. Era critério de qualidade do
   plano e não estava atendido.
3. **Estado de carregamento visível.** A troca de evento marca o bloco com
   `aria-busy`, reduz o contraste do conteúdo velho e anuncia por `role="status"`.
4. **Marcações de teste** (`painel-inscricoes`, `painel-vagas`, `painel-dinheiro`)
   para o passo 7 poder afirmar os três blocos sem depender de texto de layout.
5. Grade ajustada para `sm:2 / lg:3` colunas, para caber em tablet (768 px) sem
   rolagem horizontal com seis cartões.

### Passo 7 — Playwright e fechamento · commit `feat(admin): polish access control and close phase 6a` (esta execução)

| File | Action | Description |
|------|--------|-------------|
| `tests/e2e/admin-acesso.spec.ts` | create | quatro cenários: visitante desviado, sem papel recusado, administrador vê os três blocos, `/register` fora do ar |
| `docs/PROGRESS.md` | modify | Etapa 13, decisões D-50 a D-54, P-09 fechada, "em andamento" → Fase 6b, dependência registrada |
| `docs/IMPLEMENTATION_PLAN.md` | modify | Fase 6 partida em 6a (✅) e 6b (❌); cabeçalho e visão geral atualizados |
| `.planning/feat/features/**` | create | planos desta fase e das seguintes |

As contas do cenário administrativo nascem por linha de comando dentro do
próprio spec (o cadastro público não existe mais), usando o mesmo atalho
`artisan()` que os cenários antigos já usavam. **`tests/e2e/apoio.ts` não foi
tocado**, para não arriscar os 21 cenários anteriores.

---

## Quality Criteria

### Funcional

| Criterion | Status | Evidence |
|-----------|:------:|----------|
| `GET`/`POST /register` respondem 404 | ✅ | `RegistrationTest`: `it recusa a tela de cadastro publico`, `it recusa o envio de um cadastro publico`, `it nao conhece mais a rota chamada register` — 3 verdes. E2E: `o cadastro publico nao existe mais` |
| Autenticado sem papel → 403 em rota administrativa | ✅ | `AutorizacaoTest` verde; E2E `quem entrou mas nao tem papel nenhum ve a recusa, nao o painel` (status 403) |
| `organizador` barrado no que é do administrador | ✅ | `AutorizacaoTest` verde (permissões `pagamentos.confirmar-manual`, `usuarios.gerenciar`, `auditoria.ver`) |
| `administrador` alcança tudo o que existe nesta fase | ✅ | `AutorizacaoTest` + E2E `o administrador ve os tres blocos de numeros do evento` |
| Comando cria, recusa e-mail repetido, não ecoa senha | ✅ | `CriarAdministradorTest` verde; a senha vem de `$this->secret()` |
| `PapeisSeeder` idempotente | ✅ | `AutorizacaoTest` roda o seeder duas vezes e confere a contagem |
| Painel com os três blocos, por evento | ✅ | `PainelTest` + E2E (três `data-testid` visíveis) |
| Números batem com o banco semeado | ✅ | `PainelTest` — 228 linhas conferindo situação a situação, vaga a vaga e centavo a centavo |
| Evento sem inscrição → zeros com explicação | ✅ | `NumerosDoEvento::inscricoesPorSituacao` devolve todas as situações zeradas; `Painel.vue` mostra o aviso `painel-sem-inscricao`; caso coberto em `PainelTest` |

### Qualidade

| Criterion | Status | Evidence (saída real) |
|-----------|:------:|-----------------------|
| `vendor/bin/pint --test` | ✅ | `{"tool":"pint","result":"passed"}` · exit=0 |
| `npx eslint .` | ✅ | sem saída · exit=0 |
| `npx prettier --check resources/` | ✅ | `All matched files use Prettier code style!` · exit=0 |
| **`npx vue-tsc --noEmit` com ZERO erros** | ✅ | saída vazia (0 linhas) · exit=0 — **P-09 fechada** |
| Suíte Pest verde | ✅ | `Tests: 268 passed (1207 assertions)` — eram 241/1048 ao final da 5b |
| 21 cenários Playwright intactos e verdes | ✅ | `25 passed (33.6s)`; nenhum dos 21 arquivos anteriores foi editado (`git diff --stat 0ed419d..HEAD -- tests/e2e/` só acusa o arquivo novo) |
| Nenhuma Action/Enum/Model/migração de domínio alterada | ✅ | `git diff --stat 0ed419d..HEAD` — só `app/Models/User.php` (trait `HasRoles`, do framework) e as migrações do pacote de permissões |
| Números por consulta agregada | ✅ | `NumerosDoEvento` usa `DB::table(...)->selectRaw('count(*)…')`, `groupBy` e `sum`; nenhuma coleção Eloquent carregada |
| Vaga restante do contador materializado | ✅ | `vagasPorAtividade` lê `atividades.vagas_reservadas` / `vagas_confirmadas`; `inscricoes_atividades` não aparece na consulta |
| Cor só por token semântico | ✅ | `CartaoDeNumero` usa `text-sucesso-texto`/`text-informacao-texto`/`text-atencao-texto`; nenhum literal hexadecimal nos componentes novos |
| Nenhuma dependência além de `spatie/laravel-permission` | ✅ | `git diff 0ed419d..HEAD -- composer.json package.json` — só a linha do spatie |

### Acessibilidade

| Criterion | Status | Evidence |
|-----------|:------:|----------|
| Navegação por teclado com foco visível | ✅ | `AdminLayout` tem "pular para o conteúdo"; seletor de evento com `focus-visible:ring-2` |
| Tabela com `<th scope>` e legenda | ✅ | `TabelaDeVagas`: `<caption>` + `scope="col"` nas colunas e `scope="row"` no nome da atividade |
| Número nunca comunicado só por cor | ✅ | Todo cartão traz rótulo e frase de significado; "Sem limite" e "Restantes" são texto |
| Painel utilizável em 768 px sem rolagem horizontal | ✅ | Grades `sm:grid-cols-2 lg:grid-cols-3`; a única rolagem é a da tabela, contida em `overflow-x-auto` |

### Playwright E2E

| Cenário | Status |
|---------|:------:|
| `visitante que tenta o painel e mandado para o login` | ✅ |
| `quem entrou mas nao tem papel nenhum ve a recusa, nao o painel` | ✅ |
| `o administrador ve os tres blocos de numeros do evento` | ✅ |
| `o cadastro publico nao existe mais` | ✅ |

---

## Verification

| Command | Result |
|---------|--------|
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` · exit=0 |
| `npx eslint .` | limpo · exit=0 |
| `npx prettier --check resources/` | `All matched files use Prettier code style!` · exit=0 |
| `npx vue-tsc --noEmit` | **0 erros** · exit=0 |
| `php artisan test` (no HOST) | `Tests: 268 passed (1207 assertions)` · 17.46s |
| `php artisan test tests/Feature/Admin tests/Feature/Auth/RegistrationTest.php` | `Tests: 29 passed (163 assertions)` |
| `npm run build` | `✓ built in 1.46s` |
| `npm run test:e2e` | `25 passed (33.6s)` — 21 antigos + 4 novos |

Pest e Playwright foram executados **em momentos separados**: os dois usam o
banco `testing` (D-42).

---

## Deviations from Plan

1. **`app/Http/Controllers/Auth/RegisteredUserController.php` foi apagado** — o
   plano listava só as rotas e a tela. Sem rota que o alcance, o controller seria
   código morto apontando para uma porta murada. Registrado na decisão **D-51**.
2. **`resources/js/pages/settings/Profile.vue` entrou na correção da P-09** — o
   plano não o listava entre os 20 erros, mas ele consumia `auth.user` com
   `as User`, que deixou de compilar quando o tipo compartilhado passou a admitir
   visitante. Corrigido tipando, não forçando.
3. **Duas decisões que o plano não previu foram registradas** no PROGRESS, como
   pedido: **D-53** (os tipos compartilhados do pacote inicial estavam errados —
   `NavMain` tinha uma cópia de `NavItem` com o campo `url` enquanto o projeto
   usa `href`, e por isso o item ativo da barra lateral **nunca acendia**) e
   **D-54** (a declaração de `ImportMetaEnv` precisou sair de `app.ts` para um
   arquivo `.d.ts` global, porque `declare module 'vite/client'` não aumenta um
   arquivo de declarações globais).
4. **A Fase 6 foi partida em 6a e 6b** também no `IMPLEMENTATION_PLAN.md`, para
   que o documento reflita o recorte que o plano já usava.
5. **Nada foi commitado do arquivo `Prompt para Claude Code — ….md`** na raiz do
   repositório: não pertence a esta fase e continua fora do controle de versão.

Nenhum item do §7 Prohibitions foi violado. Nenhum `git push` foi feito.

---

## Commit

| Hash | Message |
|------|---------|
| `9b38b1d` | `feat(admin): add roles and permissions` |
| `ffd6858` | `feat(admin): close public registration and add admin account command` |
| `095e49a` | `fix(types): resolve starter kit type errors (P-09)` |
| `969682a` | `feat(admin): add admin layout and protected route group` |
| `29424a1` | `feat(admin): add event metrics service and dashboard endpoint` |
| `ca8f638` | `feat(admin): add dashboard page` |
| *(este commit)* | `feat(admin): polish access control and close phase 6a` |
