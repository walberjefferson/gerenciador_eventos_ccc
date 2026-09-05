# Execution Report — Etiquetas de situação e botões de ação coloridos nas listagens administrativas

> **Plan:** badges-de-situacao-e-acoes-coloridas
> **Executed:** 2026-09-01
> **Status:** ⚠️ WITH CAVEATS

Os dez passos do plano foram executados. As ressalvas são três, todas verificadas e
nenhuma delas causada por esta entrega: `npm run format:check` já estava vermelho no
HEAD por 30 arquivos fora do escopo; a suíte E2E já tinha 4 cenários vermelhos no HEAD;
e a suíte só roda nesta máquina depois de tirar do caminho o `public/hot` do servidor de
desenvolvimento do Vite. Detalhe de cada uma abaixo.

---

## O que foi feito, passo a passo

### 1. Tokens (`resources/css/app.css`)
Nasceram `--cor-informacao-suave` / `-contraste` e `--cor-erro-suave` / `-contraste` nos
**dois** blocos (`:root` e `[data-tema='publico']`), cada um com a razão de contraste
calculada em comentário no formato dos vizinhos, e os quatro expostos em `@theme inline`
como `--color-informacao-suave`, `--color-informacao-suave-foreground`,
`--color-erro-suave` e `--color-erro-suave-foreground`.

O nome é `erro-suave`, e não `destructive-suave`: a camada semântica do domínio fala
português (DA-41). Não existe `--cor-erro` cheia porque não precisa — quando o erro é
bloco de cor, quem responde continua sendo o `--destructive` do studio.

### 2. Variantes da etiqueta (`ui/badge/index.ts`)
Acrescentadas `sucessoSuave`, `informacaoSuave`, `atencaoSuave`, `erroSuave` e `neutra`.
As sete existentes não foram tocadas, e nenhuma classe `publico:` foi alterada. O
comentário do arquivo passou a explicar quando usar cheia (destaque isolado) e quando
usar suave (lista longa).

`neutra` usa `bg-muted text-foreground`, e **não** `text-muted-foreground`: o cinza sobre
o próprio `--muted` rende 4.39:1 e reprova — a medição já estava registrada no `app.css`.

### 3. Fonte única (`resources/js/lib/situacoes.ts`)
`varianteDaInscricao`, `varianteDoPagamento`, `varianteDoEvento`, `varianteDoWebhook`,
`varianteDeAtivo` e o despachante `varianteDoDominio`, todas devolvendo
`BadgeVariants['variant']` — nunca `string`. As duas justificativas que parecem
incoerência estão escritas no arquivo: inscrição cancelada é neutra e evento cancelado é
erro; `ignorado` continua neutro (comentário migrado da tela de avisos, como o plano
pedia).

### 4. Componentes novos
- `components/admin/EtiquetaDeSituacao.vue` — recebe `dominio`, `situacao` (valor bruto)
  e `rotulo`; rótulo nulo vira travessão em vez de pílula vazia.
- `components/admin/BotaoDeAcao.vue` — `<Link>` do Inertia quando recebe `href`,
  `<button>` quando não; ícone `aria-hidden`; anel de foco; `relative` na base (o
  comentário explica o defeito de layout no celular que ele corrige).

### 5–8. Aplicação nas telas
Inscrições (tabela e ficha), Eventos (lista e programação), Catálogo (setores e grupos),
Usuários, Avisos do provedor, Painel e os dois componentes do participante. O
`corDaSituacao`/`classeDaSituacao` da tela de avisos foi apagado e os `switch` locais do
`ResumoDaInscricao` e do `HistoricoDaCobranca` passaram a consumir o módulo central.

### 9. Testes
`contraste()` saiu de `home.spec.ts` para `tests/e2e/apoio.ts` (com o comentário
explicando por que ela mede no navegador), `home.spec.ts` passou a importá-la, e nasceu
`tests/e2e/admin-listagens-visuais.spec.ts` com os seis cenários da §5.

### 10. Verificação
Abaixo.

---

## Arquivos

