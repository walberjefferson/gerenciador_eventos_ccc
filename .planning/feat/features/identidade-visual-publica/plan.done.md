# Execution Report — Identidade visual do lado público (plano 1 de 3)

> **Plan:** identidade-visual-publica
> **Executed:** 2026-08-28
> **Status:** ⚠️ WITH CAVEATS

O trabalho está completo e provado; a ressalva é uma só e está na seção
"O que NÃO foi verificado": **duas das seis telas públicas (a cobrança do Pix e
o acompanhamento) não foram conferidas a olho**, porque são telas com URL
assinada e o roteiro de captura não conseguiu percorrer o formulário até elas.
Elas estão cobertas por teste automatizado, mas não por inspeção visual.

## What Was Done

| File | Action | Description |
|------|--------|-------------|
| `resources/css/app.css` | modify | `@custom-variant publico`, os tokens da identidade em `[data-tema='publico']` e `[data-tema='publico'].dark`, as duas famílias novas no `@theme inline`, quatro tokens de superfície suave nos quatro blocos, e as regras de tipografia do lado público |
| `resources/views/app.blade.php` | modify | `data-tema` no `<html>` para a primeira pintura; Bricolage Grotesque e DM Mono ao lado da Instrument Sans, todas no `fonts.bunny.net` |
| `resources/js/app.ts` | modify | `router.on('navigate')` reescreve `data-tema` a cada troca de página do Inertia; a mesma regra do Blade |
| `resources/js/components/ui/button/index.ts` | modify | Forma pílula, `min-h-12` e `font-semibold` dentro do escopo `publico:`; o botão do painel intacto |
| `resources/js/components/ui/badge/index.ts` | modify | As quatro etiquetas do protótipo (`open`/`soon`/`warn`/`done`) mapeadas em `sucesso`/`secondary`/`atencao`/`outline`. Nenhuma variante nova |
| `resources/js/components/publico/TrilhaDeDias.vue` | create | O elemento-assinatura: `<ol>` com a linha pontilhada e os pontos, os dois `aria-hidden` |
| `tests/Feature/Interface/TemaPublicoTest.php` | create | 7 testes: varredura de contraste recalculada, paridade de tokens, âncoras do tema do painel, `data-tema` nos dois lados e as fontes/CSP |
| `tests/e2e/identidade-publica.spec.ts` | create | Os 4 cenários da §5, incluindo o do portal |
| `docs/ARCHITECTURE.md` | modify | Seção 14.5, "São dois temas, e o escopo mora no `<html>`" |
| `docs/PROGRESS.md` | modify | Bloco da etapa concluída e decisões **DA-51 a DA-56** |

### As três armadilhas do plano, todas confirmadas na prática

1. **Os portais saem da subárvore.** Confirmado no navegador: a cadeia de
   ancestrais da lista de cidades é `DIV#reka-select-content-v-0 → DIV → BODY →
   HTML[tema=publico]`. Ela é filha direta do `<body>`.
2. **O Inertia não recarrega o `<html>`.** Resolvido com Blade + `app.ts`.
3. **A CSP libera só o `fonts.bunny.net`.** As duas famílias novas **existem
   lá** (conferido por `curl` antes de escrever uma linha): a política não
   mudou e `seguranca-csp.spec.ts` não foi tocado. Nenhuma fonte precisou ser
   auto-hospedada.

### As medições que decidiram a paleta (passo 1 do plano)

Feitas **antes** de tocar em qualquer arquivo, pela fórmula da WCAG 2.1.

| Tom do protótipo | Papel | Medido | Veredito |
|---|---|---|---|
| `--tinta` `#10231C` | texto | 14,69:1 papel / 16,42:1 cartão | entra como está |
| `--tinta2` `#5B6C64` | texto secundário | 4,98 / 5,56 / **4,58 sobre a própria etiqueta** | entra como está — o plano previa problema, a medição absolveu |
| `--mata` `#0F6B4E` | ação e sucesso | branco por cima 6,49:1 | entra como está |
| `--erro` `#A93425` | erro | branco por cima 6,55:1 | entra como está |
| `--sol` `#E9922B` **como superfície** | atenção | tinta por cima 6,73:1 | entra como está |
| `--sol` `#E9922B` **como texto** | atenção | **2,18:1** | **reprova** → `#8A5310` (5,65:1) |
| `--linha-forte` `#C7D0C6` **como borda de campo** | contorno de controle | **1,58:1** | **reprova** 1.4.11 → `#7C8B83` (3,57:1) |
| `#8A968E` da etiqueta "encerrado" | texto | **2,53:1** | **reprova** → `#5B6C64` (4,58:1) |
| `--linha` `#DEE3DB` | fio decorativo | 1,17:1 | fica — não carrega texto nem delimita controle |
| `informacao` | não existe na paleta | — | **derivada**: `#0C5A75`, 6,87:1 sobre o papel |

