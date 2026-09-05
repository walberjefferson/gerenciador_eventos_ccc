# Action Plan — Etiquetas de situação e botões de ação coloridos nas listagens administrativas

> **Type:** feature
> **Created:** 2026-09-01 11:12
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Frontend Dev sênior Vue 3.5 + TypeScript strict + Inertia 2 + Tailwind 4 (CSS-first) + shadcn-vue/reka-ui, com disciplina de acessibilidade WCAG 2.1 AA e hábito de medir contraste em vez de estimar.

**Scope:** Somente a camada de apresentação das listagens administrativas e o design system que as sustenta. Entram: os tokens de superfície suave que faltam no `resources/css/app.css`, as variantes suaves do `badgeVariants`, um módulo único de mapeamento situação → etiqueta, um componente de botão de ação com intenção e ícone, e a aplicação disso em todas as listagens do painel. Os componentes do participante que já usam `Badge` entram apenas para passar a consumir o mapeamento central (sem mudança visual intencional).

**Fora do escopo:** back-end (nenhum controller, resource, enum ou migration muda), regras de negócio, filtros, paginação, layout das tabelas, tema público (`[data-tema='publico']`) além dos dois tokens novos que precisam existir lá por paridade, e a criação de um tema escuro (o projeto não tem bloco `.dark` com valores — não inventar um).

**Stack:** Vue 3.5.13 · TypeScript · Inertia 2.0.0-beta.3 · Tailwind 4.3.3 (sem `tailwind.config.js`) · reka-ui 2.10.4 · `class-variance-authority` · `lucide-vue-next` 0.468.0 · Playwright 1.62 · Laravel 12 (só leitura).

## 2. Direct Objective

Toda coluna de situação das listagens administrativas passa a exibir uma etiqueta (`Badge`) de superfície suave, com a cor derivada de um único mapeamento central por domínio (inscrição, pagamento, evento, webhook, ativo/inativo), e todo botão de ação de linha (abrir, editar, excluir e afins) passa a ter cor por intenção, ícone e alvo de toque de 44px, através de um componente único. Ao final, nenhuma tela repete mapa de cor de situação nem string de classe de botão solta, e nenhum par texto/fundo introduzido fica abaixo de 4.5:1 medido no navegador.

## 3. Minimum Inputs

### Entidades / Dados

Nenhuma entidade nova. Os valores vêm dos enums PHP já serializados como `situacao` (valor bruto) + `situacao_rotulo` (texto legível) — **os dois campos já existem nas props**, o valor bruto é o que decide a cor e o rótulo é o que a pessoa lê.

| Enum | Arquivo | Valores |
|---|---|---|
| `SituacaoInscricao` | `app/Enums/SituacaoInscricao.php` | `aguardando_pagamento`, `confirmada`, `expirada`, `cancelada`, `lista_espera` |
| `SituacaoPagamento` | `app/Enums/SituacaoPagamento.php` | `pendente`, `pago`, `falhou`, `expirado`, `cancelado`, `estornado` |
| `SituacaoEvento` | `app/Enums/SituacaoEvento.php` | `rascunho`, `publicado`, `inscricoes_abertas`, `inscricoes_encerradas`, `finalizado`, `cancelado` |
| `SituacaoWebhook` | `app/Enums/SituacaoWebhook.php` | `recebido`, `processado`, `ignorado`, `falhou` |
| booleano `ativo` | setores, grupos, usuários, dias, grupos de atividade, atividades | `true` / `false` |

### Regras de negócio (mapeamento de cor — decidido aqui, uma vez)

O tom nunca é enfeite: ele diz o que a pessoa deve fazer com a linha.

**Inscrição** — `aguardando_pagamento` → `atencao` (o relógio está correndo) · `confirmada` → `sucesso` · `expirada` → `erro` · `cancelada` → `neutra` · `lista_espera` → `informacao`.

**Pagamento** — `pendente` → `atencao` · `pago` → `sucesso` · `falhou` → `erro` · `expirado` → `erro` · `cancelado` → `neutra` · `estornado` → `informacao`.

**Evento** — `rascunho` → `neutra` · `publicado` → `informacao` · `inscricoes_abertas` → `sucesso` · `inscricoes_encerradas` → `atencao` · `finalizado` → `neutra` · `cancelado` → `erro`.

**Webhook** — `recebido` → `neutra` · `processado` → `sucesso` · `ignorado` → `neutra` · `falhou` → `erro`.

**Ativo/inativo** — `true` → `sucesso` · `false` → `neutra`.

