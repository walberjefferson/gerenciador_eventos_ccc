# Execution Report — Campos e selects ocupando a largura nos formulários administrativos

> **Plan:** selects-ocupando-a-largura-nos-formularios-admin
> **Executed:** 2026-09-01 23:50
> **Status:** ✅ COMPLETE

## O que foi feito

| Arquivo | Ação | Descrição |
|---------|------|-----------|
| `resources/js/pages/Admin/Eventos/Formulario.vue` | modificado | Removido o `md:max-w-xs` do wrapper do campo **Situação**. O campo passou a ocupar a linha inteira da seção, alinhado com as grades de Nome/Endereço e Local/Como chegar. Comentário em pt-BR explica por que o teto saiu. |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modificado | Nos dois diálogos (grupo de atividade e atividade): o campo **Nome** foi movido para o primeiro lugar da grade e o `md:col-span-2` virou `sm:col-span-2` — a mesma faixa da grade `sm:grid-cols-2`. Dia/Grupo e Posição passam a dividir a segunda linha. Comentário em pt-BR registra o motivo (campo principal primeiro, faixa do `col-span` casando com a da grade). |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | modificado | `md:col-span-2 lg:col-span-1` no campo **Provedor**: em `md` ele fecha a segunda linha com a Assinatura; em `lg` os cinco filtros voltam a uma coluna cada. |
| `tests/e2e/formularios-admin-largura.spec.ts` | criado | Cinco cenários Playwright que medem a geometria no navegador (`boundingBox`), em 1280 px e na faixa `md`. |

Nenhum `id`, `for`, `v-model`, `aria-describedby` ou `data-testid` foi alterado: os blocos movidos são idênticos ao original, exceto pela classe do wrapper. Nenhum `@submit`, validação, prop ou comportamento foi tocado.

## Critérios de qualidade

| Critério | Situação | Evidência real |
|----------|----------|----------------|
| Nenhum wrapper de campo de formulário guarda `max-w-*` (salvo os dois seletores de evento) | ✅ | `grep -rn "max-w-" resources/js/pages resources/js/components --include="*.vue" \| grep -v components/ui/` — sobram apenas `<p>`/`<DialogContent>` de texto e diálogo, os dois seletores de evento (`Painel.vue:146`, `Portaria/Index.vue:144`) e o `<form>` da conferência de ingresso (ver "Desvios"). O cenário E2E ainda percorre os ancestrais do `#evento-situacao` no navegador e exige `maxWidth: none` em todos. |
| Nos dois diálogos, nenhuma célula vazia ao lado de um campo a partir de 640 px | ✅ | Cenários 2 e 3: `linhaFechada([dia, posicao], nome)` e `linhaFechada([grupo, posicao], nome)` — as duas metades começam e terminam onde a linha do Nome começa e termina, com tolerância de 2 px. Ambos passam. |
| Todo `col-span` na mesma faixa da grade que preenche | ✅ | `grep -rn "col-span" resources/js/pages resources/js/components --include="*.vue" \| grep -v components/ui/` → `Estrutura.vue:573 sm:col-span-2`, `Estrutura.vue:761 sm:col-span-2` (grades `sm:grid-cols-2`), `Avisos/Index.vue:166 md:col-span-2 lg:col-span-1` (grade `md:grid-cols-3 lg:grid-cols-5`), `PassoDadosPessoais.vue:111 sm:col-span-2` (não tocado). Nenhum `md:col-span-*` dentro de grade `sm:`. |
| Ordem de tabulação = ordem visual | ✅ | Cenários 2 e 3 percorrem o diálogo com Tab a partir do primeiro campo: `['grupo-nome','grupo-dia','grupo-posicao']` e `['atividade-nome','atividade-grupo','atividade-posicao']`. Passam. |
| Nenhum `id`, `for`, `v-model`, `aria-describedby` ou `data-testid` mudou | ✅ | `git diff` de `Estrutura.vue`: os blocos removidos e reinseridos são idênticos linha a linha, com a única diferença de `md:col-span-2` → `sm:col-span-2` no wrapper. O cenário E2E acha todos os campos pelos mesmos `id`. |
| Rótulos continuam ligados aos campos | ✅ | `caixaDoCampo` localiza cada campo por `#id`; os `<label for>` acompanharam o bloco inteiro no movimento. Os cenários E2E acham os cinco filtros e os seis campos dos diálogos. |
| Nenhuma mudança de comportamento | ✅ | Diff só de `class` e de posição no markup. Suíte E2E completa sem regressão (abaixo). |
| `npm run lint`, `npx vue-tsc --noEmit`, `npm run build` limpos | ✅ | `lint exit=0`; `vue-tsc --noEmit` sem saída; `vite build ✓ built in 2.14s`. |
| Suíte E2E sem regressão em relação à linha de base | ✅ | Ver "Verificação". |
| Playwright mede as larguras no navegador | ✅ | 5 cenários, 5 passando. Além disso, com o markup ANTES da correção (stash + rebuild) **4 dos 5 falham** — o cenário mede mesmo o defeito, não passa por acaso. |

## Verificação

