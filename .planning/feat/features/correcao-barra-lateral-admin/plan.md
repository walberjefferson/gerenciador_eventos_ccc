# Action Plan — Barra lateral administrativa por cima do conteúdo

> **Type:** feature
> **Created:** 2026-08-28 11:34
> **Status:** pending

---

## Diagnóstico (feito antes do plano, e confirmado)

O defeito **não é de layout** — é uma classe do Tailwind que deixou de existir e virou
CSS inválido, sem nenhum erro em lugar nenhum.

No Tailwind 3, `w-[--sidebar-width]` significava "largura igual a essa variável CSS". Na
versão 4 essa forma foi trocada por `w-(--sidebar-width)`, e a antiga passou a ser lida
como valor literal. O compilador **não reclama**: ele gera a regra assim mesmo.

Isto está, agora, dentro de `public/build/assets/app-EmVd6YPR.css`:

```css
.w-\[--sidebar-width\]{width:--sidebar-width}   /* valor inválido — o navegador descarta */
```

Sem largura declarada:

1. o `<div>` que existe **só** para reservar o espaço da barra lateral fica com zero de
   largura, e o conteúdo se espalha por baixo dela;
2. a barra, que é `position: fixed` com `z-10`, passa a **flutuar por cima** do conteúdo;
3. o botão de recolher fica no canto esquerdo do cabeçalho — **exatamente embaixo da
   barra**. Ele existe, está montado e funciona; ninguém consegue alcançá-lo.

Os três sintomas relatados são o mesmo defeito visto de três ângulos.

**Por que 40 cenários de navegador não pegaram isto:** o `playwright.config.ts` declara
**um único projeto, `celular` (Pixel 5, 393px de largura)**. Abaixo de 768px o componente
`Sidebar` nem chega a renderizar esse trecho — ele vira uma gaveta (`Sheet`), que não usa
nenhuma das classes quebradas. O caminho defeituoso **só existe em tela grande**, e tela
grande nunca foi exercitada.

**Alcance real:** a varredura do CSS construído encontrou **seis declarações inválidas**,
vindas de **dez ocorrências em quatro arquivos** — a barra lateral é a mais visível, mas
o menu da conta e o menu de navegação carregam o mesmo defeito.

| Arquivo | Linhas | Classe quebrada |
|---|---|---|
| `resources/js/components/ui/sidebar/Sidebar.vue` | 32, 43, 66, 78 | `w-[--sidebar-width]` |
| `resources/js/components/ui/sidebar/Sidebar.vue` | 71, 85 | `w-[--sidebar-width-icon]` |
| `resources/js/components/NavUser.vue` | 25 | `w-[--reka-dropdown-menu-trigger-width]` |
| `resources/js/components/ui/navigation-menu/NavigationMenuViewport.vue` | 27 | `h-[--reka-navigation-menu-viewport-height]`, `w-[--reka-navigation-menu-viewport-width]` |
| `resources/js/components/ui/sidebar/SidebarMenuSkeleton.vue` | 20 | `max-w-[--skeleton-width]` |

---

## 1. Persona & Scope

**Persona:** Desenvolvedora frontend sênior em Vue 3.5 (Composition API com
`<script setup>`) + TypeScript estrito + Tailwind CSS 4 + Reka UI 2, com prática em
migração de versão maior de Tailwind e em teste de ponta a ponta com Playwright.

**Scope:** Corrigir a sintaxe de variável CSS arbitrária nos quatro componentes listados
no diagnóstico, e criar as duas redes de proteção que faltam. **Nada de domínio**:
nenhum arquivo em `app/`, `routes/`, `database/` ou `config/` é tocado.

**Stack:** Vue 3.5.13 · Reka UI 2.10.4 · Tailwind CSS 4.3.3 · TypeScript ·
Inertia 2 · Playwright 1.62 · PHPUnit/Pest sobre PHP 8.4.

## 2. Direct Objective

Fazer a barra lateral administrativa voltar a ocupar a **coluna da esquerda** em vez de
flutuar por cima do conteúdo, devolvendo o botão de recolher ao alcance de quem
administra — corrigindo as **dez ocorrências** da sintaxe de Tailwind 3 nos quatro
componentes —, e deixar duas provas que impedem o defeito de voltar: um cenário de
navegador **em tela grande** e uma varredura que falha se qualquer declaração inválida
reaparecer no CSS construído.

## 3. Minimum Inputs

### A tradução, ocorrência por ocorrência

A regra é mecânica: **colchete vira parêntese** quando o conteúdo é o nome de uma
variável CSS.

