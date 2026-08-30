# Action Plan — Migração de `radix-vue` para `reka-ui`

> **Type:** feature
> **Created:** 2026-08-27
> **Status:** pending
> **Ordem:** **Plano B de dois.** Depende do plano A (`migracao-tailwind-v4`), já concluído.
> Aquele trocou a base de CSS e as cores; este troca a **biblioteca de primitivas** — o
> comportamento dos componentes. Separados de propósito.

---

## 1. Persona & Scope

**Persona:** Frontend Engineer **Vue 3.5 + TypeScript**, com prática em troca de biblioteca
de componentes. Sabe que renomear um import é a parte fácil e chata, e que o perigo mora nas
três ou quatro APIs que mudaram de forma — porque essas não dão erro de compilação: elas
compilam, sobem, e param de funcionar na mão do usuário.

**Scope:** trocar `radix-vue` v1 por `reka-ui` v2 em todo o projeto.

| Entrega | Neste plano |
|---------|:----------:|
| `reka-ui` instalado, `radix-vue` removido | ✅ |
| **74 arquivos** com o import trocado | ✅ |
| **Variáveis CSS `--radix-*` → `--reka-*`** (§3.4) | ✅ |
| **`v-model:checked` → `v-model`** nos 3 pontos (§3.5) | ✅ |
| Componentes com Presence conferidos (§3.6) | ✅ |
| `components.json` corrigido — alias e geração (§3.7) | ✅ |
| `docs/ARCHITECTURE.md` §14.2 reescrita: a armadilha deixa de existir | ✅ |
| Redesenhar componente ou mudar aparência | ❌ **proibido** (§7) |
| Regenerar os 23 componentes pelo CLI | ❌ **proibido** — perderia as adaptações do projeto |
| Backend, rotas, migrations | ❌ **proibido** |

**Stack:** Vue 3.5 · Inertia · TypeScript · Tailwind 4 · reka-ui 2 · Pest 4 · Playwright.

---

## 2. Direct Objective

Os 23 componentes de interface passam a usar `reka-ui` v2 **sem que nenhuma tela mude de
aparência nem de comportamento** — e, com isso, o projeto volta a poder usar o gerador
oficial do shadcn-vue, que hoje é incompatível (`docs/ARCHITECTURE.md` §14.2).

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas

| # | Tema | Decisão | Origem |
|---|------|---------|--------|
| **DA-43** | Dois planos | Este é o B. O A (Tailwind 4) já está concluído | usuário |
| **DA-44** | Sem regenerar | Os 23 componentes são **adaptados**, não regerados pelo CLI. Vários têm ajuste próprio do projeto — comentários em português, correções de acessibilidade — que o gerador apagaria | §3.7 |
| **DA-45** | Aparência congelada | Este plano **não muda uma linha de aparência**. Se uma tela mudar visualmente, é defeito | §2 |

### 3.2 O que a documentação oficial diz que muda

Consultado em **2026-08-27**, no guia de migração do reka-ui
(`docs/content/docs/guides/migration.md`). São **quatro** mudanças, e só a primeira é trivial:

| # | Mudança | Onde dói |
|---|---|---|
| 1 | `import ... from 'radix-vue'` → `'reka-ui'` | 74 arquivos, mecânico |
| 2 | **`--radix-*` → `--reka-*`** e `[data-radix-*]` → `[data-reka-*]` | §3.4 — **quebra em silêncio** |
| 3 | **`v-model:checked` → `v-model`**; prop `checked` → `:model-value` | §3.5 — **quebra o aceite dos termos** |
| 4 | **Presence**: Accordion, Collapsible, Tabs e NavigationMenu passam a renderizar mesmo inativos (`forceMount`), e a visibilidade precisa ser controlada com `hidden` | §3.6 |

### 3.3 O que existe hoje

| O quê | Número |
|---|---|
| Arquivos importando `radix-vue` | **74** |
| Componentes em `components/ui/` | 23 |
| Usos de `--radix-*` em classe Tailwind | **4 ocorrências, em 3 arquivos** (§3.4) |
| Usos de `v-model:checked` / `:checked` | **3** (§3.5) |
| Arquivos com Collapsible | 4 |
| Arquivos com NavigationMenu | 7 |
| Símbolos mais importados | `useForwardPropsEmits` (12), `useForwardProps` (11), `Primitive` (7) |

### 3.4 As variáveis CSS — a quebra silenciosa

Quatro ocorrências, todas dentro de classe Tailwind arbitrária. **Nenhuma delas dá erro
quando quebra** — o elemento simplesmente perde a medida e fica do tamanho errado:

| Arquivo | O que quebra se ficar para trás |
|---|---|
| `components/NavUser.vue:25` — `w-[--radix-dropdown-menu-trigger-width]` | o menu do usuário deixa de acompanhar a largura do gatilho |
| `components/ui/navigation-menu/NavigationMenuViewport.vue:27` — `h-[--radix-navigation-menu-viewport-height]` e `w-[--radix-navigation-menu-viewport-width]` | o painel do menu perde altura e largura |
| `components/ui/select/SelectContent.vue:45` — `h-[var(--radix-select-trigger-height)]` e `w-[var(--radix-select-trigger-width)]` | a lista do campo de seleção não acompanha o campo |

**Prova exigida:** `grep -rn "radix-" resources/` sem nenhuma ocorrência que não seja
comentário em texto.

### 3.5 `v-model:checked` — e por que este é o ponto mais grave do plano

Três ocorrências. A ordem aqui é de gravidade, não alfabética:

| Arquivo | O que é | Se quebrar |
|---|---|---|
| **`components/inscricao/PassoRevisao.vue:113`** — `v-model:checked="aceite"` | **o aceite dos termos** do formulário de inscrição | **ninguém consegue se inscrever.** É a última etapa antes de enviar |
| `components/inscricao/CartaoDeAtividade.vue:34` — `:checked="situacao.selecionada"` | a marcação de atividade escolhida | a pessoa não vê o que selecionou |
| `pages/auth/Login.vue:76` — `v-model:checked="form.remember"` | "lembrar-me" no login administrativo | incômodo, sem gravidade |

A conversão: `v-model:checked="x"` → `v-model="x"`, e `:checked="x"` → `:model-value="x"`.

**Os cenários Playwright do caminho feliz cobrem o aceite dos termos** — se a conversão
estiver errada, eles quebram. É a rede, e ela funciona: confie nela, mas confira você também.

### 3.6 Presence — Collapsible e NavigationMenu

A v2 monta o conteúdo mesmo quando inativo. Onde o projeto usa **Collapsible** (4 arquivos)
e **NavigationMenu** (7), conferir que:

- nada que devia estar escondido aparece na tela;
- nada que devia estar escondido aparece para **leitor de tela** — conteúdo montado e
  visível ao leitor, mas invisível aos olhos, é pior do que quebrado;
- a navegação por teclado não passa por dentro de painel fechado.

Os cenários de acessibilidade (`acessibilidade-do-participante`,
`acessibilidade-e-responsividade`) ajudam, mas **não substituem** abrir e fechar o menu com
o teclado.

### 3.7 O `components.json`

Depois desta migração, o projeto passa a ser compatível com o gerador oficial. Aproveite:

1. **Corrigir o alias** de `resources/js/Components` para `resources/js/components` — a
   armadilha §14.1 do `docs/ARCHITECTURE.md`, que só morde em Linux.
2. **Não rodar o gerador** mesmo assim neste plano (DA-44). O objetivo aqui é deixar a porta
   destrancada, não passar por ela.

### 3.8 Arquivos a ler antes de escrever

`docs/ARCHITECTURE.md` **§14 inteira** · `components.json` ·
`resources/js/components/ui/button/Button.vue` (o padrão de como um componente usa a
primitiva) · os três arquivos da §3.4 · os três da §3.5 ·
`resources/js/components/ui/collapsible/` e `navigation-menu/` ·
`tests/e2e/caminho-feliz.spec.ts` (é ele que cobre o aceite dos termos) ·
`package.json`

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `package.json` / `package-lock.json` | modify | entra `reka-ui` ^2; sai `radix-vue` |
| `resources/js/**/*.{vue,ts}` | modify | os 74 imports; as 4 variáveis CSS; os 3 `v-model:checked` |
| `components.json` | modify | alias corrigido para minúsculo (§3.7) |
| `docs/ARCHITECTURE.md` | modify | **§14.2 reescrita**: a incompatibilidade deixou de existir; §14.1 registrada como resolvida |
| `docs/PROGRESS.md` | modify | Etapa 22; decisões DA-43 a DA-45 |
| `.planning/feat/features/migracao-reka-ui/plan.done.md` | create | relatório |

---

## 5. Quality Criteria

### A troca

- [ ] `grep -rn "radix-vue" resources/ package.json` → **nenhuma ocorrência** (comentário em
      texto explicando história pode ficar, desde que não seja import)
- [ ] `grep -rn "radix-" resources/` → **nenhuma variável CSS nem data attribute** (§3.4)
- [ ] `grep -rn "v-model:checked\|v-model:pressed" resources/` → **vazio** (§3.5)
- [ ] `radix-vue` **removido** do `package.json`
- [ ] `npx vue-tsc --noEmit` → **0 erros** — é o melhor detector de símbolo que mudou de nome

### Comportamento — o que realmente importa aqui

- [ ] **O aceite dos termos funciona**: marcar e desmarcar, e o envio só passa com ele
      marcado (§3.5). Provado por cenário, não por leitura
