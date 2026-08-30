# Project UI Skill — gestão de eventos CCC — 2026-08-27

> Skill contextual gerada pelo ui-scanner. Use como referência em todo componente novo.

## Stack confirmada

| Tecnologia | Versão | Status |
|---|---|---|
| Vue | 3.5.13 | ✅ |
| Inertia (@inertiajs/vue3) | 2.0.0-beta.3 | ✅ |
| Laravel | 12 | ✅ |
| shadcn-vue (componentes copiados, não pacote) | 23 componentes em `resources/js/components/ui/` | ✅ — código do repositório, adaptado à mão |
| reka-ui (primitivas) | 2.10.4 | ✅ — migrado de `radix-vue`, que saiu do `package.json` |
| Tailwind | 4.3.3 | ✅ — CSS-first, sem `tailwind.config.js` |
| Formulário | `useForm` do Inertia (não vee-validate/zod) | ✅ — consistente em todo o projeto |
| Zod / vee-validate | não instalados | ⚠️ não usados — validação é feita no back-end (Laravel) e refletida via `errors` do Inertia |
| Ícones | `lucide-vue-next` 0.468.0 | ✅ |
| Toast | `vue-sonner`-like próprio (`ui/toast`) | ✅ |
| E2E | Playwright | ✅ — 3 cenários medem contraste real (`acessibilidade-do-participante`, `acessibilidade-e-responsividade`, `home`) |

## Notas críticas do `stack.json` (já incorporadas nesta análise)

1. Tailwind 4 configura tudo em `resources/css/app.css` via `@theme` — não existe `tailwind.config.js`.
2. Os 23 componentes de `components/ui/` são código do repositório adaptado à mão (comentários em português, correções de acessibilidade). **Não regerar pelo CLI shadcn** sobre os existentes — ver `docs/ARCHITECTURE.md` §14.
3. A paleta tem tokens semânticos próprios em português (`acao`, `sucesso`, `informacao`, `atencao`) com contraste WCAG calculado e documentado em comentário no `app.css` — deliberado, não desvio.
4. Os cenários Playwright de contraste leem a cor computada como `rgb()`; por isso os tokens estão em hexadecimal, não `oklch()`, embora o tema-base (shadcn studio) venha em oklch.
5. As telas públicas são mobile-first de verdade (Pixel 5 no Playwright) — não avaliar como desvio o fato de não "aproveitarem" a largura em desktop.

## Tokens customizados do projeto

### Cores — camada shadcn studio (tema azul, DA-40)

| Token | Claro | Escuro | Uso observado |
|---|---|---|---|
| `--primary` | `#155DFC` | `#2B7FFF` | Botões de ação primária, links, foco |
| `--destructive` | `#E7000B` | `#FF6467` | Erros, exclusões |
| `--border` / `--input` | `#E4E4E7` | `#27272A` / `#2E2E33` | Bordas, campos |
| `--radius` | `0.625rem` (10px) | — | Base do raio de todos os componentes |
| `--sombra-*` | ver `app.css` | — | Mapeadas em `@theme inline` para `--shadow-*` |

### Cores — camada semântica própria em português (DA-41)

Estas quatro **não existem no tema shadcn studio original** — foram derivadas do esquema azul mantendo o papel semântico, e cada uma tem três variantes: `--cor-X` (superfície), `--cor-X-contraste` (texto sobre a superfície), `--cor-X-texto` (a cor usada como texto sobre o fundo da página). Expostas ao Tailwind como `bg-acao`, `text-acao-texto`, `bg-sucesso`, `text-sucesso-texto`, `bg-informacao`, `text-informacao-texto`, `bg-atencao`, `text-atencao-texto`, `text-atencao-forte`.

| Token | Papel | Claro | Escuro |
|---|---|---|---|
| `acao` | ação principal (equivale a primary, mas com nome de domínio) | `#155DFC` | `#2B7FFF` |
| `sucesso` | positivo / disponibilidade | `#007A55` | `#00BC7D` |
| `informacao` | navegação / neutro-informativo | `#0069A8` | `#00BCFF` |
| `atencao` | alerta / contagem de tempo | `#FE9A00` (texto: `#973C00`) | `#FFB900` |