| Antes (Tailwind 3) | Depois (Tailwind 4) |
|---|---|
| `w-[--sidebar-width]` | `w-(--sidebar-width)` |
| `w-[--sidebar-width-icon]` | `w-(--sidebar-width-icon)` |
| `group-data-[collapsible=icon]:w-[--sidebar-width-icon]` | `group-data-[collapsible=icon]:w-(--sidebar-width-icon)` |
| `max-w-[--skeleton-width]` | `max-w-(--skeleton-width)` |
| `w-[--reka-dropdown-menu-trigger-width]` | `w-(--reka-dropdown-menu-trigger-width)` |
| `h-[--reka-navigation-menu-viewport-height]` | `h-(--reka-navigation-menu-viewport-height)` |
| `md:w-[--reka-navigation-menu-viewport-width]` | `md:w-(--reka-navigation-menu-viewport-width)` |

**Atenção ao que NÃO muda.** O colchete continua certo em toda a parte:

- `group-data-[collapsible=icon]`, `peer-data-[variant=inset]`, `data-[state=open]` — são
  seletores de atributo, e a sintaxe deles não mudou;
- `left-[calc(var(--sidebar-width)*-1)]` e
  `w-[calc(var(--sidebar-width-icon)_+_theme(spacing.4))]` — já usam `var(...)` dentro de
  `calc(...)`, que sempre foi válido. **Estes já funcionam hoje** (foi conferido no CSS
  construído: saem como `calc(var(--sidebar-width-icon) + 1rem)`). Não mexer.

Trocar um colchete que não precisava trocar quebra o que está funcionando. A troca é
**só** quando o conteúdo do colchete começa com `--` e é o valor inteiro.

### Como o estado da barra funciona hoje (para não mudar sem querer)

- `AppSidebar.vue` usa `<Sidebar collapsible="icon" variant="inset">` — recolhe para uma
  faixa de ícones de `3rem`, não some da tela.
- `AppShell.vue` guarda o estado em `localStorage` (`sidebar`) e passa `:open` +
  `@update:open` — é **controlado**. `SidebarProvider` também grava um cookie.
- `SidebarTrigger` já está montado em `AppSidebarHeader.vue`, com `class="-ml-1"`. **Não
  criar botão novo, não mover, não trocar por `SidebarRail`.** Ele volta a aparecer
  sozinho quando a barra parar de cobri-lo.
- `isMobile` é `useMediaQuery('(max-width: 768px)')`. Em `769px` ou mais o caminho é o de
  tela grande — o que está quebrado.

### Arquivos que a executora precisa ler antes de começar

- `resources/js/components/ui/sidebar/Sidebar.vue` — o núcleo do defeito
- `resources/js/components/ui/sidebar/SidebarInset.vue` — a coluna de conteúdo
- `resources/js/components/ui/sidebar/SidebarProvider.vue` — onde as variáveis nascem
- `resources/js/components/AppShell.vue` e `resources/js/components/AppSidebar.vue`
- `resources/js/components/AppSidebarHeader.vue` — onde o botão vive
- `tests/e2e/admin-acesso.spec.ts` — o jeito estabelecido de criar conta e entrar
- `tests/e2e/base.ts`, `tests/e2e/apoio.ts` e `playwright.config.ts`
- `.github/workflows/tests.yml` — mostra que o CI roda `npm run build` **antes** do
  PHPUnit, que é o que torna a varredura possível

### Regras da varredura de CSS

- Ler `public/build/manifest.json` para descobrir os arquivos `.css` de verdade —
  **não** varrer `public/build/assets/*.css` com curinga, porque sobra de build antiga
  ficaria acusando defeito já corrigido (ou escondendo um novo).
- Falhar quando encontrar qualquer declaração cujo valor seja um nome cru de custom
  property: o padrão `propriedade: --alguma-coisa` sem `var(`.
- A mensagem de falha precisa dizer **qual propriedade, qual valor e o que fazer**
  (trocar `[--x]` por `(--x)` no componente). Falha que só diz "encontrei 1" faz a
  próxima pessoa repetir esta investigação inteira.