- [ ] A seleção de atividade mostra o que está escolhido
- [ ] O menu do usuário, o campo de seleção e o menu de navegação **mantêm as medidas**
      (§3.4) — conferir no navegador, não só no código
- [ ] Collapsible e NavigationMenu: nada escondido aparece na tela nem para leitor de tela; o
      teclado não entra em painel fechado (§3.6)
- [ ] Diálogos abrem, fecham por Esc e devolvem o foco a quem os abriu
- [ ] **Nenhuma tela mudou de aparência** (DA-45)

### Prova

- [ ] **40 cenários Playwright verdes, sem editar nenhum**
- [ ] **542 testes Pest verdes**
- [ ] `npm run build` conclui e **a imagem Docker constrói**
- [ ] `npm run lint` limpo
- [ ] `git diff --stat` sobre `app/`, `routes/`, `database/` → **vazio**

---

## 6. Ambiguity Handling

**Assumptions made:**

- **Símbolo que não existir mais em `reka-ui` é adaptado, não contornado.** Se um nome mudou,
  use o novo; não reescreva o componente para não precisar dele.
- **O `components.json` é corrigido, mas o gerador não é usado** (§3.7).
- **Comentário que menciona `radix-vue` contando a história do projeto pode ficar**, desde
  que atualizado para dizer o que é verdade hoje.

**If unsure during execution:**

- **Se um cenário quebrar, o defeito é da migração.** Não edite o cenário.
- **Se `vue-tsc` acusar símbolo inexistente**, consulte a documentação do reka-ui antes de
  inventar equivalente.
- **Se algo exigir mudar aparência para funcionar, PARE e registre.** Pode ser sinal de que
  a API mudou de forma que o plano não previu.
- **Commite ao fim de cada step.**

---

## 7. Prohibitions

- ❌ **NUNCA** rodar `npx shadcn-vue add` ou `shadcn init` — regeneraria os 23 componentes e
  apagaria as adaptações do projeto (DA-44)
- ❌ **NUNCA** mudar aparência, layout ou espaçamento (DA-45)
- ❌ **NUNCA** editar cenário Playwright existente
- ❌ **NUNCA** tocar em `app/`, `routes/`, `database/`
- ❌ **NUNCA** deixar `radix-vue` no `package.json` "por segurança" — duas bibliotecas de
  primitivas no mesmo bundle é exatamente o que este plano existe para evitar
- ❌ **NUNCA** silenciar erro do `vue-tsc` com `any` ou `@ts-ignore`

---

## Execution Steps

1. **A dependência e os imports.** Instalar `reka-ui`, remover `radix-vue`, trocar o import
   nos 74 arquivos. Ao fim, `npx vue-tsc --noEmit` com **0 erros** — é o que prova que
   nenhum símbolo sumiu.
   → commit `refactor(ui): migrate imports from radix-vue to reka-ui`

2. **As variáveis CSS.** As 4 ocorrências da §3.4, `--radix-*` → `--reka-*`. Conferir **no
   navegador** que menu, seleção e navegação mantêm as medidas.
   → commit `fix(ui): update css variables to the reka prefix`

3. **O aceite dos termos e as demais marcações.** Os 3 pontos da §3.5, na ordem de gravidade.
   Rodar `caminho-feliz.spec.ts` logo depois — é ele que cobre o aceite.
   → commit `fix(ui): update checkbox bindings to v-model`

4. **Presence.** Collapsible e NavigationMenu conferidos (§3.6), inclusive com teclado e
   leitor de tela.
   → commit `fix(ui): keep hidden content hidden after the presence change`

5. **A prova.** Os 40 cenários, os 542 testes, lint, vue-tsc, build e **a imagem Docker**.
   Conferir no navegador as telas com componente interativo: formulário de inscrição
   (o aceite!), painel, lista de inscrições, tela de credenciais.
   → commit `test(ui): prove the reka-ui migration`

6. **Documentação e fechamento.** `components.json` corrigido; **`docs/ARCHITECTURE.md` §14
   reescrita** — as duas armadilhas deixaram de existir, e o projeto volta a poder usar o
   gerador; `docs/PROGRESS.md` (Etapa 22, DA-43 a DA-45) e o relatório.
   → commit `docs(ui): close the reka-ui migration`

---

## Done

Nenhum arquivo importa `radix-vue`, nenhuma variável CSS carrega o prefixo antigo, o aceite
dos termos funciona, nada escondido aparece — e as telas estão exatamente como estavam.
Com os 40 cenários Playwright e os 542 testes Pest verdes, sem edição, a imagem Docker
construindo, e a seção 14 do `ARCHITECTURE.md` finalmente podendo dizer que o gerador
oficial voltou a servir.

## Commit

`refactor(ui): migrate from radix-vue to reka-ui`