Todas com razão de contraste ≥ 4.5:1 documentada em comentário linha a linha no `app.css` (regra do projeto DA-42). **Não tratar como token "extra" a normalizar** — são a linguagem visual do domínio (inscrição, pagamento, status).

### Tipografia
- `--font-sans`: `Instrument Sans` (não é o Inter sugerido no `ui-theme-reference` — é uma escolha de tema deliberada do studio, manter).
- Sem fonte mono customizada em uso ativo (nenhum bloco de código na aplicação).

### Sem breakpoints customizados — usa os padrões do Tailwind 4.

## Componentes existentes — inventário

### Componentes de biblioteca instalados (`resources/js/components/ui/`)
alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, date-field, dialog, dropdown-menu, input, label, navigation-menu, progress, radio-group, select, separator, sheet, sidebar, skeleton, toast, tooltip (23 componentes).

`badgeVariants` e `alertVariants` já incluem as variantes semânticas do domínio (`sucesso`, `informacao`, `atencao`) ao lado das padrão do shadcn (`default`, `secondary`, `destructive`, `outline`) — é o ponto de referência para estender qualquer variante nova.

### Componentes de produto (187 arquivos `.vue` no total)

| Componente | Arquivo | Padrão observado |
|---|---|---|
| CartaoDeAtividade | `components/inscricao/CartaoDeAtividade.vue` | Card de seleção com estado marcado, usado no formulário de inscrição |
| IndicadorDePassos | `components/inscricao/IndicadorDePassos.vue` | Stepper do formulário em 4 etapas |
| PassoDadosPessoais / PassoParticipacao / PassoRevisao | `components/inscricao/` | Cada etapa é um componente próprio; `PassoRevisao` implementa o passo de revisão final (regra 10.7) |
| DialogoDeAcao | `components/admin/DialogoDeAcao.vue` | Diálogo de confirmação administrativa com justificativa obrigatória, devolução de foco e `aria-describedby` — referência de acessibilidade do projeto |
| FiltrosDeInscricao | `components/admin/FiltrosDeInscricao.vue` | Filtros de listagem — usa `<select>`/`<input>` nativos estilizados manualmente, não os componentes `ui/select` e `ui/input` |
| TabelaDeInscricoes / TabelaDeVagas | `components/admin/` | Tabela como padrão default de listagem admin (regra 7.2) |
| QrCodePix / CodigoCopiaECola / ContadorRegressivo | `components/pagamento/` | Fluxo de pagamento Pix — específico do domínio |
| LinhaDoTempo / MarcoDaLinhaDoTempo / HistoricoDaCobranca / ResumoDaInscricao | `components/participante/` | Timeline de status da inscrição, visível para admin e participante |

## Padrões de código identificados

### Estilo dos componentes
- [x] `<script setup lang="ts">` em 180 dos 187 arquivos `.vue` — praticamente universal.
- [x] Nenhum uso de Options API (`export default {}`) encontrado.
- [x] TypeScript em 100% dos componentes com `script setup`.

### Padrão de formulário
Inertia `useForm()` nativo (não vee-validate, não Zod) em 16 telas — inclusive nas telas administrativas com CRUD (Cidades, GruposParticipantes, Eventos/Formulario, Eventos/Estrutura, Credenciais). Erros vêm do back-end Laravel via `form.errors`. Consistente em todo o projeto — **não introduzir vee-validate/Zod em componentes novos** sem alinhamento, quebraria o padrão único hoje em vigor.

### Composables
Pasta `resources/js/composables/`: `useAppearance` (tema claro/escuro/sistema, com `prefers-color-scheme`), `useContadorRegressivo`, `useGruposDaCidade`, `useInitials`, `useSelecaoAtividades`. Padrão: função nomeada `useAlgo()`, retorno de `ref`s/funções, sem objeto `{data, isLoading, error}` genérico — são composables de domínio, não genéricos de fetch (o fetch de dados é feito via Inertia, que já entrega as props na página).

