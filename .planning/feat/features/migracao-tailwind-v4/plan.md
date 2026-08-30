# Action Plan — Migração para Tailwind v4 e adoção do tema do shadcn studio

> **Type:** feature
> **Created:** 2026-08-27
> **Status:** pending
> **Ordem:** **Plano A de dois.** O plano B (`radix-vue` → `reka-ui`) vem depois e é
> independente. Este mexe em CSS, tokens e build; o B mexe em imports e componentes.
> **Não execute os dois juntos** — se um cenário quebrar, você precisa saber qual deles foi.

---

## 1. Persona & Scope

**Persona:** Frontend Engineer **Vue 3.5 + Tailwind + TypeScript**, com prática em migração
de versão maior de framework de CSS. Sabe que o perigo de uma migração dessas não é o erro
que aparece — é o que **não** aparece: uma classe renomeada que deixa de existir e some sem
avisar, levando junto o espaçamento, a sombra ou o contraste de uma tela que ninguém abriu
naquele dia.

**Scope:** subir o Tailwind de **3.4.1 para 4.x** e adotar a paleta do shadcn studio.

| Entrega | Neste plano |
|---------|:----------:|
| Tailwind **4.x** com `@tailwindcss/vite` no lugar do PostCSS | ✅ |
| `resources/css/app.css` reescrito na sintaxe v4 (`@theme`, `@custom-variant`) | ✅ |
| `tailwind.config.js` **removido** (v4 é CSS-first) | ✅ |
| Paleta do studio adotada nos tokens shadcn | ✅ |
| Cores semânticas (`acao`, `sucesso`, `informacao`, `atencao`) **rederivadas** do tema novo, **com contraste recalculado** (§3.4) | ✅ |
| `tw-animate-css` no lugar de `tailwindcss-animate` | ✅ |
| Varredura das classes renomeadas na v4 nos 187 arquivos (§3.5) | ✅ |
| Modo escuro preservado | ✅ |
| **Troca de `radix-vue` por `reka-ui`** | ❌ **plano B** |
| Regenerar os 23 componentes pelo CLI | ❌ **proibido** (§7) — é o plano B, e o CLI traria `reka-ui` junto |
| Qualquer mudança em backend, regra ou rota | ❌ **proibido** |

**Stack:** Vue 3.5 · Inertia · TypeScript · Tailwind 4 · Vite · Pest 4 · Playwright.

---

## 2. Direct Objective

O projeto passa a compilar com Tailwind 4 e a vestir o tema do shadcn studio, **sem perder
nenhuma das garantias de acessibilidade que a Fase 5a e a 6a construíram**: todo texto
continua com contraste suficiente, e os três cenários que medem contraste no navegador
continuam verdes.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas — **NÃO reabrir**

| # | Tema | Decisão | Origem |
|---|------|---------|--------|
| **DA-39** | Versão | **Tailwind 4.x.** Decisão de quem encomendou, tomada com a ressalva de risco já apresentada e reafirmada | usuário |
| **DA-40** | Paleta | **Adotar o tema do shadcn studio**, inclusive **substituindo as cores semânticas atuais**. Decisão de quem encomendou, reafirmada depois de a consequência ser exposta | usuário |
| **DA-41** | O que o studio não cobre | O tema do studio tem `primary`, `destructive`, `chart-*` e `sidebar-*`, mas **não tem "sucesso" nem "atenção"** — e o projeto usa as quatro cores semânticas em 51 arquivos. Elas serão **derivadas do novo esquema**, não apagadas (§3.4) | consequência técnica da DA-40 |
| **DA-42** | Acessibilidade | **Não é negociável.** Toda cor semântica nova precisa de razão de contraste calculada e escrita ao lado, como o `app.css` atual faz. Se um tom do studio reprovar em AA, ele é **escurecido até passar** — a decisão foi adotar o tema, não abrir mão de acessibilidade | §3.4 |
| **DA-43** | Duas migrações, dois planos | `reka-ui` fica para o plano B | usuário |

### 3.2 O efeito visível que isto causa

Com `--primary` passando a ser o azul do studio e as semânticas derivadas dele, **o botão
"Fazer inscrição" deixa de ser vermelho**. A home, a tela de cobrança, o painel e a tela de
credenciais mudam de cara.

