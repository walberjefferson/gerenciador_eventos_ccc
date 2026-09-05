# Action Plan — Campos e selects ocupando a largura nos formulários administrativos

> **Type:** feature
> **Created:** 2026-09-01 17:05
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Frontend Dev sênior Vue 3.5 + Inertia 2 + Tailwind 4 (CSS-first, sem `tailwind.config.js`), com olho para grade responsiva e disciplina de acessibilidade — sabe que mexer na ordem visual de um formulário mexe também na ordem de tabulação, e trata as duas como a mesma coisa.

**Scope:** Quatro pontos de layout nos formulários do painel, todos de CSS/markup. Nada de back-end, nada de regra de negócio, nada de componente novo.

**Fora do escopo, por decisão registrada:**
- Os seletores de evento do `Painel.vue` e do `Portaria/Index.vue` (`sm:max-w-md`) **ficam como estão** — são controle de contexto no topo da página, não campo de formulário, e esticá-los por um monitor de 1920px só afasta o rótulo do valor.
- Os filtros que já fecham a grade: `FiltrosDeInscricao.vue` (9 campos em 3 colunas), `Auditoria/Index.vue` (4 em 4) e os filtros de `Usuarios/Index.vue` (3 em 3).
- `Catalogo/Setores.vue` e `Catalogo/GruposParticipantes.vue`: os campos são empilhados em `flex-col` dentro do diálogo e já ocupam a largura inteira dele.
- Os `<p class="max-w-3xl">` de texto explicativo — limite de largura de leitura é acerto, não defeito.

**Stack:** Vue 3.5.13 · TypeScript · Inertia 2 · Tailwind 4.3.3 · Playwright 1.62.

## 2. Direct Objective

Nos quatro pontos identificados, o campo passa a ocupar a largura que a grade do formulário oferece, sem sobrar coluna vazia ao lado e sem virar faixa de ponta a ponta: o campo Situação do formulário de evento deixa de ter teto próprio, os dois diálogos da estrutura do evento param de deixar dois buracos no desktop, e os filtros de avisos fecham a segunda linha na faixa `md`.

## 3. Minimum Inputs

### O inventário — os quatro casos, já medidos no código

**Caso 1 — `resources/js/pages/Admin/Eventos/Formulario.vue:188`, campo Situação.**
O wrapper é `<div class="flex flex-col gap-1 md:max-w-xs">` e o campo está **fora** das grades da seção (que são `md:grid-cols-2` nas linhas 108 e 158). Resultado no desktop: um select estreito sozinho, com a linha inteira vazia à direita. O `<select>` interno já tem `w-full` — o teto vem do wrapper, não dele.

**Casos 2 e 3 — `resources/js/pages/Admin/Eventos/Estrutura.vue:558` (diálogo do grupo de atividade) e `:739` (diálogo da atividade).**
Os dois têm a mesma estrutura e o mesmo defeito. A grade é `sm:grid-cols-2` com três campos nesta ordem:

| Ordem | Campo | Classe hoje |
|---|---|---|
| 1º | Dia (grupo) / Grupo (atividade) — `<select>` | 1 coluna |
| 2º | Nome — `<input>` | `md:col-span-2` |
| 3º | Posição — `<input>` | 1 coluna |

O `md:col-span-2` no campo do meio quebra a grade de `md` para cima e produz **dois buracos**:

```
sm (640–767px)              md+ (768px+)
┌───────────┬───────────┐   ┌───────────┬───────────┐
│ Dia       │ Nome      │   │ Dia       │  (vazio)  │
│ Posição   │  (vazio)  │   │ Nome (linha inteira)  │
└───────────┴───────────┘   │ Posição   │  (vazio)  │
                            └───────────┴───────────┘
```

**Caso 4 — `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue:114`, filtros.**
Grade `md:grid-cols-3 lg:grid-cols-5` com cinco campos: A partir de, Até, Situação, Provedor, Assinatura. Em `lg` fecha certo (5 em 5). Em `md` a segunda linha leva Provedor e Assinatura e **sobra uma coluna vazia**.

### As regras que valem para os quatro

1. **Teto de largura em wrapper de campo de formulário sai.** Quem manda na largura é a grade, não o campo.
2. **Campo que sobra sozinho na última linha fecha a linha** com o `col-span` da faixa correspondente — e o `col-span` precisa ser declarado na **mesma faixa** da grade que ele preenche (`sm:grid-cols-2` pede `sm:col-span-2`, não `md:col-span-2`). O defeito dos casos 2 e 3 nasceu exatamente dessa discordância de faixa.
3. **Reordenar é permitido quando melhora a leitura**, e a ordem visual e a de tabulação têm de continuar sendo a mesma — o formulário é navegado no teclado.