### Classe condicional
`cn()` (de `@/lib/utils`, `clsx` + `tailwind-merge`) usado em 87 arquivos — é o padrão dominante. Nenhum uso de `clsx()` direto fora do `cn()`.

### Organização
Por feature dentro de `components/`: `admin/`, `eventos/`, `inscricao/`, `pagamento/`, `participante/`, além dos componentes de app-shell na raiz (`AppHeader`, `AppSidebar` etc.) e `ui/` para a biblioteca. `pages/` segue a mesma divisão (`Admin/Eventos`, `Admin/Catalogo`, `Inscricoes/`, `Eventos/`, `auth/`, `settings/`).

## Convenções de nomenclatura

- **Componentes de produto**: PascalCase, **nomes de domínio em português** (`CartaoDeAtividade.vue`, `DialogoDeAcao.vue`, `LinhaDoTempo.vue`) — coerente com a regra de idioma do projeto (domínio em pt-BR, infraestrutura Laravel em inglês).
- **Componentes de biblioteca** (`ui/`): PascalCase em inglês (`Button.vue`, `DialogContent.vue`) — seguem a convenção shadcn original, não traduzidos.
- **Composables**: `useAlgo` em inglês mesmo quando o domínio é `useGruposDaCidade`, `useSelecaoAtividades` (mistura pt-BR no nome do domínio, prefixo `use` em inglês — é o padrão Vue, mantido).
- **Props/emits**: camelCase no `<script>`; no `<template>`, atributos de componente próprio usam nomes em português quando expressam o domínio (`:aberto`, `:processando`, `@confirmar`).
- **Diretórios**: minúsculo-por-feature (`admin/`, `inscricao/`), Pages em PascalCase por rota Inertia.

## Padrões de layout observados

- **Admin**: sidebar fixa (`AppSidebar.vue`, componente `ui/sidebar`) com 5 itens principais (Painel, Eventos, Inscrições, e condicionalmente Auditoria e Credenciais de pagamento conforme permissão) — dentro da faixa "sidebar para 4+ itens" da regra 5.4. Item ativo destacado (regra 5.7 atendida via `activeItemStyles`/estado do `NavigationMenu`).
- **Público**: `PublicoLayout.vue` — coluna única, mobile-first, botões `h-12 w-full` (altura maior que os 40px do tema-base, e mais que os 44px mínimos de toque — decisão correta para o contexto de uso no celular).
- **Formulário de inscrição**: 4 passos com `IndicadorDePassos`, cada passo em componente próprio, passo de revisão final antes do envio (atende 10.7 — review step em fluxo irreversível/pagamento).

## Observações visuais

Não foi possível inspecionar a aplicação pelo navegador: a ferramenta `WebFetch` não alcança `http://localhost:8888` (é um host local, fora do alcance do fetch remoto da ferramenta). O servidor **está** respondendo (`200` em `/`, `/eventos/copa-ccc-2026`, `/eventos/copa-ccc-2026/inscricao`; `404` em `/admin`, esperado sem sessão autenticada — não foi possível autenticar para inspecionar o painel). A análise visual abaixo vem da leitura direta do código-fonte Vue, que neste projeto expressa fielmente o resultado renderizado (classes Tailwind inline, sem CSS externo por componente).

- **Paleta dominante**: azul (`--primary`/`--acao` `#155DFC` claro, `#2B7FFF` escuro), com verde/âmbar/azul-céu reservados estritamente para os papéis semânticos (sucesso/atenção/informação) — sem repurpose decorativo encontrado (regra 1.3 respeitada).
- **Densidade**: confortável no público (`space-y-8`, botões grandes), mais compacta no admin (tabelas, filtros com `h-10`).
- **Bordas e sombras**: raio único (`--radius: 0.625rem`) derivado consistentemente em `sm/md/lg/xl`; sombras só do sistema (`--sombra-*`/`--shadow-*`), nenhum `shadow-[...]` arbitrário encontrado.
- **Animações**: 36 arquivos usam `animate-`/`transition-`, concentradas nos componentes `ui/` (dropdown, dialog, sheet, tooltip — via `data-[state]` do reka-ui). Nenhuma ocorrência de `motion-reduce`/`prefers-reduced-motion` no CSS de componentes nem no `app.css`.

