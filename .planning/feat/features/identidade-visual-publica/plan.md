# Action Plan — Identidade visual do lado público (plano 1 de 3)

> **Type:** feature
> **Created:** 2026-08-28 16:20
> **Status:** pending

---

## Contexto

`ccc-redesign.html` (1000 linhas, na raiz do projeto) é um protótipo funcional do lado
público inteiro: agenda, evento, formulário em quatro passos, confirmação e "minha
inscrição". Ele não é um ajuste de layout como a proposta anterior — é **outra identidade
visual**: verde-mata sobre papel, três famílias tipográficas, botões pílula e um
elemento-assinatura de trilha pontilhada ligando os dias do evento.

O trabalho foi dividido em **três planos encadeados**, por decisão do dono do produto:

1. **Este plano — o tema e as peças de base.** Nenhuma tela muda de conteúdo.
2. Agenda e evento (mais os campos novos: local, "o que está incluído", perguntas
   frequentes, com as telas de cadastro no admin).
3. Formulário, pagamento, confirmação e "minha inscrição".

Cada plano fecha com a suíte verde. Um cenário vermelho aponta para um plano só — que é
a razão de eles serem três, e não um.

### Decisões já tomadas pelo dono do produto (nesta entrevista)

| # | Decisão |
|---|---|
| 1 | **Adotar a identidade inteira** — paleta, tipografia e formas. Não é "aproveitar a estrutura" como foi na DA-46 |
| 2 | **Só o lado público fica verde.** O admin continua no tema azul do studio |
| 3 | Os campos que não existem (local, incluídos, FAQ) **serão criados, com telas de cadastro no admin** — reverte o "não alterar o admin" do pedido original. **Fica para o plano 2** |
| 4 | "Minha inscrição" **continua pedindo e-mail**, não CPF. Só o visual muda. **Fica para o plano 3** |

---

## 1. Persona & Scope

**Persona:** Desenvolvedora frontend sênior em Vue 3.5 + TypeScript estrito + Tailwind CSS
4 + Reka UI 2, com prática em sistema de design multi-tema e em acessibilidade WCAG 2.1
AA — em particular no cálculo de razão de contraste.

**Scope:** Os **tokens, a tipografia e as formas** da identidade nova, escopados ao lado
público, mais as variantes de componente que ela exige (botão, etiqueta) e o
elemento-assinatura da trilha. **Nenhuma tela muda de conteúdo, de estrutura ou de texto
nesta etapa** — o que muda é como o que já existe se parece.

**Fora do escopo, e vai para os planos 2 e 3:** a agenda, a tela do evento, o formulário,
o pagamento, a confirmação, "minha inscrição", os campos novos e as telas de cadastro.

**Stack:** Vue 3.5.13 · Reka UI 2.10.4 · Tailwind CSS 4.3.3 · Inertia 2 · Laravel 12 /
PHP 8.4 · Playwright 1.62 · Pest.

## 2. Direct Objective

Fazer as seis telas públicas que já existem aparecerem com a identidade do
`ccc-redesign.html` — verde-mata sobre papel, Bricolage Grotesque nos títulos, DM Mono
nos números, botões pílula — **sem que uma única tela administrativa mude de cor**, e sem
que nenhum tom reprove em contraste AA.

## 3. Minimum Inputs

### A paleta do protótipo, e o que cada tom faz

Do bloco `:root` de `ccc-redesign.html` (linhas 11-29):

| Token do protótipo | Valor | Papel |
|---|---|---|
| `--papel` | `#F1F3EE` | fundo da página |
| `--branco` | `#FFFFFF` | cartões |
| `--tinta` | `#10231C` | texto principal |
| `--tinta2` | `#5B6C64` | texto secundário |
| `--mata` | `#0F6B4E` | ação, links, marca |
| `--mata-esc` | `#0A4E39` | ação sob o cursor |
| `--mata-tint` | `#E3EFE9` | fundo de etiqueta "aberta" |
| `--sol` | `#E9922B` | atenção |
| `--sol-tint` | `#FBEDDA` | fundo de etiqueta de prazo |
| `--linha` / `--linha-forte` | `#DEE3DB` / `#C7D0C6` | bordas |
| `--erro` / `--erro-tint` | `#A93425` / `#FBEAE7` | erro |