### Como fica cada caso

**Caso 1:** tirar `md:max-w-xs` do wrapper. O campo passa a ocupar a largura da seção, alinhado com as grades de cima.

**Casos 2 e 3:** trocar `md:col-span-2` por `sm:col-span-2` no campo Nome **e** movê-lo para primeiro. A ordem passa a ser Nome (linha inteira) → Dia/Grupo → Posição:

```
sm+ (a partir de 640px)
┌───────────────────────┐
│ Nome (linha inteira)  │
├───────────┬───────────┤
│ Dia       │ Posição   │
└───────────┴───────────┘
```

Nome primeiro não é só arrumação de grade: é o campo principal do que se está criando, e hoje ele aparece depois de "Dia", que é acessório. A ordem de tabulação melhora junto — quem abre o diálogo digita o nome antes de escolher onde encaixar.

**Caso 4:** dar `md:col-span-2 lg:col-span-1` ao campo **Provedor**. Em `md`, Provedor (2) + Assinatura (1) fecham as três colunas; em `lg`, os cinco voltam a uma coluna cada. Provedor e não Assinatura porque nome de provedor é o texto mais longo dos dois.

### Arquivos existentes a ler antes de começar

- `resources/js/pages/Admin/Eventos/Formulario.vue` — linhas 104-205, para ver as grades da seção de identificação e onde o campo Situação se encaixa.
- `resources/js/pages/Admin/Eventos/Estrutura.vue` — linhas 556-600 e 737-782, os dois diálogos inteiros, incluindo os `@submit` e a ordem dos campos.
- `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` — linhas 112-185.
- `resources/js/components/admin/FiltrosDeInscricao.vue` — a referência de grade que **já está certa** (9 campos em `md:grid-cols-3`); serve de modelo, não se mexe nela.
- `.planning/ui/project-ui-skill.md`, seção de padrões — o projeto não tem `tailwind.config.js`; tudo vem do `@theme` em `resources/css/app.css`.
- `tests/e2e/admin-avisos-pagamento.spec.ts` e `tests/e2e/admin-inscricoes.spec.ts` — os cenários que já tocam essas telas e não podem quebrar.

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `resources/js/pages/Admin/Eventos/Formulario.vue` | modify | Remover `md:max-w-xs` do wrapper do campo Situação |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modify | Nos dois diálogos: mover Nome para primeiro e trocar `md:col-span-2` por `sm:col-span-2` |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | modify | `md:col-span-2 lg:col-span-1` no campo Provedor |
| `tests/e2e/formularios-admin-largura.spec.ts` | create | Mede no navegador que os campos ocupam a largura da grade e que não sobra coluna vazia |

## 5. Quality Criteria

- [ ] Nenhum wrapper de campo de formulário no painel guarda `max-w-*` — exceto os dois seletores de evento (`Painel.vue:146` e `Portaria/Index.vue:144`), que ficam por decisão registrada.
- [ ] Nos dois diálogos da estrutura, em qualquer largura a partir de 640px, não existe célula vazia ao lado de um campo.
- [ ] Todo `col-span` declarado está na mesma faixa responsiva da grade que ele preenche — nenhum `md:col-span-*` dentro de grade `sm:grid-cols-*`.
- [ ] A ordem de tabulação segue a ordem visual em todos os formulários tocados: percorrer com Tab desce a tela sem saltar para trás.
- [ ] Nenhum `id`, `for`, `v-model`, `aria-describedby` ou `data-testid` muda ao mover os campos — só a posição no markup.
- [ ] Os rótulos continuam ligados aos campos: cada `<label for>` aponta para o `id` que existe.
- [ ] Nenhuma mudança de comportamento: os mesmos campos, as mesmas validações, o mesmo `@submit`.
- [ ] `npm run lint`, `npx vue-tsc --noEmit` e `npm run build` limpos.
- [ ] Testes: `tests/e2e/admin-avisos-pagamento.spec.ts` e os demais cenários do painel continuam com o mesmo resultado de antes (a suíte tem 4 falhas pré-existentes conhecidas — comparar com a linha de base, não com zero).
- [ ] Playwright E2E — `formularios-admin-largura.spec.ts`, medindo no navegador em viewport de desktop (1280px):
  1. No formulário de evento, o campo Situação tem largura igual à do campo Nome (mesma grade, mesma medida), com tolerância de 2px.
  2. No diálogo de grupo de atividade, o campo Nome ocupa a linha inteira e Dia e Posição dividem a linha seguinte — verificado por `boundingBox`, comparando o topo (`y`) dos dois para provar que estão lado a lado.
  3. No mesmo diálogo, nenhum campo tem largura menor que ~metade da grade com espaço vazio ao lado.
  4. Nos filtros de avisos, em viewport `md` (768px), a segunda linha não deixa coluna vazia: a soma das larguras dos dois campos mais o gap cobre a largura da grade.