Isso é consequência esperada da DA-40, não defeito. Está escrito aqui para que ninguém, ao
ver o resultado, ache que quebrou.

### 3.3 O que existe hoje

| O quê | Onde | Número |
|---|---|---|
| Arquivos `.vue` | `resources/js/` | **187** |
| Arquivos usando os tokens semânticos | `resources/js/` | **51** |
| Arquivos usando `sidebar-*` | `resources/js/` | 17 |
| Componentes de interface | `resources/js/components/ui/` | 23 |
| Cenários que **medem contraste no navegador** | `tests/e2e/` | **3 arquivos** — `acessibilidade-do-participante`, `acessibilidade-e-responsividade`, `home` |
| Uso de `chart-*` | — | **nenhum** — não precisa portar |

**Build hoje:** `vite.config.ts` roda `tailwindcss` e `autoprefixer` via PostCSS.
**Na v4** isso é substituído pelo plugin `@tailwindcss/vite`, e o autoprefixer deixa de ser
necessário (a v4 já resolve prefixos).

### 3.4 As cores — o coração deste plano

O `app.css` atual não tem só valores: tem **o motivo de cada valor escrito ao lado**. Um
exemplo real, que precisa continuar existindo em espírito:

> *"O tom original do starter kit (#EF4444) rende apenas 3.76:1 sobre branco e reprova em
> texto pequeno — e é justamente em texto pequeno que o participante lê 'Este CPF não
> parece válido'. Escurecido para #D31212, o mesmo aviso passa a 5.43:1."*

**O que fazer:**

1. Portar os tokens do studio como vieram: `background`, `foreground`, `card`, `popover`,
   `primary`, `secondary`, `muted`, `accent`, `destructive`, `border`, `input`, `ring`,
   `sidebar-*`, sombras e raios. Claro e escuro.
2. **Derivar as quatro semânticas** do novo esquema azul, mantendo o significado:
   `acao` (a ação principal), `sucesso` (disponível, confirmado), `informacao` (navegação,
   avisos neutros), `atencao` (prazo correndo).
3. **Calcular a razão de contraste de cada uma** contra o fundo em que ela é usada, no modo
   claro **e** no escuro. Escrever o número no comentário, como hoje.
4. Onde o tom escolhido reprovar em **AA (4.5:1 para texto normal)**, escurecer até passar e
   registrar o ajuste — exatamente o que foi feito com o `--destructive` (DA-42).
5. Manter as variantes `-texto` e `-contraste`: elas existem porque a cor que serve de fundo
   e a que serve de texto **não podem ser a mesma**.

**Ferramenta de verificação:** os três cenários da §3.3 já medem contraste real no
navegador. Rode-os cedo e com frequência, não só no fim.

### 3.5 As mudanças da v4 que quebram em silêncio

Esta é a parte que mais provavelmente produz regressão sem erro. **Varra os 187 arquivos**
procurando cada uma:

| v3 | v4 | Risco |
|---|---|---|
| `shadow-sm` | `shadow-xs` | a sombra some |
| `shadow` | `shadow-sm` | idem |
| `rounded-sm` | `rounded-xs` | canto muda |
| `blur-sm` / `blur` | `blur-xs` / `blur-sm` | idem |
| `outline-none` | `outline-hidden` | **foco do teclado some** — grave para acessibilidade |
| `ring` | `ring-3` | anel de foco muda de espessura |
| `bg-opacity-50` etc. | `bg-black/50` | a opacidade deixa de ser aplicada |
| `flex-shrink-*` / `flex-grow-*` | `shrink-*` / `grow-*` | layout muda |
| `overflow-ellipsis` | `text-ellipsis` | texto não trunca |
| `decoration-slice` | `box-decoration-slice` | — |

Atenção especial ao **`outline-none` → `outline-hidden`** e ao **`ring`**: os dois afetam o
**foco visível**, que é critério de acessibilidade e está coberto pelos cenários.

Outras diferenças a conferir: a v4 exige `@import "tailwindcss"` no lugar das três diretivas
`@tailwind`; o modo escuro por classe precisa de `@custom-variant dark (&:is(.dark *))`; e
o `space-x/y-*` mudou de seletor, o que pode alterar espaçamento em listas.

