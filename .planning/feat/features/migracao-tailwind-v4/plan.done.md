# Execution Report — Migração para Tailwind v4 e adoção do tema do shadcn studio

> **Plan:** migracao-tailwind-v4
> **Executed:** 2026-08-27
> **Status:** ⚠️ WITH CAVEATS

Uma rodada só. Os seis steps foram executados na ordem; cinco commits (o step 5
não produziu mudança de arquivo — ver *Desvios*).

---

## What Was Done

| Step | Commit | File | Action | Description |
|---|---|---|---|---|
| 1 | `93eafdc` | `package.json` / `package-lock.json` | modify | entram `tailwindcss@^4.3.3`, `@tailwindcss/vite@^4.3.3`, `tw-animate-css@^1.4.0`; saem `tailwindcss-animate` e `autoprefixer` |
| 1 | `93eafdc` | `vite.config.ts` | modify | plugin `@tailwindcss/vite` no lugar do bloco `css.postcss` |
| 1 | `93eafdc` | `tailwind.config.js` | **delete** | v4 é CSS-first |
| 1 | `93eafdc` | `resources/css/app.css` | rewrite | sintaxe v4 (`@import`, `@source`, `@custom-variant`, `@theme inline`) ainda com a paleta antiga, para o build voltar a fechar antes de trocar as cores |
| 2 | `e5a8a0d` | `resources/css/app.css` | rewrite | tema do studio, claro e escuro, e as quatro semânticas derivadas com contraste calculado e escrito |
| 2 | `e5a8a0d` | `resources/js/components/ui/sidebar/index.ts` | modify | `hsl(var(--sidebar-border))` → `var(--sidebar-border)`: os tokens deixaram de ser trio HSL e passaram a ser cor inteira |
| 3 | `2e305f0` | `resources/js/**` (41 arquivos) | modify | **153 substituições** das classes renomeadas da §3.5 |
| 4 | `9ac416c` | `resources/css/app.css` | modify | paleta reescrita de `oklch()` para hexadecimal — mesmas cores; ver *A armadilha que o cenário pegou* |
| 5 | — | — | — | prova; nenhum arquivo mudou |
| 6 | (este) | `docs/ARCHITECTURE.md` | modify | §14 atualizada e **§14.3** nova; `tw-animate-css` na tabela de bibliotecas; §14.2 continua valendo |
| 6 | (este) | `docs/PROGRESS.md` | modify | Etapa 21, decisões **DA-39 a DA-43**, DA-37 marcada como superada, dependências e o registro de que o plano B continua pendente |
| 6 | (este) | `.planning/feat/features/migracao-tailwind-v4/plan.done.md` | create | este relatório |

### As dez classes renomeadas (step 3)

| v3 | v4 | Ocorrências trocadas |
|---|---|---:|
| `outline-none` | `outline-hidden` | **133** |
| `rounded-sm` | `rounded-xs` | 8 |
| `shadow-sm` | `shadow-xs` | 7 |
| `shadow` | `shadow-sm` | 5 |
| `blur-sm` / `blur` | `blur-xs` / `blur-sm` | 0 |
| `ring` | `ring-3` | 0 |
| `bg-opacity-*` etc. | `bg-black/50` | 0 |
| `flex-shrink-*` / `flex-grow-*` | `shrink-*` / `grow-*` | 0 |
| `overflow-ellipsis` | `text-ellipsis` | 0 |
| `decoration-slice` | `box-decoration-slice` | 0 |
| | **total** | **153** |

---

## As cores novas, com as razões de contraste

Fundos de referência: claro `#FFFFFF`; escuro `#09090B` (`--background`) e
`#18181B` (`--card`), este último o caso difícil para texto claro. Todos os
números foram calculados pela fórmula da WCAG 2.1 e conferidos depois no
navegador.

### Modo claro

| Token | Valor | Papel | Razão |
|---|---|---|---:|
| `--cor-acao` | `#155DFC` | superfície, texto branco por cima | **5.25:1** |
| `--cor-acao-texto` | `#155DFC` | texto sobre branco | **5.25:1** |
| `--cor-sucesso` | `#007A55` | superfície, texto branco por cima | **5.36:1** |
| `--cor-sucesso-texto` | `#007A55` | texto sobre branco | **5.36:1** |
| `--cor-informacao` | `#0069A8` | superfície, texto branco por cima | **5.86:1** |
| `--cor-informacao-texto` | `#0069A8` | texto sobre branco | **5.86:1** |
| `--cor-atencao` | `#FE9A00` | superfície, texto quase-preto por cima | **9.32:1** |
| `--cor-atencao-forte` | `#FFB900` | superfície, texto quase-preto por cima | **11.55:1** |
| `--cor-atencao-texto` | `#973C00` | texto sobre branco | **7.09:1** |
| `--primary` / `--primary-foreground` | `#155DFC` / `#EFF6FF` | | 4.82:1 |
| `--destructive` / `--destructive-foreground` | `#E7000B` / `#FFFFFF` | | 4.77:1 |
| `--foreground` | `#09090B` | texto sobre branco | 19.90:1 |
| `--muted-foreground` | `#6B6B75` | texto sobre `--muted` `#F4F4F5` | **4.79:1** |