| Comando | Resultado |
|---------|-----------|
| `npm run lint` | exit 0, sem alterações no diretório de trabalho |
| `npx vue-tsc --noEmit` | sem saída (limpo) |
| `npm run build` | `✓ built in 2.14s` |
| `npx prettier --check` nos 4 arquivos | 3 limpos; `Estrutura.vue` acusa apenas dois blocos `BotaoDeAcao` **pré-existentes** (idênticos ao HEAD, fora desta entrega) — ver "Desvios" |
| `npx playwright test tests/e2e/formularios-admin-largura.spec.ts` | **5 passed** (6,1 s) |
| `npx playwright test` (suíte inteira, com as mudanças) | **86 passed / 5 failed** (3,5 min) — duas execuções, mesmo resultado |
| `npx playwright test` (suíte inteira, no HEAD, com as mudanças em stash e o cenário novo fora) | **81 passed / 5 failed** (3,4 min) — linha de base medida hoje, nesta máquina |
| `npx playwright test tests/e2e/formularios-admin-largura.spec.ts` com o markup do HEAD | **4 failed / 1 passed** — prova de que o cenário pega o defeito |

**Leitura da suíte:** 81 + 5 novos = 86. Nenhuma regressão. As 5 falhas são as mesmas de antes: 3 em `admin-avisos-pagamento` e 2 em `admin-usuarios`.

**Nota sobre a linha de base:** as anotações do ambiente falavam em 82 passed / 4 failed (3 avisos + 1 usuarios). Medida hoje, no HEAD e sem nada desta entrega, a suíte fecha em **81 / 5**: o `admin-usuarios` tem uma SEGUNDA falha, intermitente, sempre num tempo esgotado de login (`waitForURL` depois de entrar) e sempre num cenário diferente — linha 69 na execução do HEAD, linha 153 nas execuções com as mudanças. Rodado sozinho, o arquivo fecha em 5 passed / 1 failed (só a falha conhecida da linha 110). É defeito de suíte cheia, anterior a esta entrega e alheio aos arquivos tocados aqui; fica registrado, não consertado.

## Desvios do plano

1. **Critério 1 do §5 (E2E) foi medido contra a LINHA, e não contra o campo Nome.** O §5 pedia "o campo Situação tem largura igual à do campo Nome"; o Nome vive dentro da grade `md:grid-cols-2` e vale por meia linha, enquanto a Situação, depois de tirado o `md:max-w-xs`, é filha direta da grade da seção e vale pela linha inteira — como a Descrição logo acima. Igualar as duas exigiria devolver à Situação um teto de meia largura, com a coluna vazia ao lado que o §2 manda acabar. O cenário afere o que o §2 e o §3 pedem: `largura(Situação) == largura(Nome) + vão + largura(Endereço)`, com as bordas esquerda e direita coincidindo, tolerância de 2 px.
2. **Faixa `md` medida a 900 px, não a 768 px.** Em Chromium a barra de rolagem come alguns pixels da largura de layout, e uma janela de exatamente 768 cairia para baixo do ponto de corte do `md:` — o cenário mediria a grade de uma coluna e passaria por engano. Qualquer largura entre 768 e 1023 serve, e o próprio cenário confere em que faixa caiu (`document.documentElement.clientWidth`) antes de medir.
3. **Espera explícita pela animação do diálogo.** As primeiras execuções acusaram "Dia e Posição não estão na mesma linha" com 43 px de diferença numa vez e passaram na seguinte: o `DialogContent` entra com `zoom-in-95` + `slide-in-from-top` e `duration-200`, e `boundingBox` durante a animação devolve a medida do meio do caminho. Foi acrescentada a função `assentar()`, que espera as `getAnimations()` da página terminarem (com corrida contra 1 s, para o caso de alguém introduzir animação em laço). Não é frouxidão de tolerância: a medida continua exigindo 2 px.
4. **Dois blocos `BotaoDeAcao` de `Estrutura.vue` foram devolvidos ao formato do HEAD.** O `prettier --write` no arquivo (necessário porque a nova marcação precisava passar pelo formatador) reflowou de quebra dois `BotaoDeAcao` que já estavam fora do padrão antes desta entrega — `Estrutura.vue` é um dos ~24 arquivos que já não fecham o `format:check` no HEAD (verificado: `prettier --check` reprova a versão do HEAD do arquivo). Os dois blocos foram reescritos como estavam, para o diff não carregar linha alheia. Consequência assumida: `prettier --check` continua reprovando o arquivo exatamente pelos mesmos dois blocos de antes, e por nada mais (conferido com `diff -u` contra a saída do prettier).

## Casos extras encontrados (analisados, não tocados)

- **`Portaria/Index.vue:220`** — `<form class="flex flex-col gap-3 sm:max-w-md">`, o formulário de conferir um ingresso pelo código. Não é wrapper de campo: é o formulário inteiro, de um campo só, e o teto ali é o mesmo acerto do seletor de evento logo acima (campo curto, de código, ao lado do rótulo). O arquivo também não consta da tabela §4 desta entrega. Fica como está, registrado.
- **`PassoDadosPessoais.vue:111`** — `sm:col-span-2` dentro de grade `sm:grid-cols-2`: faixa e grade combinam, está correto. Tela pública, fora do escopo.
- Nenhum quinto caso de campo órfão em grade de painel foi encontrado além dos quatro do plano.

## Commit

- **Mensagem:** `fix(admin): campos de formulario ocupando a largura da grade`
- **Arquivos:** `resources/js/pages/Admin/Eventos/Formulario.vue`, `resources/js/pages/Admin/Eventos/Estrutura.vue`, `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue`, `tests/e2e/formularios-admin-largura.spec.ts`, `.planning/feat/features/selects-ocupando-a-largura-nos-formularios-admin/` (plan.md + este relatório)
- Os dois arquivos soltos na raiz do repositório (`Prompt para Claude Code — ...md` e `ccc-redesign.html`) **não** foram tocados nem versionados.