## 6. Ambiguity Handling

**Decisões tomadas com o solicitante (não revisitar):**
- Corrigir tanto os wrappers com teto quanto os campos órfãos na grade.
- O alvo é **encaixar na grade do formulário**, não esticar tudo a 100% da tela.
- Os seletores de evento do Painel e da Portaria ficam como estão.

**Suposições assumidas:**
- Mover o campo Nome para primeiro nos dois diálogos é a forma de fechar a grade sem inventar coluna: a alternativa seria deixar Dia e Posição juntos e o Nome por último, o que colocaria o campo principal no fim do formulário.
- A tolerância de 2px nas medidas do teste existe porque borda e arredondamento de subpixel fazem duas larguras "iguais" diferirem por frações — comparar com igualdade exata daria teste instável.
- O caso 4 se resolve com `col-span` responsivo em vez de mudar a grade para `md:grid-cols-2`, porque a grade de 3 colunas está certa para os três primeiros campos (duas datas e uma situação, todos curtos).
- Nenhum `<select>` precisa ganhar `w-full`: os 28 do painel já têm.

**Se ficar em dúvida durante a execução:**
- Se mover o campo Nome quebrar algum seletor de teste que dependa de ordem (`nth-child`, por exemplo): **conserte o seletor para apontar pelo rótulo ou pelo `id`**, não desfaça a reordenação — seletor por posição em formulário é frágil por natureza.
- Se aparecer um quinto caso de campo órfão que a análise não pegou: corrija pelo mesmo critério e **registre no relatório**, não invente regra nova.
- Se alguma mudança exigir tocar em componente de `resources/js/components/ui/`: pare e pergunte — esta entrega é de página, não de biblioteca.

## 7. Prohibitions

- ❌ Nunca mexer nos seletores de evento do `Painel.vue` e do `Portaria/Index.vue`.
- ❌ Nunca remover `max-w-*` de `<p>` de texto explicativo — ali o limite é acerto de leitura.
- ❌ Nunca mudar `id`, `for`, `v-model`, `aria-describedby` ou `data-testid` ao reordenar.
- ❌ Nunca alterar validação, `@submit`, props ou qualquer comportamento — a entrega é de layout.
- ❌ Nunca deixar ordem visual divergente da ordem de tabulação.
- ❌ Nunca mexer em `FiltrosDeInscricao.vue`, `Auditoria/Index.vue`, `Catalogo/Setores.vue` nem `Catalogo/GruposParticipantes.vue` — foram analisados e estão corretos.
- ❌ Nunca reformatar arquivo fora do escopo (`npm run format:check` já não fecha para ~24 arquivos alheios).
- ❌ Nunca tocar em `resources/js/components/ui/`.

---

## Execution Steps

1. **Formulário de evento.** Remover `md:max-w-xs` do wrapper do campo Situação em `Formulario.vue:188` e conferir na tela que ele passa a acompanhar a largura das grades da seção.
2. **Diálogo do grupo de atividade.** Em `Estrutura.vue` (~linha 558): mover o campo Nome para o primeiro lugar da grade e trocar seu `md:col-span-2` por `sm:col-span-2`. Dia e Posição passam a dividir a segunda linha.
3. **Diálogo da atividade.** O mesmo em `Estrutura.vue` (~linha 739), com Grupo no lugar de Dia.
4. **Filtros de avisos.** `md:col-span-2 lg:col-span-1` no campo Provedor em `Avisos/Index.vue`.
5. **Verificação.** Escrever `tests/e2e/formularios-admin-largura.spec.ts` com os quatro cenários da §5; depois `npm run lint`, `npx vue-tsc --noEmit`, `npm run build` e a suíte E2E inteira, comparando com a linha de base (4 falhas pré-existentes). Registrar no relatório qualquer caso extra encontrado.

## Done

Os quatro pontos ocupam a largura que a grade oferece, sem coluna vazia ao lado; a ordem de tabulação acompanha a ordem visual nos formulários reordenados; nenhum identificador ou comportamento mudou; e um cenário Playwright mede as larguras no navegador para que a correção não se desfaça sozinha no próximo ajuste de grade.

## Commit

`fix(admin): campos de formulario ocupando a largura da grade`