| File | Action | Description |
|------|--------|-------------|
| `resources/css/app.css` | modify | 4 tokens novos nos 2 blocos + 4 linhas no `@theme inline` |
| `resources/js/components/ui/badge/index.ts` | modify | 5 variantes novas; as 7 antigas intactas |
| `resources/js/lib/situacoes.ts` | create | Mapeamento único, 5 domínios |
| `resources/js/components/admin/EtiquetaDeSituacao.vue` | create | Etiqueta de situação |
| `resources/js/components/admin/BotaoDeAcao.vue` | create | Botão de ação por intenção |
| `resources/js/components/admin/TabelaDeInscricoes.vue` | modify | Situação, Cobrança e "Abrir" |
| `resources/js/pages/Admin/Inscricoes/Show.vue` | modify | Situação da ficha e do histórico |
| `resources/js/pages/Admin/Eventos/Index.vue` | modify | Situação + 5 botões |
| `resources/js/pages/Admin/Eventos/Estrutura.vue` | modify | 3 tabelas de ativo + 7 botões |
| `resources/js/pages/Admin/Catalogo/Setores.vue` | modify | Situação + 4 botões |
| `resources/js/pages/Admin/Catalogo/GruposParticipantes.vue` | modify | Situação + 4 botões |
| `resources/js/pages/Admin/Usuarios/Index.vue` | modify | Pílula → etiqueta; 7 botões; `data-testid` mantidos |
| `resources/js/pages/Admin/Pagamentos/Avisos/Index.vue` | modify | `corDaSituacao` removido; etiqueta webhook |
| `resources/js/pages/Admin/Painel.vue` | modify | Situação do último aviso |
| `resources/js/components/participante/ResumoDaInscricao.vue` | modify | Consome `varianteDaInscricao` |
| `resources/js/components/participante/HistoricoDaCobranca.vue` | modify | Consome `varianteDoPagamento` |
| `tests/e2e/apoio.ts` | modify | `contraste()` extraída e exportada |
| `tests/e2e/home.spec.ts` | modify | Importa `contraste` de `apoio.ts` |
| `tests/e2e/admin-listagens-visuais.spec.ts` | create | 6 cenários novos |

---

## Razões de contraste dos tokens novos

Recalculadas pela fórmula da WCAG 2.1 **e conferidas contra o que está escrito no
comentário** — o script de conferência lê o `app.css`, extrai os hexadecimais e refaz a
conta, sem confiar no número escrito.

| Bloco | Token | Texto sobre fundo | Razão | Mínimo |
|---|---|---|---|---|
| `:root` | `--cor-informacao-suave` | `#00506F` sobre `#E2F0F8` | **7.59:1** | 4.5:1 |
| `:root` | `--cor-erro-suave` | `#9B1C1C` sobre `#FDEAEA` | **7.04:1** | 4.5:1 |
| `[data-tema='publico']` | `--cor-informacao-suave` | `#0A4A60` sobre `#E2ECF0` | **8.09:1** | 4.5:1 |
| `[data-tema='publico']` | `--cor-erro-suave` | `#8C2A1E` sobre `#F7E6E3` | **7.05:1** | 4.5:1 |

A variante `neutra` não criou token: usa `--muted` com `--foreground`, que rende
**18.10:1** no painel e **13.51:1** no tema público.

### Botão de ação, texto sobre o branco da tabela

| Intenção | Cor do texto | Razão | No estado de passagem do ponteiro |
|---|---|---|---|
| `ver` | `#0069A8` | 5.86:1 | 7.59:1 (texto muda para o par do token suave) |
| `editar` | `#155DFC` | 5.25:1 | 4.77:1 (sobre `secondary`) |
| `excluir` | `#E7000B` | 4.77:1 | 7.04:1 (texto muda para o par do token suave) |
| `neutra` | `#09090B` | 19.90:1 | 18.10:1 (sobre `muted`) |

O `excluir` foi o caso que obrigou a pensar: `text-destructive` sobre `bg-erro-suave`
renderia 4.12:1 e **reprovaria**. Por isso o estado de passagem do ponteiro troca também
o texto, e não só o fundo.

---

## Critérios de qualidade