### 3.6 Arquivos a ler antes de escrever

`resources/css/app.css` (todo — e leia os comentários, eles explicam as escolhas) ·
`tailwind.config.js` (o que precisa ser portado para `@theme`) · `vite.config.ts` ·
`package.json` · `resources/js/components/ui/button/index.ts` (as variantes) ·
`resources/js/pages/Home.vue` (usa `bg-acao`) ·
`resources/js/layouts/PublicoLayout.vue` ·
os três cenários de contraste da §3.3 · `docs/ARCHITECTURE.md` §14 (por que os componentes
não podem ser regenerados pelo CLI aqui)

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `package.json` / `package-lock.json` | modify | `tailwindcss` ^4, `@tailwindcss/vite`, `tw-animate-css`; saem `tailwindcss-animate` e `autoprefixer` |
| `vite.config.ts` | modify | plugin `@tailwindcss/vite` no lugar do PostCSS |
| `postcss.config.js` | delete (se existir) | a v4 não usa |
| `resources/css/app.css` | **rewrite** | sintaxe v4; tema do studio; as quatro semânticas derivadas **com contraste calculado e escrito** (§3.4) |
| `tailwind.config.js` | **delete** | v4 é CSS-first; o que ele tinha vai para `@theme` |
| `resources/js/**/*.vue` | modify | apenas as classes renomeadas da §3.5 — **nenhuma mudança de layout por gosto** |
| `resources/js/components/ui/**` | modify | idem; **sem trocar `radix-vue`** (plano B) |
| `docs/ARCHITECTURE.md` | modify | §14 atualizada: o projeto passa a ser Tailwind 4 e a §14.2 continua valendo até o plano B |
| `docs/PROGRESS.md` | modify | Etapa 21; decisões DA-39 a DA-43 |
| `.planning/feat/features/migracao-tailwind-v4/plan.done.md` | create | relatório |

---

## 5. Quality Criteria

### Build

- [ ] `npm run build` conclui **sem aviso de classe desconhecida**
- [ ] `npm run dev` sobe e recarrega
- [ ] `npx vue-tsc --noEmit` → 0 erros
- [ ] `npm run lint` limpo
- [ ] **A imagem Docker constrói** — o `Dockerfile` roda `npm run build` no estágio de
      assets, e uma migração de build que passa localmente e quebra na imagem é o pior dos
      dois mundos

### Cores e acessibilidade — **os critérios que mandam neste plano**

- [ ] Cada uma das quatro semânticas tem **razão de contraste calculada e escrita** no
      comentário, para claro **e** escuro (§3.4)
- [ ] **Nenhuma cor de texto abaixo de 4.5:1** sobre o fundo em que é usada
- [ ] Os **três cenários que medem contraste** continuam verdes, **sem edição**
- [ ] O **foco visível** continua aparecendo em todo elemento focável — é o que o
      `outline-none` → `outline-hidden` mais ameaça (§3.5)
- [ ] O **modo escuro** continua funcionando em todas as telas
- [ ] As quatro semânticas continuam existindo e **significando a mesma coisa** (DA-41)

### Regressão visual

- [ ] **Nenhuma ocorrência remanescente** das classes v3 da tabela §3.5 — provar com `grep`
      por cada uma, e mostrar a saída vazia
- [ ] As telas principais conferidas no navegador, em **360 px e em desktop**: home,
      vitrine, formulário de inscrição, tela de cobrança, painel, lista de inscrições, tela
      de credenciais
- [ ] Os **40 cenários Playwright verdes, sem editar nenhum**

### O que não pode ter mudado

- [ ] `git diff --stat` sobre `app/`, `routes/`, `database/` → **vazio**. Este plano não
      toca no backend
- [ ] **Nenhum arquivo passou a importar `reka-ui`** — provar com `grep -rn "reka-ui"
      resources/`, saída vazia (é o plano B)
- [ ] **542 testes Pest** continuam verdes (eles não dependem de CSS, mas provam que nada
      além do previsto foi tocado)

---

## 6. Ambiguity Handling

**Assumptions made:**

- **`chart-*` é portado mesmo sem uso hoje** — é barato e evita que o próximo gráfico nasça
  com paleta inventada.