- Quando `public/build/manifest.json` não existir, **pular o teste com motivo escrito**
  ("rode `npm run build` antes"). Em CI ele nunca é pulado, porque o build vem antes.

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `resources/js/components/ui/sidebar/Sidebar.vue` | modify | Seis ocorrências: `[--sidebar-width]` e `[--sidebar-width-icon]` viram parênteses |
| `resources/js/components/ui/sidebar/SidebarMenuSkeleton.vue` | modify | `max-w-[--skeleton-width]` → `max-w-(--skeleton-width)` |
| `resources/js/components/NavUser.vue` | modify | `w-[--reka-dropdown-menu-trigger-width]` → parênteses |
| `resources/js/components/ui/navigation-menu/NavigationMenuViewport.vue` | modify | Altura e largura do viewport → parênteses |
| `tests/Feature/Interface/CssConstruidoTest.php` | create | Varredura do CSS construído: falha se houver declaração com valor cru de custom property |
| `tests/e2e/admin-barra-lateral.spec.ts` | create | Cenário Playwright **em tela grande**: barra ao lado do conteúdo, botão alcançável, recolher e expandir |
| `docs/PROGRESS.md` | modify | Decisão **D-86** e o registro de por que a suíte não pegou isto |
| `docs/ARCHITECTURE.md` | modify | Nota curta na seção de interface: a sintaxe de variável CSS do Tailwind 4 e a varredura que a vigia |

## 5. Quality Criteria

- [ ] `grep -rn -o '[a-z-]*-\[--[a-z0-9-]*\]' resources/js` devolve **zero linhas**
- [ ] Depois de `npm run build`, nenhuma declaração do CSS construído casa com
      `[a-z-]+:\s*--[a-zA-Z0-9-]+[;}]` — hoje são **seis**
- [ ] `npx vue-tsc --noEmit` continua com **zero erros**
- [ ] `npm run lint` e `npm run format:check` passam
- [ ] Todo o `./vendor/bin/phpunit` passa, incluindo o teste novo (**542 + 1**, nenhum
      pulado por engano)
- [ ] Playwright E2E — cenário novo, em viewport de **1280×800**, com conta de
      `administrador` criada como em `admin-acesso.spec.ts`:
    - [ ] a barra lateral **não cobre** o conteúdo: a borda direita dela é menor ou igual
          à borda esquerda do `<main>`
    - [ ] a barra tem a largura declarada (**256px**, o `16rem` do `SIDEBAR_WIDTH`) — é
          esta asserção que **falha hoje** e que prova o conserto
    - [ ] o botão de recolher está **visível e clicável**
    - [ ] ao clicar, o estado vai para `collapsed`, a barra encolhe para a faixa de
          ícones e o conteúdo **ganha** a largura que ela devolveu
    - [ ] ao clicar de novo, tudo volta ao que era
- [ ] Os 40 cenários que já existiam continuam passando (`npm run test:e2e` inteiro)
- [ ] Aparência de tela pequena **inalterada**: no celular a barra continua sendo gaveta

## 6. Ambiguity Handling

**Assumptions made:**

- **O botão de recolher não é recriado.** Ele já existe e já funciona; está apenas
  coberto. Decisão do dono do produto nesta rodada de perguntas: "só destapar o botão".
  Nada de `SidebarRail`, nada de trocar `collapsible="icon"` por `offcanvas`.
- **As quatro ocorrências fora da barra lateral entram no mesmo conserto.** Decisão do
  dono do produto: "corrigir as seis". Mesma causa, mesma correção, e deixar três
  declarações inválidas vivas seria deixar um defeito marcado para ser redescoberto.
- **A varredura do CSS mora no PHPUnit, e não num script solto.** É o único lugar que o
  CI já executa **depois** do `npm run build` (`.github/workflows/tests.yml`). Script que
  ninguém chama não é rede de proteção.
- **O cenário novo declara o próprio viewport** em vez de acrescentar um projeto ao
  `playwright.config.ts`. Um projeto novo faria os 40 cenários de celular rodarem duas
  vezes, dobrando o tempo da suíte para provar uma coisa só.
- **`theme(spacing.4)` fica como está.** É sintaxe depreciada no Tailwind 4, mas
  **funciona** — sai como `1rem` no CSS construído. Trocá-la agora misturaria conserto de
  defeito com faxina, e não é isso que está sendo pedido.

**If unsure during execution:**

- Se alguma tela mudar de aparência **além** de a barra sair de cima do conteúdo, pare:
  a regra **DA-45** (aparência congelada) continua valendo, e esta correção deve devolver
  o desenho que existia antes da Etapa 21 — não propor um novo.
- Se a asserção de largura de 256px falhar mesmo depois da correção, **não afrouxe a
  asserção**. Confira antes se o `npm run build` rodou e se o navegador está pegando o
  bundle novo — assertiva relaxada para o teste passar é o mesmo defeito com outra roupa.
- Se aparecer uma sétima declaração inválida que não está na tabela do diagnóstico,
  corrija-a também e **registre no relatório de execução** — a lista foi levantada do
  build de 27/08 e pode ter envelhecido.