### Modo escuro

| Token | Valor | Papel | Razão |
|---|---|---|---:|
| `--cor-acao` | `#2B7FFF` | superfície, texto quase-preto por cima | **5.29:1** |
| `--cor-acao-texto` | `#2B7FFF` | texto na página / sobre o card | **5.29 / 4.71:1** |
| `--cor-sucesso` | `#00BC7D` | superfície, texto quase-preto por cima | **8.04:1** |
| `--cor-sucesso-texto` | `#00BC7D` | texto na página / sobre o card | **8.04 / 7.16:1** |
| `--cor-informacao` | `#00BCFF` | superfície, texto quase-preto por cima | **9.13:1** |
| `--cor-informacao-texto` | `#00BCFF` | texto na página / sobre o card | **9.13 / 8.13:1** |
| `--cor-atencao` | `#FFB900` | superfície, texto quase-preto por cima | **11.55:1** |
| `--cor-atencao-forte` | `#FFD230` | superfície, texto quase-preto por cima | **13.75:1** |
| `--cor-atencao-texto` | `#FFB900` | texto na página / sobre o card | **11.55 / 10.29:1** |
| `--primary` / `--primary-foreground` | `#2B7FFF` / `#09090B` | | **5.29:1** |
| `--destructive` / `--destructive-foreground` | `#FF6467` / `#09090B` | | **6.89:1** |
| `--foreground` | `#FAFAFA` | na página / sobre o card | 19.06 / 16.97:1 |
| `--muted-foreground` | `#9F9FA9` | na página / sobre o `--muted` | 7.59 / 5.68:1 |

### Os três tons do studio que reprovaram e foram escurecidos (DA-42)

| Onde | O que o studio trazia | Razão | O que ficou | Razão |
|---|---|---:|---|---:|
| `--muted-foreground` claro sobre `--muted` | `#71717B` | 4.39:1 ❌ | `#6B6B75` | **4.79:1** ✅ |
| `--primary-foreground` escuro sobre `--primary` | `#1C398E` (azul escuro) | 2.76:1 ❌ | `#09090B` | **5.29:1** ✅ |
| `--destructive-foreground` escuro sobre `--destructive` | `#FFFFFF` | 2.89:1 ❌ | `#09090B` | **6.89:1** ✅ |

Além disso, `--border` e `--input` do modo escuro, que o studio traz
translúcidos (branco a 10% e a 15%), viraram os equivalentes opacos `#27272A` e
`#2E2E33`: o projeto usa `bg-border` como superfície de verdade (o fio da linha
do tempo, o separador), e cor translúcida confunde a busca de fundo feita pelo
cenário que mede contraste.

### A armadilha que o cenário pegou (step 4)

O tema do studio vem escrito em `oklch()`. Com ele em `oklch`, o cenário
`tests/e2e/home.spec.ts` **falhou**, acusando **2.55:1** num botão que tem
5.25:1:

```
Error: expect(received).toBeGreaterThanOrEqual(expected)
Expected: >= 4.5
Received:    2.5460959807926398
  at tests/e2e/home.spec.ts:105
```

O cenário lê a cor calculada do elemento e a interpreta como `rgb()`. O
Chromium **não converte `oklch()`** — devolve `oklch(0.546 0.245 262.881)` como
está — e o cenário passou a medir os três números do oklch como se fossem
canais de cor. **O defeito era da migração, não do cenário:** a paleta foi
reescrita em hexadecimal, com exatamente os mesmos tons, e a medição voltou a
valer. **O cenário não teve uma linha editada.** O motivo ficou registrado no
comentário do `app.css` e na §14.3 da arquitetura, porque morde de novo.

---

## Quality Criteria