**Cada um destes tem de ser medido antes de entrar** (§5). Dois já dão para prever que vão
dar trabalho: `--sol` (`#E9922B`) como **texto** sobre papel rende cerca de 2,3:1 e
**reprova** — é o mesmo caso do âmbar que a DA-42 já resolveu escurecendo o papel de
texto; e `--tinta2` (`#5B6C64`) sobre `--papel` fica perto do limite de 4,5:1 e precisa do
número conferido, não estimado.

### As quatro semânticas do projeto continuam existindo

O projeto usa `acao`, `sucesso`, `informacao` e `atencao` em dezenas de telas para dizer
coisas diferentes (**DA-41**). A identidade nova tem verde, âmbar e vermelho, mas **não
tem quatro papéis** — ela usa o mesmo verde para ação e para sucesso. O mapeamento tem de
ser decidido e escrito, não improvisado componente a componente:

- `acao` → `--mata`
- `sucesso` → `--mata`, com o **fundo** `--mata-tint` distinguindo "disponível" de "clique aqui"
- `atencao` → `--sol`, com papel de texto escurecido até passar
- `informacao` → **não existe na paleta**. Precisa ser derivada (ver §6)

### As três armadilhas — todas conferidas neste projeto, não supostas

**1. Os portais saem da subárvore.** `SelectContent.vue:32` usa `SelectPortal` e
`DialogContent.vue:28` usa `DialogPortal`: os dois teleportam para `document.body`. Um
escopo posto no `PublicoLayout` **não alcança a lista de cidades nem os diálogos** — eles
sairiam com as cores do admin no meio de uma tela verde. Por isso o escopo tem de viver no
`<html>`, e não na subárvore da página.

**2. O Inertia não recarrega o `<html>`.** Pôr o atributo só no `app.blade.php` acerta a
primeira pintura e erra toda navegação seguinte, porque o Inertia troca só o corpo. Pôr só
no cliente acerta a navegação e **pisca** o tema errado na primeira pintura. Precisa dos
dois: servidor para o primeiro paint, cliente a cada troca de página.

**3. A CSP libera `fonts.bunny.net`, e só ela.**
`CabecalhosDeSeguranca.php:111` e `:131` listam `https://fonts.bunny.net` em `style-src` e
`font-src`. O protótipo pede Google Fonts. **Trocar a origem exigiria mexer na CSP e nos
cenários que a provam** — e o bunny.net serve as três famílias. `Instrument Sans` **já é a
fonte do projeto** (`app.blade.php`); faltam Bricolage Grotesque e DM Mono.

### Arquivos que a executora precisa ler antes de começar

- `ccc-redesign.html` — linhas 10-46 (tokens, tipografia) e 60-90 (botões e etiquetas)
- `resources/css/app.css` — onde vivem os tokens de hoje e o `@theme inline`
- `resources/views/app.blade.php` — a fonte e o `<html>`
- `resources/js/app.ts` e `resources/js/composables/useAppearance.ts` — onde `.dark` é aplicado
- `resources/js/components/ui/button/index.ts` e `.../badge/index.ts` — as variantes de hoje
- `resources/js/components/ui/select/SelectContent.vue` e `.../dialog/DialogContent.vue` — os portais
- `app/Http/Middleware/CabecalhosDeSeguranca.php` — a CSP
- `resources/js/layouts/PublicoLayout.vue` e `.../AdminLayout.vue` — as duas molduras

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `resources/css/app.css` | modify | Bloco novo com os tokens da identidade, escopado em `[data-tema='publico']`, com a razão de contraste escrita ao lado de cada tom |
| `resources/views/app.blade.php` | modify | `data-tema` no `<html>` para a primeira pintura; as duas famílias novas no bunny.net |
| `resources/js/app.ts` | modify | Atualiza `data-tema` a cada troca de página do Inertia |
| `resources/js/components/ui/button/index.ts` | modify | Forma pílula e altura mínima de 48px **dentro do escopo público**, sem tocar no botão do admin |
| `resources/js/components/ui/badge/index.ts` | modify | As quatro etiquetas do protótipo (`open`, `soon`, `warn`, `done`) mapeadas nas variantes que já existem |
| `resources/js/components/publico/TrilhaDeDias.vue` | create | O elemento-assinatura: a linha pontilhada com pontos que liga os dias |
| `tests/Feature/Interface/TemaPublicoTest.php` | create | Prova que todo tom declarado passa em AA e que o admin não perdeu nenhum token |
| `tests/e2e/identidade-publica.spec.ts` | create | Prova em navegador: público verde, admin azul, e o portal da lista de cidades **dentro** do tema certo |
| `docs/ARCHITECTURE.md` | modify | Seção sobre os dois temas: o mecanismo, e por que o escopo mora no `<html>` |
| `docs/PROGRESS.md` | modify | As decisões desta etapa |