| Critério | Status | Evidência real |
|---|---|---|
| Nenhum mapa de cor de situação fora dos lugares centrais | ✅ | `grep -rn "corDaSituacao\|classeDaSituacao" resources/js/` → nenhuma ocorrência |
| Nenhuma classe de botão solta nas listagens | ✅ | `grep` por `rounded-md border border-border px-3 py-1` (e a ordem que o Prettier gera) → só o comentário dentro do `BotaoDeAcao.vue` que cita a string antiga |
| Razão escrita em comentário em cada token novo | ✅ | Tabela acima; script recalcula e bate nos 4 |
| Toda etiqueta continua com a palavra escrita | ✅ | Cenário 1 do spec novo compara os textos das etiquetas com `['Aguardando pagamento', 'Confirmada']` |
| Todo botão de ação com alvo de toque e anel de foco | ✅ | Cenários 4, 5 e 6 medem altura e anel no navegador |
| TypeScript strict, sem `any`, `varianteDa*` devolve `BadgeVariants['variant']` | ✅ | `npx vue-tsc --noEmit -p tsconfig.json` → sem saída |
| `npm run lint` limpo | ✅ | exit 0, sem diagnóstico |
| `npm run build` sem aviso novo | ✅ | `✓ built in 1.94s`, zero linhas de `error`/`warning` |
| Nenhum `data-testid` removido ou renomeado | ✅ | Script comparou `git show HEAD:<arquivo>` com o arquivo atual nos 11 arquivos alterados → nenhum perdido |
| Os 6 cenários novos passam | ✅ | Todos verdes (saída abaixo) |
| `npm run format:check` limpo | ⚠️ | Ver ressalva 1 |
| Suíte E2E inteira verde | ⚠️ | Ver ressalvas 2 e 3 |

---

## Verificação

| Comando | Resultado |
|---|---|
| `npx vue-tsc --noEmit -p tsconfig.json` | Sem saída — TypeScript strict limpo |
| `npm run lint` | exit 0, sem diagnóstico |
| `npm run format:check` | 24 arquivos com aviso — **todos fora desta entrega** (eram 30 no HEAD limpo) |
| `npx prettier --check <os 19 arquivos desta entrega>` | `All matched files use Prettier code style!` |
| `npm run build` | `✓ built in 1.94s`, nenhum erro ou aviso |
| `php artisan test --filter=TemaPublico` | **7 passed (103 assertions)** — a paridade de tokens e as razões recalculadas continuam válidas |
| `npm run test:e2e` (HEAD limpo, linha de base) | **71 passed, 4 failed** |
| `npm run test:e2e` (com esta entrega) | **77 passed, 4 failed** — as **mesmas** 4 da linha de base |

77 = 71 da linha de base + os 6 cenários novos. **Esta entrega não deixou nenhum cenário
vermelho que já não estivesse vermelho.**

### Os 6 cenários novos

```
✓ 27 admin-listagens-visuais.spec.ts:231 › a situacao e a cobranca chegam como etiqueta, com a palavra escrita (695ms)
✓ 28 admin-listagens-visuais.spec.ts:254 › duas situacoes diferentes rendem etiquetas de cores diferentes (680ms)
✓ 29 admin-listagens-visuais.spec.ts:269 › toda etiqueta da lista de inscricoes passa em 4.5:1, medida no navegador (663ms)
✓ 30 admin-listagens-visuais.spec.ts:289 › o botao de acao da linha tem contraste e alvo de dedo na lista de inscricoes (682ms)
✓ 31 admin-listagens-visuais.spec.ts:310 › os tres botoes da lista de eventos tem contraste e a altura do aparelho (545ms)
✓ 32 admin-listagens-visuais.spec.ts:349 › o caminho de exclusao de um evento e percorrivel so pelo teclado, com foco a vista (700ms)
```

---

## Ressalvas

### 1. `npm run format:check` não fecha limpo, e já não fechava antes

Medido no HEAD `1a5ecb5`, com esta entrega inteiramente guardada: **30 arquivos**
reprovavam. Depois desta entrega são **24** — os 6 que sobraram do bolo eram arquivos
desta entrega, e foram corrigidos de passagem. Os 24 restantes (`Dashboard.vue`,
`Home.vue`, `AppHeader.vue`, `AdminLayout.vue`, …) são desvio de ordenação de classe do
`prettier-plugin-tailwindcss` acumulado antes.