| Criterion | Status | Evidence (real output) |
|---|:--:|---|
| `npm run build` sem aviso de classe desconhecida | ✅ | `npm run build 2>&1 \| grep -iE "warn\|error\|unknown"` → saída vazia; `✓ built in 7.29s` |
| `npm run dev` sobe | ✅ | `VITE v6.4.3 ready in 200 ms` · `LARAVEL v12.67.0 plugin v1.2.0` (subiu na 5174 porque a 5173 está tomada pelo container) |
| `npx vue-tsc --noEmit` → 0 erros | ✅ | saída vazia |
| `npm run lint` limpo | ✅ | `eslint . --fix` sem diagnóstico |
| A imagem Docker constrói | ✅ | `docker build` → **exit 0**; no log: `#23 [assets 4/7] RUN npm ci`, `#27 [assets 7/7] RUN npm run build`, `app-WHOhz9ET.css 69.22 kB`; imagem `gestao-eventos-ccc:tailwind4` 956MB |
| Cada semântica com contraste calculado e escrito, claro e escuro | ✅ | tabelas acima; os números estão nos comentários de `resources/css/app.css` |
| Nenhuma cor de texto abaixo de 4.5:1 | ✅ | varredura de navegador em 8 telas × 2 larguras × 2 temas, medindo **todo texto visível**: `VARREDURA: nenhum problema` |
| Os três cenários que medem contraste, verdes e sem edição | ✅ | `npx playwright test home acessibilidade-do-participante acessibilidade-e-responsividade` → **11 passed (8.7s)**; `git diff` em `tests/` vazio |
| Foco visível em todo elemento focável | ✅ | os dois cenários de teclado (`semAnelDeFoco` tem de ser `[]`) passam; o de `outline-none` → `outline-hidden` foi a maior troca do step 3 (133) |
| Modo escuro funcionando | ✅ | `@custom-variant dark (&:is(.dark *))` gera `:is(.dark *)` no CSS construído; a varredura roda cada tela também com `.dark` na raiz |
| As quatro semânticas continuam existindo e significando o mesmo | ✅ | `--cor-acao/-sucesso/-informacao/-atencao` com os três papéis (`-contraste`, `-texto`, e `-forte` no atenção) e as classes `bg-acao`, `text-sucesso-texto`, `bg-atencao` presentes no CSS construído |
| Nenhuma ocorrência remanescente das classes v3 da §3.5 | ⚠️ | ver *Prova das classes* abaixo — nove das dez com saída vazia; a décima não é provável por `grep` e está explicada |
| Telas principais em 360 px e desktop | ✅ | varredura automatizada: home, vitrine, formulário, cobrança, acompanhamento, painel, lista de inscrições e credenciais, a 360 px e 1280 px, claro e escuro — sem texto reprovado e sem rolagem horizontal |
| 40 cenários Playwright verdes, sem editar nenhum | ✅ | `npx playwright test` → **40 passed (40.5s)** |
| `git diff --stat` em `app/`, `routes/`, `database/` vazio | ✅ | `git diff --stat ab5c88f HEAD -- app routes database` → saída vazia |
| Nenhum arquivo importando `reka-ui` | ✅ | `grep -rn "reka-ui" resources/` → saída vazia; `radix-vue` segue em **74** arquivos |
| 542 testes Pest verdes | ✅ | `Tests: 542 passed (3807 assertions)` · `Duration: 62.47s` |

### Prova das classes (`grep` sobre `resources/js` e `resources/views`, 243 arquivos)

```
shadow (nu, v3)           0 ocorrencias
rounded-sm (v3)           0 ocorrencias
blur-sm (v3)              0 ocorrencias
blur (nu, v3)             0 ocorrencias
outline-none (v3)         0 ocorrencias
ring (nu, v3)             0 ocorrencias
*-opacity-* (v3)          0 ocorrencias
flex-shrink-* (v3)        0 ocorrencias
flex-grow-* (v3)          0 ocorrencias
overflow-ellipsis (v3)    0 ocorrencias
decoration-slice (v3)     0 ocorrencias
```

**A décima não dá `grep` vazio, e é honesto dizer por quê:** o nome v4 de
`shadow` é `shadow-sm`, que era um nome v3. Depois da migração restam **5**
ocorrências de `shadow-sm` — exatamente as 5 que antes eram `shadow` nu —, mais
**7** de `shadow-xs` (as antigas `shadow-sm`). Os números batem com o `git diff`
do step 3, e a soma das quatro trocas dá as 153 substituições. Não há como
provar isso com saída vazia; prova-se pela contagem.

---

## Verification