Duas decisões que precisam sobreviver no comentário do código, porque parecem incoerência e não são:

1. **Inscrição cancelada é neutra, evento cancelado é erro.** Cancelar uma inscrição é rotina administrativa e acontece todo dia; cancelar um evento é excepcional e muda a vida de todo mundo que se inscreveu. Pintar as duas de vermelho ensinaria a ignorar o vermelho.
2. **`ignorado` continua neutro** — a decisão já está escrita em `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` e vale: não é erro, é o aviso que falava de cobrança que não existe aqui. Preservar o comentário ao migrar.

### Regras de acessibilidade (não negociáveis — o projeto já cobra)

- A palavra da situação continua escrita dentro da etiqueta. A cor reforça, nunca substitui (WCAG 1.4.1). Nenhuma etiqueta vira só bolinha colorida.
- Todo par texto/fundo introduzido precisa render **≥ 4.5:1**, com a razão calculada e escrita em comentário linha a linha no `app.css` — é a regra DA-42 do projeto, e há cenário Playwright que mede cor computada no navegador.
- Botão de ação com altura mínima de 44px (`min-h-11`), como já acontece em `Admin/Usuarios/Index.vue`.
- Ícone é decorativo: `aria-hidden="true"`. O nome acessível vem do texto do botão.
- Anel de foco preservado: `focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden` em todos os botões.

### Intenções dos botões de ação

| Intenção | Onde | Cor | Ícone (lucide) |
|---|---|---|---|
| `ver` | Abrir ficha, Detalhes | borda e texto em `informacao` | `Eye` |
| `editar` | Editar | borda e texto em `acao` | `Pencil` |
| `excluir` | Excluir, Remover, Sim, excluir | borda e texto em `destructive` | `Trash2` |
| `neutra` | Programação, Não, Redefinir senha, demais | borda `border`, texto `foreground` | livre (`CalendarDays`, `KeyRound`, …) ou nenhum |

Regra de composição: **uma linha tem no máximo uma ação `ver`, uma `editar` e uma `excluir`** — o resto é neutro. Três botões azuis lado a lado não hierarquizam nada. Por isso "Programação", em `Admin/Eventos/Index.vue`, fica neutro mesmo sendo navegação.

### Arquivos existentes a ler antes de começar

Obrigatórios:
- `resources/css/app.css` — linhas 75–230 (`:root`), 231–376 (`[data-tema='publico']`) e 377–462 (`@theme inline`). Entender que `--cor-X-suave` / `--cor-X-suave-contraste` já existem para `sucesso` e `atencao` nos dois blocos, e que **não existe bloco `.dark` com valores**.
- `resources/js/components/ui/badge/index.ts` — as sete variantes atuais e o comentário que explica o mapeamento do tema público.
- `resources/js/components/ui/button/index.ts` — como o projeto documenta variantes com `cva`; é o modelo de estilo a seguir.
- `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` — linhas 90–110: o `corDaSituacao` local, com o comentário sobre `ignorado` que precisa migrar junto.
- `resources/js/components/participante/ResumoDaInscricao.vue` e `HistoricoDaCobranca.vue` — os outros dois mapeamentos duplicados.
- `resources/js/components/admin/TabelaDeInscricoes.vue` — inclusive o comentário sobre `relative` no link "Abrir" (o `sr-only` posicionado que, sem âncora, estica o documento no celular). **Esse comportamento tem que sobreviver no componente novo.**
- `tests/e2e/home.spec.ts` linhas 29–60 — a função `contraste()` que mede no navegador.
- `tests/e2e/apoio.ts` e `tests/e2e/admin-inscricoes.spec.ts` — helpers e o jeito de autenticar no painel.

