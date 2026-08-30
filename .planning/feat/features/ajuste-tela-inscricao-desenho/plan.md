# Action Plan — Ajustar a tela de inscrição ao desenho do protótipo

> **Type:** feature
> **Created:** 2026-08-30
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Pessoa desenvolvedora frontend sênior em Vue 3.5 (Composition API
com `<script setup>`) + TypeScript estrito + Inertia 2 + Tailwind CSS 4 +
Reka UI 2 (padrão shadcn-vue), com Playwright para prova em navegador. Escreve
comentário em português explicando o **porquê**, nunca o **o quê**.

**Escopo:** a tela `/eventos/{slug}/inscricao` (as três etapas que vivem no
`Criar.vue`), a tela de cobrança `/inscricoes/{codigo}/pagamento`, o resumo
lateral, e a navegação do `PublicoLayout`. Referência única de verdade: o
`ccc-redesign.html` na raiz — `step1()` (linhas 656–720), `resumo()`
(linhas 671–682), `viewInscricao()` (linhas 834–843) e o CSS
(linhas 227–277).

**Fora do escopo:** nenhuma regra de inscrição, de vaga, de conflito ou de
pagamento muda. `app/`, `routes/` e `database/` **não são tocados** — o
`EventoPublicoResource` já entrega `quando_rotulo` e `local`, que é tudo o que
o desenho pede a mais. Um `git diff` sobre esses três diretórios deve voltar
vazio ao final.

**Stack:** Vue 3.5.13 · reka-ui 2.10.4 · Tailwind CSS 4.3.3 · TypeScript ·
Inertia · Playwright · Pest.

## 2. Direct Objective

Fazer a tela de inscrição parar de ser "campos soltos sobre o papel" e virar o
**painel branco** do protótipo: formulário dentro de um cartão, grade única de
duas colunas com todos os campos alinhados, placeholder em cada campo, resumo
lateral abrindo pelo nome do evento com "17 e 18 de outubro · Sítio Santa
Clara", e as ações no rodapé do próprio painel. O campo de nascimento passa a
ser digitável com máscara `dd/mm/aaaa` e um botão que abre um calendário
próprio, e o cabeçalho do site ganha a navegação "Agenda / Minha inscrição".

## 3. Minimum Inputs

### Entidades / Dados

Nenhuma entidade nova. Nenhuma migration. O que a tela consome já existe:

| Campo | Origem | Já chega no `Criar.vue`? |
|---|---|---|
| `quando_rotulo` — "17 e 18 de outubro" | `EventoPublicoResource:54` | Sim |
| `local` — "Sítio Santa Clara" | `EventoPublicoResource:56` | Sim |
| `valor_centavos`, `moeda` | `EventoPublicoResource:66-67` | Sim |
| `slug` (volta para o evento) | `EventoPublicoResource:45` | Sim |

Os dois primeiros já estão declarados em `resources/js/types/evento.ts:58,61`.

### Regras de negócio

1. **`data_nascimento` continua trafegando em ISO (`AAAA-MM-DD`)** — é o que o
   `StoreInscricaoRequest` espera e o que `formatarDataCurta()` do `Criar.vue`
   consome. O novo campo mostra `dd/mm/aaaa` e emite ISO: o contrato do
   `v-model` não muda, e nada fora do componente precisa saber da troca.
2. **Data digitada precisa ser real.** Hoje o `<input type="date">` impedia
   `31/02/2000` por construção; com campo de texto isso deixa de ser verdade.
   O conferidor de `data_nascimento` no `Criar.vue` passa a recusar data
   inexistente e data no futuro, com frase em português. O servidor continua
   sendo quem decide.
3. **O limite `max = hoje`** que o `DateField` recebia hoje continua valendo,
   no calendário e na digitação.
4. **O placeholder do select de Grupo é condicional:** sem cidade escolhida,
   "Escolha a cidade primeiro"; com cidade, "Escolha o seu grupo". O estado
   desabilitado atual não muda.
5. **Erro tem precedência sobre nota**: o protótipo mostra `.f__e` **no lugar**
   de `.f__n`, não abaixo. Onde há erro, a nota de ajuda some.

### Arquivos a ler antes de começar

- `ccc-redesign.html` — linhas 227–277 (CSS de `.panel`, `.fields`, `.f`,
  `.actions`, `.summary`), 656–720 (`stepper`, `resumo`, `campo`, `step1`),
  834–843 (`viewInscricao`), 383–388 (`.top__nav`).
