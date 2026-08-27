# Execution Report — Migração de `radix-vue` para `reka-ui`

> **Plan:** migracao-reka-ui
> **Executed:** 2026-08-27
> **Status:** ⚠️ WITH CAVEATS

O plano foi executado por inteiro. A ressalva **não é uma falha da migração**: é um
defeito herdado da Etapa 21 (Tailwind 4) que só ficou visível porque este plano exigiu
medir as larguras no navegador. Está descrito em "Ressalva" e registrado no
`docs/PROGRESS.md`. Além dele, dois pontos do plano se revelaram falsos positivos ao
serem inspecionados — também descritos abaixo.

---

## What Was Done

| File | Action | Description |
|---|---|---|
| `package.json` / `package-lock.json` | modify | entrou `reka-ui@^2.10.4`; **saiu `radix-vue`** |
| 74 arquivos em `resources/js/**` | modify | 72 tiveram o `import ... from 'radix-vue'` trocado por `'reka-ui'`; 2 (`ui/toast/use-toast.ts`, `admin/DialogoDeAcao.vue`) só citavam a biblioteca em comentário — o comentário foi atualizado para dizer o que é verdade hoje |
| `resources/js/components/NavUser.vue` | modify | `--radix-dropdown-menu-trigger-width` → `--reka-*` |
| `resources/js/components/ui/navigation-menu/NavigationMenuViewport.vue` | modify | `--radix-navigation-menu-viewport-{height,width}` → `--reka-*` |
| `resources/js/components/ui/select/SelectContent.vue` | modify | `--radix-select-trigger-{height,width}` → `--reka-*` |
| `resources/js/components/inscricao/PassoRevisao.vue` | modify | **o aceite dos termos**: `v-model:checked="aceite"` → `v-model="aceite"` |
| `resources/js/pages/auth/Login.vue` | modify | "lembrar-me": `v-model:checked="form.remember"` → `v-model="form.remember"` |
| `resources/js/components/inscricao/PassoDadosPessoais.vue` | modify | comentário "O Select do radix" → "do reka" |
| `components.json` | modify | alias `Components` → `components` (§14.1); `tailwind.config` apontava para arquivo inexistente e ficou vazio |
| `docs/ARCHITECTURE.md` | modify | **§14 reescrita**: as duas armadilhas deixaram de existir; a tabela das quatro mudanças da v2 ficou registrada; versão 1.6 → 1.7 |
| `docs/PROGRESS.md` | modify | Etapa 22; DA-44 e DA-45; cabeçalho, nota da Etapa 21 e seção de dependências atualizados |
| `.planning/feat/features/migracao-reka-ui/plan.done.md` | create | este relatório |

**Não foram tocados:** `app/`, `routes/`, `database/`, nenhum cenário Playwright, e o
arquivo untracked na raiz (`Prompt para Claude Code — ...md`), que segue untracked.

---

## Quality Criteria

### A troca

| Criterion | Status | Evidence (real output) |
|---|---|---|
| `grep -rn "radix-vue" resources/ package.json` vazio | ✅ | sem saída, `exit 1` |
| `grep -rn "radix-" resources/` vazio | ✅ | sem saída, `exit 1` |
| `grep -rn "v-model:checked\|v-model:pressed" resources/` vazio | ✅ | sem saída, `exit 1` |
| `radix-vue` removido do `package.json` | ✅ | `grep -n "radix-vue\|reka-ui" package.json` → só `40: "reka-ui": "^2.10.4",` |
| `npx vue-tsc --noEmit` → 0 erros | ✅ | sem saída, `EXIT=0`. Nenhum `any`, nenhum `@ts-ignore` acrescentado |

### Comportamento