Consulta: `.planning/ui/project-ui-skill.md` (§ tokens e § padrões de código) e `docs/ARCHITECTURE.md` §14 (por que os componentes de `ui/` não se regeneram pelo CLI).

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `resources/css/app.css` | modify | Criar `--cor-informacao-suave` / `-contraste` e `--cor-erro-suave` / `-contraste` em `:root` e em `[data-tema='publico']`, com a razão de contraste calculada em comentário; expor os quatro em `@theme inline` como `--color-informacao-suave`, `--color-informacao-suave-foreground`, `--color-erro-suave`, `--color-erro-suave-foreground` |
| `resources/js/components/ui/badge/index.ts` | modify | Acrescentar as variantes `sucessoSuave`, `informacaoSuave`, `atencaoSuave`, `erroSuave` e `neutra` ao `badgeVariants`, sem tocar nas sete existentes |
| `resources/js/lib/situacoes.ts` | create | Fonte única: `varianteDaInscricao`, `varianteDoPagamento`, `varianteDoEvento`, `varianteDoWebhook`, `varianteDeAtivo`, todas devolvendo `BadgeVariants['variant']`, com o mapa e o porquê em comentário |
| `resources/js/components/admin/EtiquetaDeSituacao.vue` | create | Envolve `Badge`: recebe `dominio`, `situacao` (valor bruto) e `rotulo`, resolve a variante pelo módulo acima e renderiza o texto |
| `resources/js/components/admin/BotaoDeAcao.vue` | create | Botão/link de ação com `intencao` (`ver`/`editar`/`excluir`/`neutra`), `icone` opcional, `min-h-11`, anel de foco e suporte a `<Link>` do Inertia via prop `href` ou a `<button>` via `@click` |
| `resources/js/components/admin/TabelaDeInscricoes.vue` | modify | Colunas Situação e Cobrança viram `EtiquetaDeSituacao`; "Abrir" vira `BotaoDeAcao` intenção `ver`, preservando o `relative` e o `sr-only` com o nome da pessoa |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modify | Situação da ficha e situação de cada cobrança no histórico viram etiqueta |
| `resources/js/pages/Admin/Eventos/Index.vue` | modify | Coluna Situação vira etiqueta; Editar → `editar`, Programação → `neutra`, Excluir e "Sim, excluir" → `excluir`, "Não" → `neutra` |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modify | As três tabelas (dias, grupos de atividade, atividades) passam a usar etiqueta de ativo/inativo; Editar/Excluir/Remover viram `BotaoDeAcao` |
| `resources/js/pages/Admin/Catalogo/Setores.vue` | modify | Coluna Situação vira etiqueta de ativo/inativo; Editar/Excluir viram `BotaoDeAcao` |
| `resources/js/pages/Admin/Catalogo/GruposParticipantes.vue` | modify | Idem |
| `resources/js/pages/Admin/Usuarios/Index.vue` | modify | Trocar a pílula manual de Ativo/Desativado pela etiqueta; Editar/Redefinir senha/demais viram `BotaoDeAcao` (mantendo os `data-testid` que os cenários já usam) |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | modify | Remover `corDaSituacao` e `classeDaSituacao`; usar `EtiquetaDeSituacao` domínio webhook, migrando o comentário sobre `ignorado` |
| `resources/js/pages/Admin/Painel.vue` | modify | Situação do último aviso do provedor vira etiqueta |
| `resources/js/components/participante/ResumoDaInscricao.vue` | modify | Trocar o `switch` local por `varianteDaInscricao` do módulo central; manter `data-testid="situacao-da-inscricao"` |
| `resources/js/components/participante/HistoricoDaCobranca.vue` | modify | Trocar a função `variante` local por `varianteDoPagamento` |
| `tests/e2e/apoio.ts` | modify | Extrair `contraste(page, seletor)` de `home.spec.ts` para cá e exportar |
| `tests/e2e/home.spec.ts` | modify | Passar a importar `contraste` de `apoio.ts` em vez da cópia local |
| `tests/e2e/admin-listagens-visuais.spec.ts` | create | Cenários novos de etiqueta, contraste medido e alvo de toque |

## 5. Quality Criteria