## 7. Prohibitions

- ❌ Nunca tocar em `app/`, `routes/`, `database/`, `config/` ou qualquer migração — este
  defeito é de CSS e não tem lado servidor
- ❌ Nunca alterar `collapsible`, `variant`, largura, cor, espaçamento ou qualquer decisão
  visual da barra lateral — **DA-45**: aparência congelada
- ❌ Nunca criar um botão de recolher novo nem mover o existente
- ❌ Nunca editar os 40 cenários Playwright que já existem, nem o `playwright.config.ts`
- ❌ Nunca rodar o gerador do shadcn-vue (`npx shadcn-vue add`) — **DA-44**: ele reescreve
  o arquivo inteiro e apaga os comentários em português que custaram a existir
- ❌ Nunca silenciar erro de `vue-tsc` com `any`, `as unknown as` ou `@ts-ignore`
- ❌ Nunca substituir colchete por parêntese onde o conteúdo **não** é um nome cru de
  variável CSS (seletores `data-[...]`, `calc(var(...))`)
- ❌ Nunca reintroduzir `radix-vue`, `tailwindcss@3` ou `tailwind.config.js`
- ❌ Nunca marcar o teste de varredura como pulado para o build faltando **em CI**

---

## Execution Steps

1. **Ler e confirmar o diagnóstico.** Rodar
   `grep -rn -o '[a-z-]*-\[--[a-z0-9-]*\]' resources/js` e conferir que as dez ocorrências
   da tabela ainda estão lá. Se o número mudou, ajustar o alvo antes de editar.

2. **Corrigir `Sidebar.vue`** — as seis ocorrências das linhas 32, 43, 66, 71, 78 e 85.
   Conferir, linha a linha, que nenhum `group-data-[...]` nem `calc(var(...))` foi tocado
   junto.

3. **Corrigir os outros três arquivos** — `SidebarMenuSkeleton.vue`, `NavUser.vue` e
   `NavigationMenuViewport.vue` (duas ocorrências na mesma linha).

4. **Construir e provar no CSS gerado.** `npm run build`, depois procurar por
   `[a-z-]+:\s*--[a-zA-Z0-9-]+[;}]` no CSS apontado pelo `manifest.json`: precisa passar
   de **seis para zero**. Este é o momento em que o conserto fica provado, antes de
   qualquer teste automatizado existir.

5. **Escrever a varredura** em `tests/Feature/Interface/CssConstruidoTest.php`, com as
   regras da seção 3: manifest como fonte, mensagem de falha que ensina o conserto, e
   pulo com motivo quando não houver build. Provar que ela **pegaria** o defeito —
   desfazendo uma das correções, reconstruindo, vendo o teste ficar vermelho e refazendo.
   Teste que nunca falhou não é prova de nada.

6. **Escrever o cenário** `tests/e2e/admin-barra-lateral.spec.ts`, em 1280×800, com conta
   de `administrador` criada pelo padrão de `admin-acesso.spec.ts` — as cinco asserções
   da seção 5.

7. **Rodar tudo:** `npx vue-tsc --noEmit`, `npm run lint`, `npm run format:check`,
   `./vendor/bin/phpunit` e `npm run test:e2e` inteiro. A suíte de navegador roda contra o
   banco `testing` — **não** rodar junto com o PHPUnit.

8. **Conferir com os próprios olhos, nos dois tamanhos.** Em `http://localhost:8888`, em
   tela grande: barra à esquerda, conteúdo ao lado, botão alcançável, recolhe e expande.
   Em tela de celular: continua sendo gaveta, como sempre foi.

9. **Registrar** a decisão **D-86** em `docs/PROGRESS.md` — a causa, o alcance de seis
   declarações, e **por que 40 cenários não pegaram** (a suíte só tinha viewport de
   celular, e o caminho quebrado é exclusivo de tela grande). Acrescentar a nota curta em
   `docs/ARCHITECTURE.md`.

10. **Commit único**, com a mensagem abaixo, e escrever o relatório de execução.

## Done

A barra lateral administrativa ocupa a coluna da esquerda e o conteúdo ocupa o resto, o
botão de recolher está visível e recolhe para a faixa de ícones, o CSS construído não tem
nenhuma declaração inválida, e existem duas provas automatizadas — uma em navegador de
tela grande, outra varrendo o CSS — que ficam vermelhas se qualquer uma das duas coisas
voltar a quebrar.

## Commit

`fix(ui): restaurar a largura da barra lateral na sintaxe do tailwind 4`