## 5. Quality Criteria

- [ ] **Toda cor declarada tem razão de contraste calculada e escrita ao lado**, como já é
      regra desde a Fase 5a. Nenhum tom entra "porque estava no arquivo"
- [ ] Texto normal ≥ **4,5:1**; texto grande e componentes ≥ **3:1**. O tom que reprovar é
      escurecido até passar, e o valor original fica registrado no comentário
- [ ] **Nenhuma tela administrativa muda de cor.** Provado com captura antes/depois de
      `/admin/painel` e `/admin/inscricoes`
- [ ] O portal do `Select` aberto numa tela pública aparece **com o tema público** —
      é o cenário que denuncia o escopo posto no lugar errado
- [ ] Não há piscada de tema: a primeira pintura já sai no tema certo (o atributo vem do
      servidor)
- [ ] Navegar de uma tela pública para outra pelo Inertia **mantém** o tema
- [ ] A CSP **não muda**: as fontes continuam vindo de `fonts.bunny.net`. Se alguma família
      não existir lá, ela é auto-hospedada — a origem nova está proibida (§7)
- [ ] `npx vue-tsc --noEmit` com zero erros; `npm run lint` e `npx prettier --check` limpos
      nos arquivos tocados
- [ ] `./vendor/bin/pest` inteiro passa, com o teste novo
- [ ] Os **47 cenários** de navegador continuam passando, sem nenhum editado
- [ ] Playwright E2E — cenários novos:
    - [ ] a home sai com fundo papel e o botão principal verde-mata
    - [ ] `/admin/painel` continua com o fundo e o azul de hoje
    - [ ] a lista de cidades do formulário, que é portal, sai no tema público
    - [ ] em 320px de largura nada escapa da tela e todo alvo de toque tem ≥ 44px

## 6. Ambiguity Handling

**Assumptions made:**

- **O escopo vive no `<html>`, e é escrito nos dois lados** (Blade para a primeira pintura,
  `app.ts` a cada troca de página do Inertia). É a única forma que alcança os portais e não
  pisca. A alternativa — escopo no `PublicoLayout` — foi descartada com prova: os dois
  portais estão nas linhas citadas na §3.
- **`informacao` é derivada, e não emprestada do verde.** A identidade não tem um quarto
  papel, mas o projeto usa `informacao` para navegação e aviso neutro em dezenas de
  lugares. Usar o mesmo verde de ação faria "clique aqui" e "isto é um aviso" ficarem
  idênticos. Deriva-se um tom frio da mesma família, com contraste conferido.
- **O modo escuro continua existindo no lado público.** `useAppearance.ts` aplica `.dark`
  no `<html>` e as telas públicas herdam isso hoje. O protótipo é só claro, mas tirar o
  escuro seria regressão para quem o usa por necessidade — e vai contra a linha da Etapa
  23, que passou a respeitar "reduzir movimento". A variante escura é **derivada** da
  identidade nova, com contraste conferido. **Se o dono do produto preferir o lado público
  sempre claro, isto é uma linha para reverter** — e a decisão é dele.
- **A altura mínima de 48px do botão do protótipo vale só no público.** No admin o botão
  continua como está: mudar altura de botão lá dentro mexeria em 40 telas que este plano
  jurou não tocar.

**If unsure during execution:**

- Se um tom da paleta reprovar em AA, **escureça-o e registre os dois valores** — o do
  arquivo e o usado. Nunca use o tom original "porque é o do designer": a DA-42 já resolveu
  esse conflito uma vez, e a resposta foi acessibilidade.
- Se uma família tipográfica não existir no `fonts.bunny.net`, **auto-hospede o arquivo**
  em `public/fonts/` e sirva-o de `'self'`. Não acrescente origem à CSP.
- Se alguma tela administrativa mudar de aparência, **pare**: é sinal de que o escopo
  vazou, e é o defeito que este plano mais precisa evitar.

## 7. Prohibitions

- ❌ Nunca alterar a **aparência** de nenhuma tela administrativa. O admin ganha telas de
  cadastro no **plano 2**; de cor ele não muda