- [ ] Nenhum arquivo fora de `resources/js/components/ui/badge/`, `resources/js/lib/situacoes.ts` e `resources/js/components/admin/` decide cor de situação — buscar por `bg-sucesso-suave`, `bg-destructive/10`, `corDaSituacao` e `rounded-full px-2 py-0.5` no fim e confirmar que só sobram os lugares centrais.
- [ ] Nenhuma listagem administrativa monta botão de ação com string de classe solta — `grep -rn "rounded-md border border-border px-3 py-1" resources/js/pages resources/js/components` não retorna nada em telas de listagem (os componentes de `ui/`, `DialogoDeAcao.vue` e formulários fora do escopo podem permanecer).
- [ ] Cada token novo no `app.css` tem, na linha acima, a razão de contraste calculada — mesmo formato dos vizinhos (`/* Texto #00506F sobre #E2F0F8: 7.59:1 */`).
- [ ] Toda etiqueta continua com a palavra escrita; nenhuma vira só cor.
- [ ] Todo botão de ação tem `min-h-11`, nome acessível legível e anel de foco visível pelo teclado.
- [ ] TypeScript strict sem erro e sem `any`: `varianteDa*` devolve `BadgeVariants['variant']`, não `string`.
- [ ] `npm run lint` e `npm run format:check` passam limpos.
- [ ] `npm run build` conclui sem aviso novo.
- [ ] Nenhum `data-testid` existente é removido ou renomeado — os cenários de `admin-inscricoes.spec.ts`, `admin-usuarios.spec.ts` e `admin-avisos-pagamento.spec.ts` precisam continuar verdes sem edição.
- [ ] Testes: a suíte E2E inteira (`npm run test:e2e`) passa — inclusive `home.spec.ts` depois da extração do helper, e os três cenários que medem contraste.
- [ ] Playwright E2E — cenários novos em `admin-listagens-visuais.spec.ts`:
  1. A lista de inscrições mostra a situação e a cobrança como etiqueta, com o texto do rótulo visível e legível por nome acessível.
  2. Duas inscrições em situações diferentes rendem etiquetas com cores de fundo diferentes (a cor está mesmo variando, não é decoração fixa).
  3. O contraste texto/fundo de cada etiqueta visível na lista é ≥ 4.5:1, medido no navegador.
  4. O contraste do texto de cada botão de ação da linha é ≥ 4.5:1.
  5. Todo botão de ação da linha tem altura renderizada ≥ 44px.
  6. O caminho de exclusão em `Admin/Eventos/Index` continua alcançável e operável só pelo teclado, com foco visível.

## 6. Ambiguity Handling

**Decisões tomadas com o solicitante (não revisitar):**
- Escopo: todas as listagens administrativas; telas do participante entram só para consumir o mapeamento central.
- Botões: outline colorido por intenção — borda e texto na cor, fundo neutro. Nada de fundo cheio na tabela.
- Ícone à esquerda do rótulo, com a palavra sempre escrita.
- Centralizar: módulo único de mapeamento + componente único de botão; as três duplicatas morrem.
- Etiqueta de tom suave, criando os dois tokens que faltam.

**Suposições assumidas:**
- **Os valores de cor abaixo são propostas calculadas, não dogma.** Ponto de partida para `:root`: `--cor-informacao-suave: #e2f0f8` com `--cor-informacao-suave-contraste: #00506f` (≈7.59:1) e `--cor-erro-suave: #fdeaea` com `--cor-erro-suave-contraste: #9b1c1c` (≈7.04:1). O executor **recalcula** cada razão antes de escrever o comentário; se der abaixo de 4.5:1, escurece o texto até passar. Para `[data-tema='publico']`, derivar da família daquele bloco (`--cor-informacao: #0c5a75`, `--destructive: #a93425`) pelo mesmo método usado em `--cor-sucesso-suave: #e3efe9`, e calcular de novo.
- A variante `neutra` usa `bg-muted text-foreground` — não `text-muted-foreground`, que rende só 4.39:1 sobre o próprio `--muted` (o comentário na linha 94 do `app.css` já registra isso).
- Não existe tema escuro com valores no projeto; portanto não se cria bloco `.dark`. Se um dia existir, os tokens novos entram lá pelo mesmo caminho.
- O nome do token é `erro-suave`, não `destructive-suave`: a camada semântica do domínio é em português (DA-41), e `destructive` é da camada shadcn.
- Os componentes `EtiquetaDeSituacao` e `BotaoDeAcao` moram em `components/admin/` porque é onde vivem os componentes de painel — não em `components/ui/`, que é a biblioteca copiada e não se mistura com componente de produto.
- Sem Vitest no projeto: a verificação de front é Playwright + lint + build. Não introduzir runner de teste novo.

**Se ficar em dúvida durante a execução:**
- Situação sem mapa (enum novo, valor inesperado): cair no neutro, nunca quebrar a tela — e registrar no relatório de execução, não inventar cor.
- Uma tela onde a mudança visual exigiria mexer no layout da tabela (coluna estourando, quebra no celular): parar e perguntar antes de reorganizar colunas — o layout está fora do escopo.
- Se um cenário Playwright existente quebrar, a suspeita padrão é o seletor perdido, não o cenário errado: restaurar o `data-testid` ou o texto e rodar de novo.

## 7. Prohibitions