- `resources/js/pages/Inscricoes/Criar.vue`
- `resources/js/components/inscricao/PassoDadosPessoais.vue`
- `resources/js/components/inscricao/PassoParticipacao.vue`
- `resources/js/components/inscricao/PassoRevisao.vue` — **já** está no desenho
  (blocos `.rev`); serve de exemplo de como os valores do protótipo vêm sendo
  copiados, com o seletor de origem escrito no comentário.
- `resources/js/components/inscricao/ResumoDaInscricao.vue`
- `resources/js/components/ui/date-field/DateField.vue`
- `resources/js/layouts/PublicoLayout.vue`
- `resources/js/pages/Inscricoes/Pagamento.vue`
- `tests/e2e/apoio.ts` — linha 50 é a que quebra ao trocar o campo de data.
- `docs/ARCHITECTURE.md` §14 — as duas armadilhas do `components.json` e a
  decisão **DA-44** (o gerador do shadcn não é rodado; componentes entram
  adaptados à mão).

## 4. Output Format

| Arquivo | Ação | O quê |
|---|---|---|
| `resources/js/layouts/PublicoLayout.vue` | modificar | Navegação "Agenda" (`/`) e "Minha inscrição" (`/acesso`) no cabeçalho, no padrão `.top__link` |
| `resources/js/pages/Inscricoes/Criar.vue` | modificar | Crumb "← {nome do evento}", `<h1>Inscrição</h1>`, fim da linha "Valor da inscrição", painel envolvendo as etapas, ações no rodapé do painel, conferidor de data real |
| `resources/js/components/inscricao/PassoDadosPessoais.vue` | modificar | Grade única de 2 colunas, placeholders, textos do desenho, select de grupo condicional, campo de data novo |
| `resources/js/components/inscricao/PassoParticipacao.vue` | modificar | Conteúdo dentro do painel |
| `resources/js/components/inscricao/PassoRevisao.vue` | modificar | Blocos `.rev` dentro do painel (o interior não muda) |
| `resources/js/components/inscricao/ResumoDaInscricao.vue` | modificar | `.summary` do desenho; sai o título "Resumo" e o cartão "Precisa de ajuda?" |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modificar | Mesma moldura de painel das outras etapas |
| `resources/js/components/ui/popover/*` | criar | Popover do shadcn-vue sobre Reka UI, adaptado à mão (DA-44) |
| `resources/js/components/ui/calendar/*` | criar | Calendar do shadcn-vue sobre Reka UI, adaptado à mão (DA-44) |
| `resources/js/components/ui/date-field/DateField.vue` | modificar | Campo de texto com máscara `dd/mm/aaaa` + botão que abre o calendário; emite e recebe ISO |
| `package.json` | modificar | `@internationalized/date` (exigência do `CalendarRoot` do Reka UI) |
| `tests/e2e/apoio.ts` | modificar | Helper passa a preencher a data no formato da tela |
| `tests/e2e/validacao-do-formulario.spec.ts` | modificar | Dois `fill` de data (linhas 23 e 56) |
| `tests/e2e/inscricao-desenho.spec.ts` | criar | Cenários novos em 1280×800 e em celular |

## 5. Quality Criteria

### Fidelidade ao desenho — cada valor com o seletor de origem no comentário

- [ ] **Painel (`.panel`, linha 228):** fundo `bg-card`, borda 1px, raio 14px
      (`--r`), padding 28px, sombra `--sombra`. Título 23px (`.panel h2`) e
      subtítulo 15px em `text-muted-foreground` com 8px de topo (`.panel__n`).
- [ ] **Etapa 1 ganha o subtítulo que hoje não existe:** "Usamos só para
      organizar o encontro e enviar sua confirmação."
- [ ] **Grade (`.fields`, linha 231):** `grid-template-columns:1fr 1fr`,
      gap 18px, 26px de topo. **Uma grade só para os sete campos** — some o
      `sm:max-w-md` que hoje encolhe CPF e nascimento e desalinha a coluna
      (visível na imagem "antes"). Nome ocupa as duas colunas (`.f--full`).
      Em telas estreitas, uma coluna.
- [ ] **Campos:** os valores já estão corretos no arquivo atual (50px,
      raio 10px, borda 1,5px, padding 14px) — **não mexer neles**. O que falta
      é o `placeholder`, cuja cor é `#9AA79F` (linha 238).
- [ ] **Placeholders:** nome "Como está no documento"; e-mail
      "nome@email.com"; telefone "(00) 00000-0000"; CPF "000.000.000-00";
      nascimento "dd/mm/aaaa".