- ❌ Nunca mudar conteúdo, estrutura, texto ou fluxo de nenhuma tela nesta etapa — isto é
  troca de tema, e se uma tela mudar de conteúdo é defeito
- ❌ Nunca acrescentar origem à CSP (`fonts.googleapis.com`, `fonts.gstatic.com` ou
  qualquer outra). A política atual é provada em navegador por `seguranca-csp.spec.ts`
- ❌ Nunca editar nenhum dos 47 cenários que já existem
- ❌ Nunca duplicar componente de interface para ter "a versão verde": um componente, dois
  temas, escopo no CSS
- ❌ Nunca silenciar erro de `vue-tsc` com `any`, `as unknown as` ou `@ts-ignore`
- ❌ Nunca rodar o gerador do shadcn-vue (**DA-44**)
- ❌ Nunca introduzir a sintaxe `classe-[--variavel]` do Tailwind 3 (**D-86**): a varredura
  de CSS falha e está certa em falhar

---

## Execution Steps

1. **Ler e medir.** Ler os arquivos da §3 e calcular a razão de contraste de **cada par**
   da paleta do protótipo contra os dois fundos (`--papel` e `--branco`). Escrever a tabela
   com os números **antes** de tocar em qualquer arquivo: é ela que diz quais tons entram
   como estão e quais precisam escurecer.

2. **Montar o mecanismo de escopo e prová-lo vazio.** `data-tema` no `<html>` pelo Blade e
   pelo `app.ts`, com o bloco `[data-tema='publico']` ainda **sem nenhuma cor nova dentro**.
   Conferir no navegador que o atributo está certo em tela pública, em tela administrativa
   e depois de navegar entre páginas pelo Inertia. Provar o mecanismo antes de confiar
   nele.

3. **Os tokens da identidade**, com a razão de contraste ao lado de cada um e o mapeamento
   das quatro semânticas da §3. Tom que reprovou entra escurecido, com os dois valores
   registrados.

4. **As fontes.** Bricolage Grotesque e DM Mono pelo `fonts.bunny.net`, ao lado da
   Instrument Sans que já existe. Conferir que a CSP não precisou mudar e que a suíte, que
   corta rede externa, continua passando com a fonte do sistema.

5. **As formas e as variantes de componente.** Raio pílula e altura de 48px no botão,
   raio de 14px em cartões, e as quatro etiquetas do protótipo mapeadas nas variantes que
   já existem — tudo dentro do escopo, sem tocar no admin.

6. **O elemento-assinatura**, em `TrilhaDeDias.vue`: a linha pontilhada com pontos. Ele é
   decoração e por isso sai do leitor de tela — quem lê por áudio já recebe a ordem pela
   lista.

7. **A variante escura**, derivada, com contraste conferido contra os dois fundos escuros.

8. **As duas provas:** o teste de contraste no Pest e o cenário de navegador com as quatro
   asserções da §5 — incluindo a do portal, que é a que denuncia o escopo no lugar errado.

9. **Rodar tudo:** `vue-tsc`, lint, prettier, `pest` e `npm run test:e2e` inteiro. Conferir
   com os próprios olhos as seis telas públicas e **duas administrativas**, nos dois
   tamanhos. A suíte de navegador usa o banco `testing` — não rodar junto com o Pest.

10. **Registrar** as decisões em `docs/PROGRESS.md` e o mecanismo dos dois temas em
    `docs/ARCHITECTURE.md`. Commit único.

## Done

As seis telas públicas aparecem com a identidade do protótipo — papel, verde-mata,
Bricolage Grotesque, botões pílula —, nenhuma tela administrativa mudou de cor, todo tom
declarado tem contraste AA com o número escrito ao lado, a lista de cidades aberta numa
tela pública sai no tema público, e as duas provas automatizadas ficam vermelhas se
qualquer uma dessas coisas deixar de valer.

## Commit

`feat(publico): adotar a identidade visual verde no lado publico`

---

## Depois deste plano

- **Plano 2 — agenda e evento:** as duas telas de apresentação no desenho novo, mais os
  campos de local, "o que está incluído" e perguntas frequentes, com as telas de cadastro
  no admin (decisão 3 da entrevista).
- **Plano 3 — o caminho da inscrição:** formulário, pagamento, confirmação e "minha
  inscrição" (que continua pedindo e-mail, decisão 4).