- ❌ Nunca deixar a cor ser a única portadora da informação — a palavra fica escrita, sempre.
- ❌ Nunca escrever um par texto/fundo sem calcular o contraste; nunca chutar a razão no comentário.
- ❌ Nunca criar um bloco `.dark` no `app.css` — o projeto não tem tema escuro com valores.
- ❌ Nunca alterar as sete variantes existentes de `badgeVariants` nem qualquer classe com prefixo `publico:` — quarenta telas dependem delas.
- ❌ Nunca regenerar componentes de `resources/js/components/ui/` pelo CLI do shadcn (`docs/ARCHITECTURE.md` §14).
- ❌ Nunca tocar em controller, resource, enum, migration, rota ou regra de negócio: esta entrega é de apresentação.
- ❌ Nunca remover, renomear ou "melhorar" `data-testid` existente.
- ❌ Nunca introduzir vee-validate, Zod, biblioteca de ícones nova ou dependência qualquer — `lucide-vue-next` já está instalado.
- ❌ Nunca remover o `relative` do link "Abrir" da tabela de inscrições nem o `sr-only` com o nome da pessoa: o comentário no arquivo explica o defeito de layout no celular que isso corrige.
- ❌ Nunca apagar os comentários que explicam decisão (o do `ignorado`, o do `--muted`, o dos temas no `badge/index.ts`) — migrar junto com o código.

---

## Execution Steps

1. **Tokens.** Criar `--cor-informacao-suave` / `-contraste` e `--cor-erro-suave` / `-contraste` em `:root` e em `[data-tema='publico']` no `resources/css/app.css`, cada um com a razão de contraste calculada em comentário no formato dos vizinhos; expor os quatro em `@theme inline`.
2. **Variantes da etiqueta.** Acrescentar `sucessoSuave`, `informacaoSuave`, `atencaoSuave`, `erroSuave` e `neutra` ao `badgeVariants` em `resources/js/components/ui/badge/index.ts`, com um comentário explicando quando usar suave (lista longa) e quando usar cheia (destaque isolado). Não mexer nas sete existentes.
3. **Fonte única.** Criar `resources/js/lib/situacoes.ts` com as cinco funções de mapeamento da §3, tipadas como `BadgeVariants['variant']`, com o mapa e as duas justificativas (cancelada neutra × cancelado erro; `ignorado` neutro) escritas em comentário.
4. **Componentes.** Criar `resources/js/components/admin/EtiquetaDeSituacao.vue` e `resources/js/components/admin/BotaoDeAcao.vue` conforme a §4 — `BotaoDeAcao` renderiza `<Link>` quando recebe `href` e `<button>` caso contrário, com `min-h-11`, ícone `aria-hidden` e anel de foco.
5. **Inscrições.** Aplicar os dois componentes em `components/admin/TabelaDeInscricoes.vue` (situação, cobrança e o botão Abrir, preservando `relative` + `sr-only`) e em `pages/Admin/Inscricoes/Show.vue` (situação da ficha e do histórico).
6. **Eventos.** Aplicar em `pages/Admin/Eventos/Index.vue` (situação do evento; Editar/Programação/Excluir/confirmação) e em `pages/Admin/Eventos/Estrutura.vue` (as três tabelas de ativo/inativo e seus botões).
7. **Catálogo e usuários.** Aplicar em `Catalogo/Setores.vue`, `Catalogo/GruposParticipantes.vue` e `Usuarios/Index.vue`, trocando a pílula manual de Ativo/Desativado pela etiqueta e mantendo todos os `data-testid`.
8. **Avisos, painel e participante.** Migrar `Pagamentos/Avisos/Index.vue` (apagando `corDaSituacao`/`classeDaSituacao` e levando o comentário do `ignorado` para `situacoes.ts`), `Admin/Painel.vue`, `ResumoDaInscricao.vue` e `HistoricoDaCobranca.vue` para o mapeamento central. Ao final, buscar pelas classes de cor de situação no projeto e confirmar que só sobram os lugares centrais.
9. **Testes.** Extrair `contraste()` de `tests/e2e/home.spec.ts` para `tests/e2e/apoio.ts`, ajustar o import em `home.spec.ts` e escrever `tests/e2e/admin-listagens-visuais.spec.ts` com os seis cenários da §5.
10. **Verificação.** Rodar `npm run lint`, `npm run format:check`, `npm run build` e `npm run test:e2e` inteiro; corrigir o que aparecer e registrar no relatório de execução qualquer situação que tenha caído no neutro por falta de mapa.

## Done

Todas as listagens do painel mostram situação como etiqueta de tom suave vinda de um único mapeamento, todos os botões de ação de linha têm cor por intenção, ícone e 44px de altura vindos de um único componente, as três duplicatas de mapa de cor sumiram, e a suíte Playwright inteira — incluindo os cenários novos que medem contraste e alvo de toque no navegador — passa.

## Commit

`feat(admin): etiquetas de situacao e botoes de acao coloridos nas listagens`