- [ ] **Textos de ajuda:** e-mail "Enviamos a confirmação para este endereço.";
      telefone ganha "Usado só se precisarmos falar com você." (hoje não tem);
      CPF "Só os números."; grupo "A lista mostra os grupos da cidade
      escolhida.". **Exceção deliberada ao desenho:** o nascimento mantém
      "Algumas atividades têm idade mínima **ou máxima**" — o sistema aceita as
      duas regras, e escrever só uma faria a tela mentir sobre a própria
      validação. Escreva esse porquê no comentário, ao lado da frase.
- [ ] **Ações (`.actions`, linha 266):** borda superior 1px, 30px de topo,
      24px de respiro, "Voltar ao evento" discreto à esquerda e o botão
      principal empurrado à direita (`margin-left:auto`).
- [ ] **Resumo (`.summary`, linhas 269–277):** sem o título "Resumo"; abre pelo
      **nome do evento** (16px) e, embaixo, `quando_rotulo` + " · " + `local`
      em 14px muted (`.summary__ev`) — a linha some quando `local` é nulo, sem
      deixar o separador órfão. Total em Bricolage 24px com
      `letter-spacing:-.02em`. Fecha com "Você só paga na última etapa."
      (`.buy__n`, 14px de topo). `sticky` em `top: 84px`.
- [ ] **O cartão "Precisa de ajuda?" sai da lateral:** o mesmo contato já está
      no rodapé do `PublicoLayout`, e o protótipo só o traz na vitrine. Dizer
      duas vezes na mesma tela não ajuda ninguém.
- [ ] **Cabeçalho da página:** crumb "← {nome do evento}" (14px muted, 20px
      abaixo) e `<h1>Inscrição</h1>` em 30px. **Sai** a linha "Valor da
      inscrição: R$ X" — o valor vive no resumo, e repeti-lo acima do
      indicador de etapas é o mesmo número duas vezes na mesma dobra.
- [ ] **Navegação do site (`.top__link`, linha 57):** 14px, muted, padding
      8px/12px, raio de pílula, alvo de toque de 44px. Alvo atual da página
      marcado com `aria-current="page"`.

### Campo de nascimento

- [ ] O campo continua **digitável** e continua tendo `id="data_nascimento"`
      e o rótulo "Data de nascimento" — é por eles que doze cenários de
      navegador o encontram.
- [ ] Máscara `dd/mm/aaaa` durante a digitação; o `v-model` emite ISO.
- [ ] Botão de calendário com `aria-label`, alvo de 44px, abrindo um Popover
      com o Calendar. Ao escolher um dia, o popover fecha e **o foco volta para
      o campo** — quem usa teclado não pode ser deixado no vazio.
- [ ] Calendário navegável só pelo teclado (setas, PageUp/PageDown, Escape
      fecha), com o mês e o ano legíveis por leitor de tela.
- [ ] `max` = hoje, respeitado nos dois caminhos (digitação e calendário).
- [ ] Data inexistente digitada (`31/02/2000`) vira erro em português, no
      mesmo lugar dos outros erros.

### Acessibilidade — o que não se negocia (DA-42)

- [ ] Todo texto novo em AA: 4,5:1 para texto normal, 3:1 para texto grande.
      A cor de placeholder do protótipo (`#9AA79F`) **precisa ser medida sobre
      o branco do painel antes de entrar** — se reprovar, escurece, e o valor
      original fica registrado no comentário, como já foi feito três vezes na
      DA-55.
- [ ] Nenhum alvo de toque abaixo de 44px — inclusive o botão do calendário e
      os links novos do cabeçalho.
- [ ] Foco visível em tudo que recebe foco; nada de `outline-hidden` sem
      substituto.
- [ ] `aria-describedby` continua ligando cada campo à sua nota e ao seu erro.

### Provas

- [ ] **Playwright, cenário novo em 1280×800** (`inscricao-desenho.spec.ts`):
      o painel existe e tem fundo diferente do papel; os sete campos da etapa 1
      têm **a mesma largura de coluna** — medir `boundingBox()` e afirmar que
      e-mail, CPF e cidade coincidem, que é exatamente o defeito da imagem
      "antes"; o resumo mostra `quando · local` e a frase "Você só paga na
      última etapa."; o cabeçalho tem os dois links.
- [ ] **Playwright, calendário:** abre pelo botão, escolhe um dia, o campo
      recebe a data no formato `dd/mm/aaaa`, o popover fecha e o foco volta ao
      campo. Um segundo cenário faz o mesmo **só pelo teclado**.
