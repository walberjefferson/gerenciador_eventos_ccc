# Action Plan — Cidade vira Setor, e o catálogo real entra no lugar do fictício

> **Type:** feature
> **Created:** 2026-08-30
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Pessoa desenvolvedora full-stack sênior em Laravel 12 (PHP 8.2+,
PSR-12, Pest) + Vue 3.5 `<script setup>` + TypeScript estrito + Inertia 2 +
Tailwind CSS 4. Comentário em português explicando o **porquê**.

**Escopo:** duas mudanças que andam juntas porque falam do mesmo conceito:

1. **Nomenclatura.** O que a tela chama de "Cidade" passa a se chamar
   **"Setor"**, no lado público e no administrativo, incluindo as URLs do
   catálogo administrativo.
2. **Dados.** O catálogo fictício (São Paulo, Campinas, Ribeirão Preto, Belo
   Horizonte, Curitiba) sai; entram os **5 setores e 29 grupos reais**
   extraídos de `/Users/binho/Arquivos/consulta cidades e setores`.

**Fora do escopo — e isto é o coração do plano:** **o banco não muda.** Nenhuma
migration, nenhuma coluna renomeada, nenhuma tabela nova. `cidades`,
`grupos_participantes`, `cidade_id`, os Models `Cidade` e `GrupoParticipante`,
o composable `useGruposDaCidade` e os campos de formulário `cidade_id`
continuam com os nomes de hoje. Muda o que se **lê** e o **caminho das rotas de
catálogo**.

**Stack:** Laravel 12 · PostgreSQL · Pest · Vue 3.5 · Inertia · Tailwind 4 ·
Playwright.

## 2. Direct Objective

Fazer o formulário de inscrição perguntar **"Setor"** e, dependendo dele,
**"Grupo"** — com o catálogo de verdade da comunidade dentro: os cinco setores
de Alagoas e os grupos que pertencem a cada um. Sem tocar no banco.

## 3. Minimum Inputs

### O mapeamento — leia antes de tudo

A fonte é uma consulta de **outro** sistema, com dois níveis (`setores` →
`cidades`). Ele encaixa na nossa estrutura assim:

| No arquivo de origem | Na nossa tabela | Quantos |
|---|---|---|
| `setores` | `cidades` | 5 |
| `cidades` | `grupos_participantes` | 29 |

Ou seja: **o que o arquivo chama de "cidade" é o nosso "grupo"**. A hierarquia
de dois níveis é a mesma que o formulário já tem; só os nomes mudam.

### O catálogo a semear

`uf` é `char(2)` NOT NULL, com `unique(nome, uf)` — todos os setores recebem
**`'AL'`**. O rótulo público, porém, passa a ser **só o nome**: a UF existia
para desambiguar cidades homônimas de estados diferentes, e cinco setores da
mesma região não têm essa ambiguidade.

**Setor Batalha** (8): Batalha (Povoados) · Batalha (Sede) · Belo Monte · Belo
Monte (Povoados) · Jacaré dos Homens · Jaramataia · Monteirópolis · Paus Preto

**Setor Delmiro** (2): Barragem Leste · Mata Grande

**Setor Olho d'água das Flores** (5): Carneiros · Olho d'água das Flores ·
Palestina · Pão de Açúcar · Senador Rui Palmeira

**Setor Palmeira** (6): Divina Pastora · Mar Vermelho · Palmeira (Nossa Senhora
das Graças) · Palmeira (São Vicente) · Paulo Jacinto · Quebrangulo