| Command | Result |
|---|---|
| `npm run build` | ✅ sem aviso |
| `npm run dev` | ✅ `VITE v6.4.3 ready in 200 ms` |
| `npm run lint` | ✅ limpo |
| `npx vue-tsc --noEmit` | ✅ 0 erros |
| `npx playwright test` | ✅ **40 passed (40.5s)** |
| `php artisan test` | ✅ **542 passed (3807 assertions)** |
| `docker build -t gestao-eventos-ccc:tailwind4 .` | ✅ exit 0 |
| `grep -rn "reka-ui" resources/` | ✅ vazio |
| `git diff --stat ab5c88f HEAD -- app routes database` | ✅ vazio |

---

## Deviations from Plan

1. **A paleta ficou em hexadecimal, não em `oklch()`.** O plano manda portar os
   tokens do studio "como vieram", e o studio os entrega em `oklch`. As cores
   são exatamente as mesmas, convertidas para sRGB. O motivo está acima: em
   `oklch` o cenário `home.spec.ts` quebra, e o plano é explícito — *"se um
   cenário de acessibilidade quebrar, o defeito é da migração; corrija a cor ou
   a classe, nunca o cenário"*.

2. **O `tailwind.config.js` foi removido no step 1, não no step 2.** O plano o
   coloca no step 2, mas o step 1 exige que `npm run build` conclua — e na v4 o
   arquivo antigo carrega `require('tailwindcss-animate')`, que já não existia.
   Para o step 1 fechar com build verde, a conversão mínima do `app.css` para a
   sintaxe v4 e a remoção do config andaram juntas. A paleta só mudou no step 2,
   como manda o plano.

3. **Cinco commits, não seis: o step 5 não gerou commit.** Ele é só prova, e
   nenhum arquivo mudou. Um commit vazio marcaria o passo sem dizer nada; as
   evidências estão neste relatório.

4. **Três tons do studio foram escurecidos e dois foram deixados opacos.** Está
   previsto pela DA-42, mas registro aqui porque significa que a paleta **não é
   byte a byte a do studio**. Cada ajuste está na tabela acima e no comentário
   ao lado do valor.

5. **`--border` / `--input` do modo escuro deixaram de ser translúcidos.**
   Mesma razão: `bg-border` é usado como superfície, e translucidez atrapalha a
   medição de contraste.

6. **`--radius` passou de `0.5rem` para `0.625rem`** e as sombras passaram a ser
   as do studio. É a §6 do plano ("as sombras e os raios do studio entram como
   vieram"). Os cantos ficaram 2 px mais arredondados em toda a interface.

---

## O que não deu para provar

- **A conferência olho no olho das sete telas não foi feita por um ser humano.**
  O que existe é uma varredura automatizada, que roda cada tela em 360 px e
  1280 px, no claro e no escuro, mede o contraste de **todo** texto visível e
  checa rolagem horizontal — e passou limpa. Ela **não** julga estética: se o
  azul do studio ficou feio em algum lugar, ou se um espaçamento parece errado
  com o raio novo, nenhum comando neste relatório saberia dizer. **Alguém
  precisa abrir as telas.**
- **A varredura foi um arquivo temporário, e foi apagada.** Ela não virou
  cenário porque o critério do plano fala em 40 cenários e acrescentar o 41º
  mudaria a conta. O resultado (`VARREDURA: nenhum problema`) está registrado
  aqui, mas **não é reexecutável a partir do repositório**.
- **O HMR do `npm run dev` foi verificado só até "o servidor sobe".** Não houve
  edição de arquivo com o navegador aberto para ver o recarregamento acontecer.
- **A tela de cobrança foi vista contra a Efí em modo de teste**, como já era o
  caso; nada foi exercitado contra dinheiro de verdade.
- **`--sidebar-*` foi portado inteiro, mas o painel foi visto só pela varredura.**
  Os 17 arquivos que usam `sidebar-*` não foram inspecionados um a um.
- **`chart-*` foi portado sem uso**, como a §6 do plano prevê. Nenhum gráfico
  existe para confirmar que a escala azul funciona na prática.

---

## Commit

- **Mensagem final:** `docs(css): close the tailwind v4 migration`
- **Commits do plano:** `93eafdc` · `e5a8a0d` · `2e305f0` · `9ac416c` · (este)
- **Arquivos deste commit:** `docs/ARCHITECTURE.md`, `docs/PROGRESS.md`,
  `.planning/feat/features/migracao-tailwind-v4/plan.done.md`
- O untracked `Prompt para Claude Code — Plataforma de Inscrições e Gestão de
  Eventos.md` na raiz **não foi tocado nem versionado**, como instruído.