## Inconsistências identificadas

1. **Altura de botão e input não coincidem no padrão default.** `Button` (variant `default`, size `default`) usa `h-9` (36px); `Input` usa `h-10` (40px). O tema documenta `--height-input`/`--height-button` como 40px pareados (ui-theme-reference), mas o `buttonVariants` do projeto não segue esse valor. Na prática, isso é mitigado em telas críticas porque muitos lugares sobrescrevem com `class="h-10"`/`h-11`/`h-12` — mas o **default** do componente `Button` continua desalinhado do `Input`.
2. **`FiltrosDeInscricao.vue` e `DialogoDeAcao.vue` usam `<input>`/`<select>`/`<button>` nativos estilizados manualmente**, em vez dos componentes `ui/Input`, `ui/Select`, `ui/Button` — duplicam classes (`h-10 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring`) em vez de reutilizar o componente. Funciona e é acessível, mas é um padrão paralelo ao dos componentes de biblioteca — se o token de foco ou borda mudar, esses arquivos não acompanham automaticamente.
3. **Sem uso de `Skeleton` em nenhum componente de produto** — o componente existe em `ui/skeleton` mas não aparece referenciado fora de si mesmo. Onde há carregamento assíncrono (listagens admin, painel), não há evidência de skeleton nem de outro indicador de carregamento no código lido.
4. **Toque leve com `min-h-11` só nas telas públicas mais recentes** (`Home.vue`, alguns pontos de `Criar.vue`) — não é um padrão aplicado uniformemente em todo componente tocável do fluxo público; vale conferir se todos os elementos interativos do formulário de inscrição mantêm 44px.

Nenhuma dessas é atribuída com certeza às migrações Tailwind 3→4 ou radix-vue→reka-ui — nenhum resíduo visual da migração (classes `--radix-*`, `[data-radix-*]`, ou `v-model:checked` antigo) foi encontrado; a migração documentada em `docs/ARCHITECTURE.md` §14.2 parece ter sido bem-sucedida e completa.

## Relatório de Conformidade com Boas Práticas

### Regras P0 obrigatórias