**Setor Santana** (8): Dois Riachos · Maravilha · Olivença · Poço das
Trincheiras (Quandú) · Poço das Trincheiras (Sede) · Santana do Ipanema
(Camoxinga) · Santana do Ipanema (Samambaia e Pedra d'água) · Santana do
Ipanema (Sede)

**Total: 5 setores, 29 grupos.** Confira essa contagem ao final: 8+2+5+6+8.

**Três correções de digitação foram aplicadas à fonte**, e cada uma precisa
aparecer no relatório para o dono do produto conferir:

| No arquivo | Semeado | Por quê |
|---|---|---|
| `Olho d'água das FLores` | `Olho d'água das Flores` | duas maiúsculas seguidas no meio da palavra |
| `Pão de Açucar` | `Pão de Açúcar` | falta o acento |
| `Setor Olho d'água das flores` | `Setor Olho d'água das Flores` | o nome do setor ficou com a inicial minúscula que o da cidade não tem |

**Não invente outras correções.** "Poço das Trincheiras (Quandú)" e
"Paus Preto" ficam como estão: são nomes locais, e adivinhar seria pior.

### Regras de negócio

1. **O que a pessoa vê muda; o que trafega, não.** O campo continua sendo
   `cidade_id` no formulário e na query string. Renomear o campo enviado
   quebraria o `StoreInscricaoRequest`, o filtro salvo em favoritos e a coluna
   do banco — sem devolver nada a quem usa.
2. **O grupo continua dependendo do que está acima dele.** A regra de
   `useGruposDaCidade` não muda uma linha: escolher o setor filtra os grupos.
   O que muda é o texto — "Escolha o setor primeiro".
3. **O rótulo público perde a UF** (`CidadeResource::rotulo`): passa a ser só
   `nome`.
4. **O admin continua com o campo UF** na tela de catálogo, com `AL` como
   valor sugerido. A coluna é obrigatória e o `unique` depende dela; esconder o
   campo faria o cadastro de um setor novo falhar sem explicar por quê.
5. **O seeder continua idempotente** — `firstOrCreate` por (`nome`,`uf`) e por
   (`cidade_id`,`nome`), como já é hoje. Ele roda a cada subida do container.
6. **O catálogo antigo não é apagado por código.** O seeder **acrescenta** o
   catálogo real; ele não remove São Paulo nem Campinas de um banco que já os
   tenha. Apagar registro que pode ter inscrição apontando para ele é destrutivo
   e não cabe num seeder. Em ambiente de desenvolvimento o `migrate:fresh` já
   resolve; para produção, escreva no `plan.done.md` o comando de limpeza a ser
   rodado à mão, **sem executá-lo**.

### Arquivos a ler antes de começar

- `database/seeders/CidadeSeeder.php` — a forma do catálogo e o `firstOrCreate`.
- `app/Http/Resources/CidadeResource.php` — o rótulo.
- `app/Http/Requests/StoreInscricaoRequest.php` (linhas 58–59) e
  `app/Http/Requests/Admin/GrupoParticipanteRequest.php` (linhas 46, 57–61) e
  `app/Http/Requests/Admin/CidadeRequest.php` — as mensagens.
- `routes/web.php` (linhas 104–122) — o grupo `catalogo`.
- `app/Http/Controllers/Admin/CidadeController.php`,
  `GrupoParticipanteController.php`,
  `InscricaoAdminController.php` (linha ~190, o rótulo `nome/uf`),
  `ExportarInscricoesController.php` (linha 55, o cabeçalho do CSV).
- `resources/js/pages/Admin/Catalogo/Cidades.vue` e `GruposParticipantes.vue`.
- `resources/js/pages/Admin/Inscricoes/Index.vue`.
- `resources/js/components/inscricao/PassoDadosPessoais.vue` e
  `resources/js/pages/Inscricoes/Criar.vue`.
- `tests/e2e/apoio.ts` (linhas ~52–53) e os quatro specs que citam
  "São Paulo (SP)" ou "Centro".
- `tests/Feature/Admin/CatalogoTest.php` e
  `tests/Feature/Auditoria/AuditoriaTest.php` — os dois usam a rota
  `catalogo.cidades`.
- `docs/PROGRESS.md` — Decisões; a numeração corrente vai até **DA-85**.

## 4. Output Format

| Arquivo | Ação | O quê |
|---|---|---|
| `database/seeders/CidadeSeeder.php` | modificar | O catálogo real no lugar do fictício |
| `app/Http/Resources/CidadeResource.php` | modificar | `rotulo` passa a ser só o nome |
| `app/Http/Requests/StoreInscricaoRequest.php` | modificar | "Escolha o seu setor." / "O setor escolhido não está disponível." |
| `app/Http/Requests/Admin/CidadeRequest.php` | modificar | Mensagens e `attributes` em termos de setor |
| `app/Http/Requests/Admin/GrupoParticipanteRequest.php` | modificar | Idem, inclusive "Já existe um grupo com esse nome neste setor." |
| `routes/web.php` | modificar | `catalogo/cidades` → `catalogo/setores`; nomes de rota e `{cidade}` → `{setor}` |
| `app/Http/Controllers/Admin/CidadeController.php` | modificar | Renderiza `Admin/Catalogo/Setores`; parâmetro `Cidade $setor` |
| `app/Http/Controllers/Admin/GrupoParticipanteController.php` | modificar | Textos e props em termos de setor |
| `app/Http/Controllers/Admin/InscricaoAdminController.php` | modificar | Rótulo do filtro: só o nome, sem `/UF` |
| `app/Http/Controllers/Admin/ExportarInscricoesController.php` | modificar | Cabeçalho do CSV: `Cidade` → `Setor` |
| `resources/js/pages/Admin/Catalogo/Cidades.vue` | renomear + modificar | Vira `Setores.vue`, com os textos trocados |
| `resources/js/pages/Admin/Catalogo/GruposParticipantes.vue` | modificar | "Cidade" → "Setor" em rótulo, coluna e frases |
| `resources/js/pages/Admin/Inscricoes/Index.vue` | modificar | Filtro e coluna |
| `resources/js/components/inscricao/PassoDadosPessoais.vue` | modificar | Rótulo, placeholders e texto de ajuda |
| `resources/js/pages/Inscricoes/Criar.vue` | modificar | Rótulo do resumo e a mensagem do conferidor |
| `resources/js/types/admin.ts` | modificar | Só se algum tipo carregar texto visível |
| `tests/e2e/apoio.ts` + 4 specs | modificar | Dados novos: "Setor Batalha" / "Batalha (Sede)" |
| `tests/Feature/Admin/CatalogoTest.php` | modificar | Rota nova e textos |
| `tests/Feature/Auditoria/AuditoriaTest.php` | modificar | Rota nova |
| `tests/Feature/Publico/CatalogoDeSetoresTest.php` | criar | O seeder semeia 5 e 29, é idempotente, e o rótulo não traz UF |
| `docs/PROGRESS.md` | modificar | Decisões a partir da **DA-86** |

## 5. Quality Criteria

- [ ] **Nenhuma migration**, e `git diff database/migrations/` volta vazio.
- [ ] **Nenhum Model, coluna ou campo de formulário renomeado**: `cidade_id`
      continua sendo o nome do campo enviado e da coluna. Prove rodando a suíte
      de inscrição sem editar nenhum teste que envie `cidade_id`.
- [ ] O seeder cria **exatamente 5** registros em `cidades` e **29** em
      `grupos_participantes`, com a distribuição da seção 3 — e rodá-lo duas
      vezes seguidas não muda esses números.
- [ ] Nenhuma ocorrência de "cidade"/"Cidade" **visível** sobra nas telas
      tocadas. Varra com `grep -rn` por texto entre aspas e por conteúdo de
      elemento; o que sobrar deve ser identificador de código, não texto.
- [ ] `CidadeResource::rotulo` devolve `Setor Batalha`, sem `(AL)`.
- [ ] O CSV exporta a coluna com o cabeçalho `Setor`, e o conteúdo continua o
      mesmo (nome do setor da pessoa).
- [ ] As rotas antigas `/admin/catalogo/cidades` **deixam de existir**. Não
      criar redirecionamento: o sistema não está publicado, ninguém tem esse
      link salvo, e um redirect eterno para uma URL que nunca foi usada é lixo
      nascendo velho.
- [ ] O binding de rota continua funcionando com `{setor}` — o type-hint
      `Cidade $setor` resolve pelo nome do parâmetro. **Prove com um teste que
      edita um setor pela rota nova**, não só pela leitura do código.
- [ ] Acessibilidade: o `<label>` do campo continua ligado por `for`, e o
      `aria-describedby` continua apontando para a ajuda certa. Nenhum alvo
      abaixo de 44px. Contraste AA em qualquer texto novo.
- [ ] A suíte Pest inteira passa. `./vendor/bin/pint --test`,
      `npx vue-tsc --noEmit`, `npm run lint` e `npm run build` limpos.

### Provas

- [ ] **Pest** (`CatalogoDeSetoresTest.php`): contagem 5/29; idempotência; o
      rótulo sem UF; um grupo pertence ao setor certo (escolha
      "Santana do Ipanema (Sede)" e afirme que o pai é "Setor Santana").
- [ ] **Pest**: `CatalogoTest` e `AuditoriaTest` passam pela rota nova, e a
      antiga devolve 404.
- [ ] **Playwright** (`apoio.ts` e os quatro specs): o caminho feliz escolhe
      "Setor Batalha" e depois "Batalha (Sede)". **Escolha de propósito um
      grupo com parêntese e um setor com apóstrofo** em pelo menos um cenário —
      `Setor Olho d'água das Flores` é o caso que quebraria um seletor mal
      escrito, e é dado real.

## 6. Ambiguity Handling

**Decisões do dono do produto nesta entrevista:**

- **Substituir o catálogo fictício pelo real** — o sistema ainda não subiu, e
  testar com dado de verdade acha problema de verdade (apóstrofo, acento,
  parêntese, nome longo).
- **Guardar `AL`, mostrar só o nome.**
- **Renome alcança tela + URLs do admin**, não o código nem o banco.
- **Corrigir o óbvio, listando** cada correção.

**Premissas de quem escreveu o plano:**

- **A query string do filtro administrativo continua `cidade_id`.** "URLs do
  admin" foi entendido como o caminho das rotas de catálogo, não como o nome
  dos parâmetros — que são contrato com o banco e com o `StoreInscricaoRequest`.
  Se o dono do produto quiser `setor_id` na query também, é uma linha a mais,
  mas muda o contrato e merece ser decidido à parte.
- **`Cidades.vue` é renomeado para `Setores.vue`** (arquivo, não só conteúdo),
  porque o nome do arquivo é o que o `inertia()` referencia e ficaria mentindo.
  Use `git mv` para o histórico não se perder.
- O menu lateral **não** lista o catálogo hoje — conferido em
  `AppSidebar.vue`. Se durante a execução aparecer um link para
  `/admin/catalogo/cidades` em alguma tela, ele entra no escopo; **liste-o no
  relatório**.

**Se travar:**

- Se algum teste que você **não** pode editar quebrar por causa do nome do
  campo `cidade_id`, **pare e relate**: significa que o renome vazou para o
  contrato, e o plano diz explicitamente que ele não deve.
- Se o binding `{setor}` → `Cidade` não resolver, **não** contorne com
  `findOrFail` manual: registre e pergunte. É sintoma de o Laravel precisar de
  `Route::model()` explícito, e essa é uma decisão de arquitetura.

## 7. Prohibitions

- ❌ **Nenhuma migration. Nenhuma coluna renomeada. Nenhum Model renomeado.**
- ❌ Não renomear `cidade_id` em formulário, query string, validação ou banco.
- ❌ Não renomear `useGruposDaCidade`, `CidadeResource`, `CidadeController` nem
  variáveis — o renome desta feature para na tela e nas rotas.
- ❌ **Não apagar registro de catálogo por código.** O seeder acrescenta.
- ❌ Não criar redirect das rotas antigas.
- ❌ Não corrigir nome nenhum além dos três listados na seção 3.
- ❌ Não usar `classe-[--variavel]` (Tailwind 3) — na versão 4 é
  `classe-(--variavel)` (**D-86**).
- ❌ Não combinar classe estática com condicional para a mesma propriedade
  (**DA-68**).
- ❌ Não mexer em `app/Services/`, `app/Jobs/` nem no fluxo de pagamento.

---

## Execution Steps

1. **O catálogo.** `CidadeSeeder` recebe os 5 setores e 29 grupos da seção 3,
   com `uf => 'AL'`, mantendo o `firstOrCreate` que o torna idempotente.
   Rode-o duas vezes e confira que os números não mudam.

2. **O rótulo e as mensagens do servidor.** `CidadeResource` devolve só o nome.
   `StoreInscricaoRequest`, `CidadeRequest` e `GrupoParticipanteRequest`
   passam a falar em setor, inclusive no `attributes`.

3. **As rotas.** `catalogo/cidades` → `catalogo/setores`, nomes de rota
   incluídos, e o parâmetro `{cidade}` → `{setor}` com o type-hint ajustado nos
   controllers. Confirme o binding com um teste de edição pela rota nova.

4. **As telas do admin.** `git mv Cidades.vue Setores.vue`, textos trocados
   nela, em `GruposParticipantes.vue` (rótulo, coluna, frases de ajuda) e em
   `Inscricoes/Index.vue` (filtro e coluna). O controller passa a renderizar
   `Admin/Catalogo/Setores`.

5. **O CSV e o filtro.** Cabeçalho `Setor` no `ExportarInscricoesController`; o
   rótulo do filtro no `InscricaoAdminController` perde o `/UF`.

6. **O formulário público.** `PassoDadosPessoais.vue`: rótulo "Setor",
   placeholder "Escolha o seu setor", o condicional do grupo vira "Escolha o
   setor primeiro" e a ajuda, "A lista mostra os grupos do setor escolhido".
   `Criar.vue`: o rótulo do resumo e a mensagem "Escolha o seu setor.".

7. **As provas.** `apoio.ts` e os quatro specs passam a usar
   "Setor Batalha" / "Batalha (Sede)", com um cenário exercitando
   `Setor Olho d'água das Flores`. `CatalogoTest` e `AuditoriaTest` na rota
   nova. `CatalogoDeSetoresTest.php` novo, com contagem, idempotência,
   hierarquia e rótulo.

8. **Fechamento.** Pest inteiro, pint, `vue-tsc`, lint, build, e
   `git diff database/migrations/` vazio. No `plan.done.md`, a lista das três
   correções de digitação e o comando de limpeza do catálogo antigo **para ser
   rodado à mão em produção, não por você**.

9. **Registro.** `docs/PROGRESS.md`, decisões a partir da **DA-86**: por que
   cidade virou setor só na tela e nas rotas e não no banco, por que o rótulo
   perdeu a UF, por que o seeder acrescenta em vez de substituir, e as três
   correções de digitação aplicadas à fonte.

## Done

O formulário pergunta "Setor" e, em seguida, "Grupo" filtrado por ele, com os
cinco setores de Alagoas e seus 29 grupos reais. O administrativo fala setor em
todas as telas e responde em `/admin/catalogo/setores`. O banco não mudou uma
coluna, a suíte Pest inteira passa e `git diff database/migrations/` volta
vazio.

## Commit

`feat(catalogo): tratar cidade como setor e semear o catalogo real`