## Quality Criteria

| Criterion | Status | Evidence (real output) |
|---|---|---|
| Toda cor declarada tem razão calculada e escrita ao lado | ✅ | `TemaPublicoTest`: *"todo tom do tema publico claro passa em AA, com a razao recalculada"* + o mesmo no escuro. O teste **recalcula** lendo o `app.css`, não confia no comentário |
| Texto ≥ 4,5:1; componentes ≥ 3:1; tom que reprova é escurecido com o original registrado | ✅ | 3 tons ajustados (tabela acima), os dois valores no comentário. Visto vermelho: devolvendo `#E9922B` ao papel de texto, o teste falha com `--cor-atencao-texto (#E9922B) sobre --background (#F1F3EE) = 2.18:1, precisa de 4.5:1` |
| Nenhuma tela administrativa muda de cor | ✅ | Cenário `o painel administrativo continua no azul de hoje`: em `/admin/painel` e `/admin/inscricoes`, `--primary` = `#155DFC`, `--background` = `#FFFFFF`, `--cor-acao` = `#155DFC`, fundo do `body` = `rgb(255,255,255)`. Mais captura de tela dos dois endereços em 393px e 1280px, conferida a olho |
| O portal do `Select` sai no tema público | ✅ | Cenário do portal: `saiuDaPagina=true`, `tema='publico'`, borda `rgb(222,227,219)` (o fio público; no painel seria `rgb(228,228,231)`), raio 12px. **Visto vermelho:** com o atributo movido do `<html>` para a raiz do layout, falha com *"a lista de cidades saiu fora do tema publico"* |
| Sem piscada: a primeira pintura já sai certa | ✅ | `curl http://localhost:8888/` devolve `<html lang="pt-BR" data-tema="publico">`; `/login` devolve `data-tema="admin"`. Coberto no Pest por `a primeira pintura de uma tela publica ja sai no tema publico` |
| Navegar pelo Inertia mantém o tema | ✅ | `router.on('navigate')` em `app.ts`; provado pelo cenário do painel, que navega de `/login` → `/admin/painel` → `/admin/inscricoes` e checa `data-tema` em cada uma |
| A CSP não muda; fontes no `fonts.bunny.net` | ✅ | `git diff app/` vazio (0 arquivos). Cabeçalho conferido: `style-src 'self' 'unsafe-inline' https://fonts.bunny.net`. Teste `as tres familias tipograficas continuam vindo do fonts.bunny.net` |
| `vue-tsc` zero erros; `lint` e `prettier` limpos nos arquivos tocados | ✅ | `npx vue-tsc --noEmit` sem saída; `npm run lint` sem saída; `npx prettier --check` nos 6 arquivos: *"All matched files use Prettier code style!"*. `pint --test` no teste novo: `{"tool":"pint","result":"passed"}` |
| `./vendor/bin/pest` inteiro passa | ✅ | `Tests: 550 passed (3982 assertions)` — 543 antes, mais os 7 novos |
| Os 47 cenários continuam passando, sem nenhum editado | ✅ | `51 passed (1.3m)` = 47 + 4. `git diff --name-only -- tests/e2e` devolve **0 arquivos** |
| E2E: home com fundo papel e botão verde-mata | ✅ | `body` = `rgb(241,243,238)`; botão = `rgb(15,107,78)`, raio ≥ metade da altura, altura ≥ 48px |
| E2E: `/admin/painel` com o fundo e o azul de hoje | ✅ | acima |
| E2E: lista de cidades (portal) no tema público | ✅ | acima |
| E2E: 320px sem escapar da tela e alvo de toque ≥ 44px | ✅ | 4 endereços públicos varridos em 320px: `scrollWidth - clientWidth ≤ 0` e nenhum alvo abaixo de 44px |

## Verification

| Command | Result |
|---|---|
| `npm run build` | ✓ built in 2.48s |
| `npx vue-tsc --noEmit` | zero erros |
| `npm run lint` | limpo |
| `npx prettier --check` (6 arquivos tocados) | All matched files use Prettier code style! |
| `./vendor/bin/pint --test tests/Feature/Interface/TemaPublicoTest.php` | passed |
| `./vendor/bin/pest` | **550 passed (3982 assertions)** |
| `npm run test:e2e` | **51 passed (1.3m)** |
| `curl -I http://localhost:8888/` | CSP idêntica à de antes |
| `git diff --name-only -- app routes database` | 0 arquivos |
| `git diff --name-only -- tests/e2e` | 0 arquivos (nenhum cenário antigo editado) |

Pest e Playwright **nunca rodaram ao mesmo tempo**: os dois usam o banco
`testing`.