- [ ] **Playwright, celular (projeto padrão da suíte):** a barra fixa do
      rodapé com o Total **continua existindo** e o "Continuar" continua a um
      toque — decisão do dono do produto, contra o protótipo, que não a tem.
- [ ] **Os 51 cenários que já existem passam.** Os únicos com edição permitida
      são `apoio.ts` (linha 50) e `validacao-do-formulario.spec.ts`
      (linhas 23 e 56), e só por causa do formato da data. **Se qualquer outro
      cenário precisar ser editado, pare e explique** — é sinal de que a tela
      mudou mais do que o desenho pedia.
- [ ] **A suíte Pest inteira passa** (550+ testes), sem edição: esta feature
      não encosta no backend.
- [ ] `npx vue-tsc --noEmit`, `npm run lint` e `npm run build` limpos.
- [ ] `git diff --stat app/ routes/ database/` volta **vazio**.

## 6. Ambiguity Handling

**Decisões tomadas com o dono do produto nesta entrevista:**

- **Cabeçalho: só a navegação, marca atual.** Entram "Agenda" e "Minha
  inscrição"; o brasão da CCC fica. O monograma do protótipo não entra porque
  marca é assunto de quem conduz a comunidade, não da tela (**DA-37**).
- **Nascimento: calendário próprio**, com `@internationalized/date` +
  `popover` + `calendar` do shadcn-vue. Foi a escolha explícita, sabendo do
  custo da dependência nova.
- **A barra fixa do celular fica.** O protótipo põe as ações só dentro do
  painel; no celular isso esconderia o Total e o "Continuar" atrás de uma
  rolagem, e ali não existe resumo lateral para compensar. Desvio consciente,
  a registrar como decisão.
- **O painel vale para as etapas 1, 2, 3 e para a tela de Pagamento.**
- **Textos do desenho, exceto a idade** — ver Quality Criteria.

**Premissas de quem escreve o plano, a conferir na execução:**

- O `.summary` é ancorado em `top: 84px` no protótipo porque lá o cabeçalho é
  fixo e mede 64px. **O nosso cabeçalho não é fixo** — copiar o número às cegas
  deixaria um vão. Meça e ajuste; o valor certo é o que não abre buraco.
- A logo aparecendo **duas vezes** na imagem "antes" (canto esquerdo e canto
  direito) não tem origem no `PublicoLayout.vue`, que renderiza uma só.
  Confirme no navegador antes de mexer: se for extensão do navegador de quem
  tirou o print, não há nada a corrigir. **Não invente uma correção para um
  defeito que você não conseguiu reproduzir.**
- O ícone vermelho dentro do campo "Nome completo", na mesma imagem, tem cara
  de gerenciador de senhas do navegador. Mesma regra: reproduza antes de tratar.

**Se travar durante a execução:**

- Um valor do protótipo reprovando em contraste **não** é motivo para abrir mão
  do contraste: escureça, registre o valor original no comentário e siga
  (**DA-42**, **DA-55**).
- Se a troca do campo de data quebrar cenário fora dos três lugares previstos,
  **pare e relate** em vez de ajustar o cenário.
- Se o Calendar do shadcn-vue exigir mais dependências além do
  `@internationalized/date`, **pare e pergunte** — a conta muda.

## 7. Prohibitions

- ❌ **Não tocar em `app/`, `routes/`, `database/`.** Não há nada a fazer lá:
  `quando_rotulo` e `local` já existem.
- ❌ **Não rodar o gerador do shadcn-vue** (`npx shadcn-vue add`) sobre os
  componentes existentes — **DA-44**. Os 23 componentes de interface carregam
  comentário em português e correção de acessibilidade que o gerador apaga.
  Popover e Calendar entram adaptados à mão.
- ❌ **Não usar a sintaxe `classe-[--variavel]`** do Tailwind 3: na versão 4 é
  `classe-(--variavel)`, e a forma antiga vira CSS inválido em silêncio
  (**D-86**).
- ❌ **Não combinar classe estática com classe condicional para a mesma
  propriedade** (`bg-card` fixo + `bg-muted` condicional): quem decide vira a
  ordem no CSS, não a intenção. Um ternário decide fundo, borda e texto de uma
  vez (**DA-68**).
- ❌ Não remover a barra fixa do rodapé no celular.
- ❌ Não alterar `id`, `name` ou rótulo de nenhum campo — são o endereço dos
  cenários de navegador.
- ❌ Não introduzir dependência além do `@internationalized/date`.
- ❌ Não editar cenário Playwright que não seja `apoio.ts` ou
  `validacao-do-formulario.spec.ts`.