| Criterion | Status | Evidence |
|---|---|---|
| **O aceite dos termos funciona** | ✅ **provado por cenário** | `caminho-feliz.spec.ts` marca o aceite com `.check()` e segue até o QR Code do Pix. **Prova dos dois lados:** rodado contra o `build` antigo (ainda com `v-model:checked`) ele **falhou** em `waitForURL(/pagamento/)` — o passo imediatamente após o aceite; refeito o `build`, passou em 1,2 s. A rede pega este defeito |
| A seleção de atividade mostra o que está escolhido | ✅ | `caminho-feliz`, `conflito-de-horario`, `maximo-de-selecoes` e `capacidade-esgotada` verdes. Ver "Desvio 1": este ponto **não era** um Checkbox do reka-ui |
| Campo de seleção mantém as medidas | ✅ **medido no navegador** | Sonda em navegador real, formulário de inscrição: gatilho `361px`, `--reka-select-trigger-width` = `361px`, lista abre com `363px` (os 2 px de borda), `--reka-select-trigger-height` = `44px` |
| Menu do usuário mantém as medidas | ⚠️ **medido no navegador — ver Ressalva** | gatilho `212,72px`; `--reka-dropdown-menu-trigger-width` = `212,71875px` (a variável **está correta**); menu abre com `224px` (o `min-w-56`). A largura pedida é descartada pelo navegador — **defeito herdado da Etapa 21, não desta migração** |
| Menu de navegação mantém as medidas | ⚠️ **mesmo defeito, sem efeito prático** | `NavigationMenuViewport` **não é usado em nenhuma tela** (ver Desvio 2), então a medida errada não chega ao usuário |
| Collapsible / NavigationMenu: nada escondido aparece | ✅ | Ver Desvio 2 — nenhum dos quatro componentes de Presence tem uso real. Sonda no navegador: `{"collapsible":0,"navContent":0,"listboxFechado":0}` |
| Diálogos abrem, fecham por Esc e devolvem o foco | ✅ **conferido no navegador** | Menu do usuário: `Escape` → `toHaveCount(0)` e **`FOCO devolvido ao gatilho: true`**. Select: `Escape` → fechado e **`foco devolvido: true`**. Mais os cenários `admin-inscricoes` e `credenciais-pagamento`, que abrem diálogo de confirmação |
| Nenhuma tela mudou de aparência (DA-45) | ✅ | Nenhuma classe de estilo foi alterada — o `git diff` só contém nomes de import, nomes de variável CSS e ligações `v-model`. Os três cenários de `home.spec.ts` que **medem contraste no navegador** seguem verdes, e `acessibilidade-e-responsividade` também |

### Prova

| Criterion | Status | Evidence |
|---|---|---|
| 40 cenários Playwright verdes, sem editar nenhum | ✅ | `npx playwright test` → **`40 passed (50.6s)`**. `git diff` sobre `tests/` está vazio |
| 542 testes Pest verdes | ✅ | `php artisan test` → **`Tests: 542 passed (3807 assertions)`**, idêntico à linha de base |
| `npm run build` conclui | ✅ | `✓ built in 1.68s` |
| A imagem Docker constrói | ✅ | `docker build -t gestao-eventos-ccc:reka-ui-check .` → `naming to docker.io/library/gestao-eventos-ccc:reka-ui-check done`. É onde uma troca de dependência costuma quebrar depois de passar no macOS |
| `npm run lint` limpo | ✅ | `eslint . --fix`, `exit 0`, árvore sem alteração depois dele |
| `git diff --stat` sobre `app/`, `routes/`, `database/` vazio | ✅ | `git diff --stat dca15b5 HEAD -- app/ routes/ database/` → sem saída |

---

## Verification

| Command | Result |
|---|---|
| `npx vue-tsc --noEmit` | exit 0, sem saída |
| `npm run lint` | exit 0 |
| `npm run build` | `✓ built in 1.68s` |
| `php artisan test` | `542 passed (3807 assertions)` em 59,91 s |
| `npx playwright test` | `40 passed (50.6s)` |
| `docker build .` | imagem exportada com sucesso |
| `git diff --stat dca15b5 HEAD -- app/ routes/ database/` | vazio |