## O que NÃO foi verificado

Esta seção existe para que nenhuma prova seja afirmada sem ter acontecido.

1. **Duas das seis telas públicas não foram vistas a olho: a cobrança do Pix
   (`/inscricoes/{codigo}/pagamento`) e o acompanhamento
   (`/inscricoes/{codigo}/acompanhar`).** As duas exigem URL assinada, ou seja,
   percorrer o formulário inteiro; o roteiro de captura falhou no passo do
   aceite do regulamento e eu preferi não gastar mais tentativas do que a prova
   valia. **O que existe no lugar:** os cenários `caminho-feliz`,
   `segunda-via-do-pix`, `confirmacao-do-pagamento` e `acompanhamento` passam
   nas duas telas, e elas usam exatamente os mesmos componentes e tokens das
   quatro que **foram** conferidas. Ainda assim, é inspeção visual que não
   aconteceu. **Conferidas a olho, em 393px e 1280px:** a porta da rua, a
   vitrine do evento, o formulário (inclusive com a lista de cidades aberta),
   a recuperação de acesso, `/admin/painel` e `/admin/inscricoes`.
2. **O modo escuro do lado público não foi visto a olho.** Os tons foram
   medidos e o teste recalcula todos, mas ninguém abriu uma tela pública com
   `.dark` aplicado. É variante derivada (**DA-54**) e o próprio plano marca
   que ela é "uma linha para reverter" se o dono do produto preferir o lado
   público sempre claro.
3. **O `TrilhaDeDias.vue` não foi renderizado em lugar nenhum.** Ele passa no
   `vue-tsc` e no lint, mas **nenhuma tela o usa** — quem o coloca em tela é o
   plano 2, e o plano 1 pede que ele nasça aqui. Não há prova visual nem de
   navegador dele.
4. **O contraste não foi medido no navegador para o tema público**, só no CSS.
   O cenário que mede contraste com `getComputedStyle` (`home.spec.ts`)
   continua passando, mas ele mede um elemento só.

## Deviations from Plan

1. **A ordem do passo 2 foi encurtada.** O plano pedia montar o mecanismo de
   escopo com o bloco `[data-tema='publico']` **vazio** e prová-lo antes de pôr
   cor dentro. O mecanismo foi provado (`curl` mostrando `data-tema="publico"`
   na home e `data-tema="admin"` no `/login`), mas o bloco já tinha os tokens
   dentro nesse momento. A prova que o plano queria — de que o atributo sai
   certo dos dois lados — aconteceu; o que não aconteceu foi a etapa
   intermediária com o bloco vazio.
2. **Quatro tokens novos entraram nos blocos do painel** (`--cor-sucesso-suave`,
   `--cor-sucesso-suave-contraste`, `--cor-atencao-suave` e
   `--cor-atencao-suave-contraste`, em `:root` e em `.dark`). Eles nasceram para
   as etiquetas do protótipo, que são pílulas de fundo lavado. Estão lá porque
   o teste de paridade exige que todo token tenha valor correto nos dois temas,
   e porque um par (fundo + texto) sem valor no painel seria uma armadilha para
   a próxima pessoa. **Nenhuma tela administrativa os usa**, e o teste
   `o tema do painel nao mudou de cor` prova que nenhuma cor existente mudou.
3. **A borda de campo do lado público ficou visivelmente mais escura que a do
   protótipo** (`#7C8B83` no lugar de `#C7D0C6`). É consequência direta da
   DA-42 aplicada ao critério 1.4.11: borda de campo é contorno de controle, e
   3:1 é o mínimo. O valor original está registrado no comentário. **Se o dono
   do produto achar o resultado pesado demais, é uma linha para conversar** —
   mas o caminho não é voltar ao tom que reprova.
4. **O hero da home ficou azul-petróleo, não verde.** Ele usa `bg-informacao`, e
   `informacao` foi derivada como tom frio por decisão do próprio plano
   (**DA-53**). Não é defeito; é o que acontece quando um token muda de valor e
   a tela não muda de estrutura. Se o desejo for hero verde, isso é mudança de
   tela — plano 2.
5. **Os números dos campos de formulário passaram a sair em DM Mono.** O gancho
   escolhido para a família de número foi a classe `tabular-nums`, que o projeto
   já usa onde o algarismo precisa de largura fixa — e alguns `<input>`
   (telefone, CPF, data) a carregam. É coerente com "DM Mono nos números" e
   ficou registrado aqui porque não estava escrito no plano.

## Commit

- **Hash:** `85106ab`
- **Message:** `feat(publico): adotar a identidade visual verde no lado publico`
- **Files:** os 10 da tabela de saída do plano, e nenhum outro. `ccc-redesign.html`
  e o arquivo de prompt na raiz continuam **fora do commit**, como estavam.