**Não foram formatados de propósito.** `npm run format` escreve em `resources/` inteiro, e
o plano manda commitar só os arquivos da §4. Formatá-los levaria 24 arquivos alheios para
dentro deste commit e esconderia a mudança real no meio do ruído. Fica registrado como
dívida para uma entrega de higiene própria.

### 2. Quatro cenários E2E já estavam vermelhos no HEAD

Verificado rodando a suíte com esta entrega guardada. São eles, com a causa:

| Cenário | Causa (pré-existente) |
|---|---|
| `admin-avisos-pagamento:106` | `getByRole('link', {name: 'Avisos do provedor'})` casa com 2 elementos: o item do menu e o "Ver os avisos do provedor" do painel. O seletor precisa de `exact: true`. |
| `admin-avisos-pagamento:133` | `not.toContainText('Processado')` reprova no **cabeçalho** da coluna "Processado em", não nos dados. |
| `admin-avisos-pagamento:154` | `aria-expanded` não vira `true` no clique. Reproduzido igual no HEAD limpo. |
| `admin-usuarios:110` | Espera 0 botões na própria linha, mas a linha tem o botão "Editar" — que é permitido de propósito, e o comentário no arquivo explica. O cenário ficou para trás da decisão. |

**Nenhum deles foi corrigido**: são defeitos de cenário fora do escopo desta entrega, e o
plano proíbe corrigir defeito pré-existente (só documentar).

### 3. A suíte E2E não roda nesta máquina sem tirar o `public/hot` do caminho

Na primeira execução **81 cenários falharam**, inclusive os que não tocam em nada desta
entrega. A causa não é código: existe um `public/hot` (git-ignored) apontando para
`http://localhost:5174`, escrito pelo servidor de desenvolvimento do Vite que está no ar
nesta máquina. Com ele presente, o `@vite` do Laravel serve os arquivos pelo servidor de
desenvolvimento em vez do manifesto construído — e o `tests/e2e/base.ts` aborta, de
propósito, todo pedido que não seja para o próprio servidor de teste. Resultado: a
aplicação nunca inicializa e todo cenário estoura o tempo.

Diagnóstico registrado no navegador:
`Connecting to 'ws://localhost:5174/?token=...' violates ... "connect-src 'self'"`.

O arquivo foi **movido para fora durante as execuções e devolvido ao lugar ao final**,
com o mesmo conteúdo (`http://localhost:5174`). O ambiente ficou como estava.

---

## Desvios do plano

### A. Tamanho responsivo do botão de ação — pedido do humano depois do plano

O plano fixava `min-h-11` (44px) para todo botão de ação. Durante a execução veio o
pedido de encolher o botão dentro da tabela. Foi implementado como uma prop `tamanho` no
`BotaoDeAcao`, com dois valores:

- `padrao` → `min-h-11 px-3 py-1` (44px sempre);
- `compacto` → `min-h-11 px-2.5 py-1 md:min-h-9 md:[&_svg]:size-3.5` — **44px no celular,
  36px do breakpoint `md` para cima**.

O compacto **não** é "um botão menor": ele é menor só onde quem aciona é um ponteiro. No
celular continua valendo o alvo de dedo que a WCAG e o projeto cobram, porque é lá que a
organização usa o painel entre uma coisa e outra. Os 36px não são número inventado — é a
altura do `size: default` do `Button` do projeto (`h-9`).

Os 29 botões de ação das 7 listagens usam `tamanho="compacto"`. O cenário de altura foi
ajustado junto: a expectativa acompanha a largura da janela (36px de `md` para cima, 44px
abaixo) em vez de afrouxar para 36px sempre — afrouxar desligaria justamente a proteção
que importa.

### B. Ícone de "desativar conta" não é uma lixeira

O plano mapeia `excluir` → `Trash2`. Em `Admin/Usuarios/Index.vue` a ação é **desativar**,
e o próprio docblock do arquivo diz que **não há botão de excluir**: a conta continua no
banco porque a auditoria aponta para ela. Lixeira ali mentiria sobre o que o botão faz.
Foram usados `UserMinus` (desativar, com a cor `excluir`, que é a certa: a pessoa perde o
acesso na hora) e `UserCheck` (reativar, neutro). Registrado em comentário no arquivo.