| Regra | Status | Detalhes |
|---|:---:|---|
| 1.1 Modo claro/escuro | ✅ | `.dark`/`:root` completos no `app.css`; `useAppearance.ts` usa `prefers-color-scheme` como padrão e persiste escolha manual |
| 1.2 Tokens semânticos | ✅ | Nenhum hex hardcoded fora de `components/ui/` (0 ocorrências); toda cor de produto vem de token (`bg-primary`, `bg-acao`, `text-destructive` etc.) |
| 1.3 Cores reservadas | ✅ | Verde/laranja/vermelho usados só como `sucesso`/`atencao`/`destructive`; paleta de gráfico (`chart-*`) separada e ainda não usada em nenhuma tela |
| 2.2 Tamanho mínimo de fonte | ✅ | Nenhuma ocorrência de `text-[Npx]` abaixo de 12px; menor classe observada é `text-xs` |
| 2.3 Escala progressiva | ✅ | Home.vue e páginas amostradas seguem `text-2xl`→`text-xl`→`text-base`→`text-sm` sem saltos |
| 3.1 Grid de 4px | ⚠️ | Apenas 1 ocorrência de largura arbitrária fora do sistema (`w-[300px]` no `SheetContent` do `AppHeader.vue`) — não é espaçamento (padding/margin/gap), é largura de painel; aceitável, mas fora da escala nominal |
| 3.5 Paridade altura input/botão | ⚠️ | Ver Inconsistência 1 — `Button` default (`h-9`) e `Input` (`h-10`) não coincidem no componente-base; mitigado localmente com overrides |
| 4.1 Hierarquia de botões | ✅ | `buttonVariants` mapeia default/destructive/outline/secondary/ghost/link corretamente; `DialogoDeAcao` usa hierarquia visual clara entre "Voltar" (outline) e ação (preenchido) |
| 7.7 Estados vazios | ✅ | Encontradas mensagens de vazio em Cidades, GruposParticipantes, Eventos/Index, Inscrições/Index, Auditoria/Index — todas com frase explicativa (algumas com CTA, ex.: "Comece por 'Novo evento'") |
| 8.1 Confirmar antes de excluir | ✅ | `DialogoDeAcao.vue` exige justificativa textual antes de ações administrativas destrutivas (cancelar inscrição, registrar pagamento fora do fluxo); telas de catálogo (Cidades, Grupos) têm fluxo de exclusão com diálogo |
| 12.1 Validação em tempo real | ⚠️ | Não verificado em profundidade — padrão do projeto é `useForm` do Inertia com erros vindos do back-end no submit; não foi encontrada validação client-side em blur/input nos arquivos amostrados |
| 12.3 Erros de backend | ✅ | `form.errors` do Inertia é exibido nas telas de formulário; `DialogoDeAcao` expõe `props.erro` com `role="alert"` |
| 13.2 Paginação de listas longas | ✅ | `Admin/Inscricoes/Index.vue` e `Admin/Auditoria/Index.vue` usam paginação anterior/próxima vinda do Laravel |
| 14.1 Reduced motion | ❌ | Nenhuma ocorrência de `motion-reduce:`/`prefers-reduced-motion` em `app.css` nem em componentes — as 36 ocorrências de `animate-`/`transition-` (majoritariamente em `ui/dialog`, `ui/dropdown-menu`, `ui/sheet`, `ui/tooltip`, via reka-ui `data-[state]`) não têm alternativa reduzida |
| 14.3 Indicadores de foco | ✅ | Nenhum `outline-none`/`outline-hidden` sem `focus-visible:ring` pareado; padrão `focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring` consistente em toda a base |
| 14.4 Alvo mínimo de toque | ⚠️ | Presente e bem aplicado no fluxo mais crítico (Home pública usa `h-12`/`min-h-11`), mas não confirmado uniformemente em todos os elementos tocáveis do restante do fluxo público — ver Inconsistência 4 |

### Regras P1 — padrões fortes

| Regra | Status | Detalhes |
|---|:---:|---|
| 1.4 Ícones vetoriais | ✅ | `lucide-vue-next` único em uso; nenhum emoji encontrado como ícone funcional |
| 1.5 Estilo plano | ✅ | Sem gradientes em botões/fundos; superfícies sólidas |
| 1.6 Raio de borda consistente | ✅ | `--radius` único, derivado em `sm/md/lg/xl` via `@theme inline`; nenhum raio arbitrário fora do sistema |
| 1.7 Sistema de sombra | ✅ | Só `--sombra-*`/`--shadow-*` do tema; 0 ocorrências de `shadow-[...]` arbitrário |
| 13.3 Skeleton vs. spinner | ❌ | Componente `Skeleton` existe mas não é usado em nenhuma tela/lista de produto encontrada — ver Inconsistência 3 |
| 14.5 Escala de z-index | ⚠️ | Uso é majoritariamente de valores fixos do Tailwind (`z-10`, `z-20`, `z-50`) sem uma escala nomeada própria do projeto (o `ui-theme-reference` sugere tokens `--z-dropdown`, `--z-modal` etc.); há 2 ocorrências de valor arbitrário (`z-[100]` no `ToastViewport`, `z-[1]` no `NavigationMenuIndicator`) — ambas dentro de `ui/`, herdadas do gerador shadcn, não do código de produto |

### Violações que merecem atenção