---

## O que foi conferido no navegador, com os próprios olhos

Honestidade sobre o método: **não houve inspeção visual manual tela a tela.** A
verificação de navegador foi feita por uma **sonda de Playwright temporária**, escrita em
`tests/e2e/zz-probe-temporaria.spec.ts`, executada em navegador real (Chromium) e
**apagada em seguida** — ela não está em nenhum commit, e nenhum cenário existente foi
tocado. A sonda leu do DOM vivo, com `getComputedStyle` e `boundingBox`:

- **as quatro variáveis CSS**, uma a uma, no elemento onde importam;
- **a largura do gatilho contra a do painel**, no menu do usuário e no campo de seleção;
- **o retorno do foco** depois de `Escape`, comparando com `document.activeElement`;
- **uma varredura de "fantasmas"**: todo elemento com texto que esteja invisível aos
  olhos mas **não** escondido do leitor de tela.

**Resultado da varredura de fantasmas: 1 ocorrência, e é falso positivo.** É o
`div class="group peer hidden md:block"` de `ui/sidebar/Sidebar.vue:56` — o invólucro da
barra lateral, cuja caixa própria é degenerada porque os filhos são `fixed`. O conteúdo
dele **está na tela**; quem tem área zero é o invólucro. Não é componente de Presence.

**O que foi verificado só por comando, não por olho:** que nenhuma tela mudou de
aparência. A base disso é que o `git diff` não contém uma única alteração de classe de
estilo, somada aos cenários que medem contraste e responsividade no navegador. **Uma
conferência visual humana das oito telas principais não foi feita** e continua sendo a
recomendação antes de mesclar.

---

## Ressalva — um defeito da Etapa 21, encontrado aqui e **não corrigido**

`NavUser.vue` e `NavigationMenuViewport.vue` usam a **forma curta** do Tailwind 3 para
variável CSS: `w-[--reka-dropdown-menu-trigger-width]`. **O Tailwind 4 não gera mais essa
forma.** O CSS compilado, lido do bundle:

```css
.w-\[--reka-dropdown-menu-trigger-width\]{width:--reka-dropdown-menu-trigger-width}   /* inválido: falta var() */
.h-\[--reka-navigation-menu-viewport-height\]{height:--reka-navigation-menu-viewport-height}   /* inválido */
.h-\[var\(--reka-select-trigger-height\)\]{height:var(--reka-select-trigger-height)}   /* correto */
```

O navegador descarta as duas primeiras. Medido no navegador: o menu do usuário abre com
`224px` (o `min-w-56`) em vez dos `212,72px` do gatilho.

**Por que não foi corrigido:**

1. **Não é regressão desta migração.** Com o prefixo `--radix-*` o CSS saía igualmente
   inválido — o nome da variável é irrelevante para o defeito, que está na sintaxe do
   utilitário. Antes e depois são idênticos.
2. **Corrigir mudaria a aparência de uma tela** — o menu do usuário encolheria de 224 px
   para 212,7 px. **DA-45 proíbe explicitamente** mudança de aparência neste plano, e a
   §6 do plano manda parar e registrar exatamente nesse caso.
3. `SelectContent.vue`, que já escrevia `var(...)`, **funciona** — e é o único dos três
   que tem uso real numa tela de participante.

**A correção, para a tarefa que vier:** trocar `w-[--var]` por `w-(--var)` (ou
`w-[var(--var)]`) nos dois arquivos. São duas linhas. Merece o "antes e depois" à vista
de quem decide, porque muda o que se vê.

---

## Deviations from Plan

**1. `CartaoDeAtividade.vue:34` NÃO foi convertido — e converter teria quebrado a tela.**

A §3.5 do plano lista `:checked="situacao.selecionada"` como um dos três pontos a
converter para `:model-value`. Na leitura do arquivo, o elemento é um
**`<input type="checkbox">` nativo do HTML**, não um `Checkbox` do reka-ui — o arquivo
sequer importa o componente:

```html
<input type="checkbox" class="peer sr-only" :checked="situacao.selecionada" ... />
```

Em input nativo, `:checked` é o atributo do HTML. Trocá-lo por `:model-value` criaria um
atributo inexistente e a marcação **deixaria de aparecer**. Mantido como está. Os
cenários `caminho-feliz`, `conflito-de-horario`, `maximo-de-selecoes` e
`capacidade-esgotada` confirmam que a seleção de atividade continua correta.

**2. Presence não exigiu nenhuma alteração — os quatro componentes não têm uso real.**

O plano previa conferir Collapsible (4 arquivos) e NavigationMenu (7). Ao rastrear o uso:

- **Accordion e Tabs não existem** no projeto;
- **Collapsible** existe em `components/ui/collapsible/` mas **nenhuma tela o importa** —
  as 4 ocorrências são os próprios arquivos do componente;
- **NavigationMenu** é usado num único lugar, `AppHeader.vue`, e apenas com
  `NavigationMenu`, `NavigationMenuList`, `NavigationMenuItem` e `NavigationMenuLink`.
  **Não há `NavigationMenuTrigger` nem `NavigationMenuContent`** — é uma barra de links,
  sem painel que abra e feche. `NavigationMenuViewport` e `NavigationMenuContent` são
  código morto.

Confirmado no navegador (`{"collapsible":0,"navContent":0}`). **Por isso o step 4 não
gerou commit:** não havia o que corrigir, e um commit vazio afirmaria um trabalho que não
aconteceu. O step 5 também não gerou commit próprio — ele era só verificação, e nenhuma
verificação produziu alteração de arquivo.

**3. `components.json`: uma correção a mais do que o plano pediu.**

Além do alias minúsculo (§3.7), a chave `tailwind.config` apontava para
`tailwind.config.js` — arquivo **removido de propósito na Etapa 21**. Ficou vazia, que é
o que a ferramenta espera de um projeto em Tailwind 4. Sem essa correção o gerador ainda
falharia, e o objetivo declarado do §3.7 é justamente "deixar a porta destrancada".
Mudança de configuração pura: **nada em tempo de execução lê o `components.json`**. O
gerador **não foi rodado** (DA-44).

**4. Um comentário desatualizado ficou de fora, de propósito.**

`tests/e2e/apoio.ts:57` ainda diz "As listas de escolha sao do radix-vue". É comentário
em arquivo de apoio dos cenários, fora do `resources/js/**` da §4, e por isso não foi
tocado — os critérios de grep do plano são sobre `resources/`. Fica a recomendação de
atualizá-lo numa próxima passada por `tests/`.

**Nenhuma proibição da §7 foi violada:** o gerador do shadcn não foi executado, nenhuma
aparência foi alterada, nenhum cenário Playwright foi editado, `app/`/`routes/`/`database/`
não foram tocados, `radix-vue` saiu do `package.json`, e nenhum erro de `vue-tsc` foi
silenciado.

---

## Commit

Quatro commits, um por step com conteúdo real:

| Hash | Message | Files |
|---|---|---|
| `b52d071` | `refactor(ui): migrate imports from radix-vue to reka-ui` | `package.json`, `package-lock.json`, 74 arquivos em `resources/js/**` |
| `d7bf259` | `fix(ui): update css variables to the reka prefix` | `NavUser.vue`, `NavigationMenuViewport.vue`, `SelectContent.vue`, `PassoDadosPessoais.vue` |
| `69d535a` | `fix(ui): update checkbox bindings to v-model` | `PassoRevisao.vue`, `Login.vue` |
| *(último)* | `docs(ui): close the reka-ui migration` | `components.json`, `docs/ARCHITECTURE.md`, `docs/PROGRESS.md`, `plan.done.md` |

Steps 4 e 5 não produziram commit — pelas razões dos desvios 2 e 3 acima.