- ❌ Não mexer em `radix-vue` (não existe mais) nem reintroduzir
  `tailwind.config.js` (removido na Etapa 21).

---

## Execution Steps

1. **Navegação no cabeçalho.** `PublicoLayout.vue` ganha "Agenda" (`route('home')`)
   e "Minha inscrição" (`route('inscricoes.acesso')`) no padrão `.top__link`,
   com alvo de 44px e `aria-current` no link da página atual. Conferir em
   360px que os dois links não empurram o nome da comunidade para fora.

2. **Cabeçalho da tela de inscrição.** No `Criar.vue`: crumb
   "← {evento.nome}" apontando para `/eventos/{slug}`, `<h1>Inscrição</h1>` em
   30px, e **remoção** da linha "Valor da inscrição". Ajustar o respiro do
   indicador de etapas para o do desenho (26px acima, 38px abaixo).

3. **O painel.** Envolver o conteúdo das três etapas no `.panel` e mover as
   ações para o rodapé dele, com a borda superior do `.actions`. A barra fixa
   do celular **continua**, com o Total; em tela grande ela segue virando linha
   comum, agora dentro do painel. Cada valor copiado do protótipo leva no
   comentário o seletor de onde veio.

4. **A grade e os campos.** `PassoDadosPessoais.vue`: os três blocos viram
   **uma grade só** de duas colunas, com o nome ocupando as duas; cai o
   `sm:max-w-md`. Entram os cinco placeholders, os textos de ajuda do desenho
   (menos a idade), o placeholder condicional do grupo e a regra de erro
   substituindo a nota. Não mexer na altura, no raio nem na borda dos campos —
   já estão certos.

5. **Popover e Calendar.** Instalar `@internationalized/date`; escrever à mão
   `components/ui/popover/` e `components/ui/calendar/` sobre os primitivos do
   Reka UI, seguindo a forma dos componentes que já existem (comentário em
   português dizendo o porquê, `cn()` para classe, tipos exportados).

6. **O campo de nascimento.** `DateField.vue` passa a ser campo de texto com
   máscara `dd/mm/aaaa` e botão de calendário. **Contrato preservado:** recebe
   e emite ISO, mantém `id`, `name`, `max`, `aria-*`. Foco volta ao campo ao
   fechar o popover. No `Criar.vue`, o conferidor de `data_nascimento` passa a
   recusar data inexistente e data no futuro.

7. **O resumo lateral.** `ResumoDaInscricao.vue` vira o `.summary`: sem o
   título "Resumo", abrindo pelo nome do evento com `quando_rotulo · local`
   embaixo, linhas de escolha, total em Bricolage e a frase "Você só paga na
   última etapa.". Sai o cartão "Precisa de ajuda?".

8. **A tela de cobrança.** `Pagamento.vue` adota a mesma moldura de painel,
   sem mexer no QR Code, no prazo, nas instruções numeradas nem na consulta de
   estado — só a caixa em volta.

9. **As provas.** Ajustar `apoio.ts` e os dois `fill` de
   `validacao-do-formulario.spec.ts` para o formato da tela. Escrever
   `tests/e2e/inscricao-desenho.spec.ts` com os cenários de 1280×800, de
   calendário (mouse e teclado) e do celular. Rodar a suíte Playwright inteira,
   a suíte Pest inteira, `vue-tsc`, `lint` e `build`; conferir que
   `git diff app/ routes/ database/` volta vazio.

10. **Registro.** Anotar em `docs/PROGRESS.md` as decisões novas na numeração
    corrente (a última é a **DA-73**): a navegação do cabeçalho, o calendário
    próprio com a dependência nova, a barra fixa do celular mantida contra o
    protótipo, a nota de idade mantida contra o protótipo, e a saída do cartão
    "Precisa de ajuda?" da lateral. Cada uma com o **porquê**, não só o o quê.

## Done

A tela de inscrição, aberta em 1280×800, é a imagem "depois": formulário em
painel branco, sete campos alinhados numa grade de duas colunas com
placeholder, resumo abrindo pelo nome do evento com data e local, ações no
rodapé do painel e navegação no cabeçalho — com o campo de nascimento
digitável e com calendário, todos os 51 cenários anteriores verdes (exceto os
dois arquivos de data, editados pelo motivo declarado), a suíte Pest intacta e
`git diff app/ routes/ database/` vazio.

## Commit

`fix(publico): ajustar a tela de inscricao ao desenho do prototipo`