- **As sombras e os raios do studio entram como vieram.** São decisão estética, e a estética
  foi escolhida por quem encomendou.
- **A fonte não muda.** O studio disse "no custom fonts"; o projeto usa Instrument Sans e
  continua com ela.
- **Nenhuma tela é redesenhada.** Este plano troca a base e as cores; layout, espaçamento e
  hierarquia ficam como estão. Se algo parecer feio depois, é assunto de outro trabalho.

**If unsure during execution:**

- **Se uma cor do studio reprovar em contraste, escureça e registre** (DA-42). Não deixe
  passar "porque veio do tema".
- **Se um cenário de acessibilidade quebrar, o defeito é da migração** — corrija a cor ou a
  classe, nunca o cenário.
- **Se aparecer vontade de rodar o CLI do shadcn, PARE.** Ele traria `reka-ui` e misturaria
  os dois planos (`docs/ARCHITECTURE.md` §14.2).
- **Se algo exigir mexer em backend, PARE.** Não é este plano.
- **Commite ao fim de cada step.**

---

## 7. Prohibitions

- ❌ **NUNCA** trocar `radix-vue` por `reka-ui` aqui — é o plano B
- ❌ **NUNCA** rodar `npx shadcn@latest init` nem `shadcn-vue add`
- ❌ **NUNCA** editar cenário Playwright existente
- ❌ **NUNCA** aceitar cor de texto abaixo de 4.5:1
- ❌ **NUNCA** remover as cores semânticas sem substituto equivalente (DA-41)
- ❌ **NUNCA** tocar em `app/`, `routes/`, `database/`
- ❌ **NUNCA** redesenhar tela, mudar layout ou "melhorar" espaçamento de passagem
- ❌ **NUNCA** deixar classe v3 da tabela §3.5 para trás

---

## Execution Steps

1. **Dependências e build.** `tailwindcss` ^4, `@tailwindcss/vite`, `tw-animate-css`; saem
   `tailwindcss-animate` e `autoprefixer`; `vite.config.ts` passa a usar o plugin. Ao fim,
   `npm run build` tem de concluir — ainda que a aparência esteja errada, o que é esperado
   antes do step 2.
   → commit `build(css): migrate to tailwind v4 toolchain`

2. **O tema.** `app.css` reescrito: `@import "tailwindcss"`, `@custom-variant dark`,
   `@theme`, os tokens do studio em claro e escuro, e as quatro semânticas derivadas **com
   a razão de contraste calculada e escrita ao lado** (§3.4). `tailwind.config.js` removido.
   → commit `feat(css): adopt the studio theme on tailwind v4`

3. **As classes renomeadas.** Varredura das dez da tabela §3.5 nos 187 arquivos. Cada
   substituição conferida — e ao fim, um `grep` por cada classe antiga com saída vazia.
   Atenção redobrada a `outline-none` e `ring`, que mexem no foco visível.
   → commit `refactor(css): update renamed utility classes`

4. **Contraste e foco, provados.** Rodar os três cenários de acessibilidade; corrigir a
   **cor**, nunca o cenário. Conferir foco visível em teclado nas telas principais.
   → commit `fix(css): keep contrast and focus within WCAG AA`

5. **A prova.** Os 40 cenários Playwright, os 542 testes Pest, lint, vue-tsc, `npm run
   build` e **o build da imagem Docker**. Conferir as sete telas da §5 em 360 px e desktop.
   → commit `test(css): prove the migration in the browser`

6. **Documentação e fechamento.** `docs/ARCHITECTURE.md` §14 atualizada, `docs/PROGRESS.md`
   (Etapa 21, DA-39 a DA-43, e o registro de que **o plano B — `reka-ui` — continua
   pendente**) e o relatório.
   → commit `docs(css): close the tailwind v4 migration`

---

## Done

O projeto compila com Tailwind 4, veste o tema do shadcn studio, e **nenhum texto ficou
abaixo do contraste mínimo** — com os três cenários que medem contraste no navegador verdes
e sem edição, os 40 cenários Playwright verdes, o modo escuro funcionando e a imagem Docker
construindo. O `radix-vue` continua no lugar, à espera do plano B.

## Commit

`feat(css): migrate to tailwind v4 and adopt the studio theme`