### C. Dois refinamentos além da lista da §4, ambos para atender critério da §5

- **`Usuarios/Index.vue`**: a pílula "você" (`bg-secondary … rounded-full px-2 py-0.5`)
  virou `<Badge variant="secondary">`. Mesmas cores, mesmo texto — some a última string de
  classe solta que o critério de busca da §5 pegava naquele arquivo.
- **`Avisos/Index.vue`**: o botão "Ver conteúdo do aviso" virou `BotaoDeAcao` neutro. É
  botão de ação de linha de listagem, e o critério 2 da §5 pede que nenhum deles monte
  classe solta. Os `aria-expanded`, `aria-controls` e `data-testid` continuam iguais.

### D. Mudança visual no `ResumoDaInscricao` (participante)

O plano dizia "sem mudança visual intencional", mas mandava consumir `varianteDaInscricao`.
As duas coisas não cabem juntas, e o mapeamento central venceu:

- `confirmada`: era `sucesso`. No tema público o `sucesso` **já** se pintava com os tokens
  suaves, então **não há diferença visível** ali.
- `aguardando_pagamento`: era `informacao` (azul cheio) e passou a `atencaoSuave` (âmbar
  lavado). É mudança real, e é a que o mapa central defende: esperar pagamento é o relógio
  correndo, não um aviso neutro.

### E. Defeito meu, encontrado e corrigido

A primeira versão do spec novo derrubava `conciliacao-por-txid.spec.ts`. Causa: inventei
o CPF `50520530020` para a Aurora, e ele **já era usado** por aquele cenário — a regra de
domínio recusa duas inscrições ativas com o mesmo CPF no mesmo evento
("Já existe uma inscrição ativa com este CPF neste evento."). Trocado por `50520531183`,
com dígitos verificadores calculados, e conferido que nenhum CPF se repete entre os
arquivos de cenário. Depois da correção a suíte voltou aos 4 vermelhos da linha de base.

---

## Situações que caíram no neutro por falta de mapa

**Nenhuma.** Os 21 valores dos quatro enums (`SituacaoInscricao` 5, `SituacaoPagamento` 6,
`SituacaoEvento` 6, `SituacaoWebhook` 4) estão mapeados um a um, mais o par booleano de
ativo/inativo. O `?? NEUTRA` existe como rede — para o dia em que um enum crescer sem que
a tela saiba — e nenhuma tela caiu nele durante a execução.

## Achados fora do escopo (documentados, não corrigidos)

1. **`resources/js/pages/Admin/Papeis/Index.vue`** ainda decide cor de estado com a pílula
   manual `bg-sucesso-suave … rounded-full px-2 py-0.5` / `bg-muted text-muted-foreground …`
   ("Alcança" / "Não alcança"). O arquivo **não está** na tabela §4 do plano. Vale notar
   que o ramo cinza usa `text-muted-foreground` sobre `bg-muted`, que rende **4.39:1 e
   reprova** — é o mesmo defeito que a variante `neutra` foi criada para evitar.
2. **`Admin/Pagamentos/Avisos/Index.vue`**, coluna "Assinatura": a pílula
   Válida/Inválida continua com classe inline. Não é situação de enum de domínio e o plano
   não a listou; o comentário de segurança que a explica foi preservado.
3. Os `bg-destructive/10` que sobraram são **faixas de alerta** (`role="alert"` do
   `erroDeExclusao`), não etiquetas de situação.

## Commit

- Mensagem: `feat(admin): etiquetas de situacao e botoes de acao coloridos nas listagens`
- Arquivos: os 19 da tabela §4 (16 modificados/criados de código + 3 de teste) mais
  `plan.md` e `plan.done.md`, seguindo o que o repositório já faz (`a2640a4` versiona o
  plano junto do código).
- **Fora do commit**, como pedido: `ccc-redesign.html` e
  `Prompt para Claude Code — Plataforma de Inscrições e Gestão de Eventos.md`.