| Prioridade | Arquivo:linha | Regra | Descrição | Sugestão |
|---|---|---|---|---|
| P0 | `resources/js/components/ui/button/index.ts:17` | 3.5 | `size: default` do Button é `h-9` (36px), enquanto `Input` é `h-10` (40px) — o par botão+campo lado a lado não fica alinhado por padrão | Alinhar `h-9`→`h-10` no `default`, ou documentar explicitamente que telas devem usar `size="lg"` (`h-10`) quando ao lado de inputs |
| P0 | toda a base (`ui/dialog`, `ui/dropdown-menu`, `ui/sheet`, `ui/tooltip`) | 14.1 | Nenhuma variante de `prefers-reduced-motion: reduce` nas animações de entrada/saída (`animate-in`/`animate-out`, `data-[state]`) | Adicionar o bloco `@media (prefers-reduced-motion: reduce)` do `ui-theme-reference` ao `app.css` |
| P1 | `resources/js/components/admin/FiltrosDeInscricao.vue`, `resources/js/components/admin/DialogoDeAcao.vue` | — (padrão de reuso, não regra numerada) | `<input>`/`<select>`/`<button>` nativos replicam manualmente as classes do design system em vez de reusar `ui/Input`, `ui/Select`, `ui/Button` | Ao tocar nesses arquivos novamente, considerar migrar para os componentes de biblioteca — não é urgente, hoje está funcional e acessível |
| P1 | listagens admin (Inscrições, Eventos, Cidades, Grupos, Auditoria) | 13.3 | Nenhum uso de `Skeleton` durante carregamento — Inertia geralmente navega com a página já montada, mas ações assíncronas dentro da página (filtros, ações em lote) não têm placeholder visual confirmado | Avaliar se há estado de carregamento sem feedback visual ao aplicar filtros/paginação |

### Resumo
- **Conformidade P0**: 12/14 regras aplicáveis plenamente atendidas; 2 com ressalva (⚠️ 3.1 e 3.5, ⚠️ 12.1, ⚠️ 14.4) e 1 não atendida (❌ 14.1 reduced motion).
- **Conformidade P1**: 4/6 plenamente atendidas; 1 não atendida (❌ 13.3 skeleton) e 1 com ressalva (⚠️ 14.5 z-index sem escala nomeada).
- **Top 3 áreas de melhoria**:
  1. Suporte a `prefers-reduced-motion` ausente em toda a base (P0, afeta todos os componentes com animação de entrada/saída do reka-ui).
  2. Paridade de altura Button/Input no componente-base (P0, hoje mitigada só por overrides pontuais).
  3. Ausência de `Skeleton` em listagens administrativas (P1, componente já existe e está pronto para uso).

## Recomendações para novos componentes

1. **Reutilizar os tokens semânticos em português** (`bg-acao`, `text-sucesso-texto`, `border-atencao/60`, etc.) em vez de `bg-primary`/`bg-success` quando o componente expressa conceito do domínio (inscrição, pagamento, status) — é o vocabulário estabelecido nas 187 telas existentes.
2. **Seguir o padrão `useForm` do Inertia** para qualquer formulário novo — não introduzir vee-validate/Zod, que não existem no projeto.
3. **Sempre usar `cn()`** para composição de classes condicionais; nunca template literal puro.
4. **Em telas públicas, manter `h-12`/`min-h-11`** em todo alvo tocável — é o padrão já estabelecido em `Home.vue` e deve se propagar ao resto do fluxo de inscrição.
5. **Em telas administrativas, preferir os componentes de `ui/`** (`Input`, `Select`, `Button`) a marcação nativa estilizada manualmente, mesmo que o padrão atual em `FiltrosDeInscricao`/`DialogoDeAcao` funcione — reduz o risco de divergência quando o tema mudar.
6. **Ações destrutivas administrativas devem seguir o modelo do `DialogoDeAcao.vue`**: diálogo com justificativa obrigatória, devolução de foco ao elemento de origem, `aria-describedby` ligando campo/ajuda/erro.
7. **Ao adicionar animação, incluir o par `motion-reduce`** ou aguardar a correção centralizada no `app.css` (ver violação P0 acima) antes de multiplicar o padrão sem essa proteção.
8. **Não rodar o gerador shadcn sobre os 23 componentes existentes** — só para componentes novos, conforme `docs/ARCHITECTURE.md` §14.2 (DA-44).

## Gate de aprovação
- [x] Stack verificada
- [x] Tokens extraídos
- [x] Componentes inventariados
- [x] Padrões de código identificados
- [x] Convenções documentadas
- [x] Conformidade com boas práticas verificada
