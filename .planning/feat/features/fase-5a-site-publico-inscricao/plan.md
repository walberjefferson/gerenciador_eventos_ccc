# Action Plan — Fase 5a: site público do evento, inscrição e pagamento Pix

> **Type:** feature
> **Created:** 2026-08-20
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Senior Frontend Engineer **Vue 3.5 + TypeScript strict + Inertia 2 + Tailwind + shadcn-vue**, mobile-first, com prática em acessibilidade WCAG 2.1 AA e em formulários de várias etapas. Sabe Laravel o suficiente para escrever controllers Inertia finos e Resources — mas **não** mexe em regra de domínio.

**Scope — Fase 5a:** as telas públicas que levam da descoberta do evento até a cobrança Pix na mão do participante.

| Entrega | Nesta fase |
|---------|:----------:|
| Página pública `/eventos/{slug}` (vitrine + programação + vagas + regulamento) | ✅ |
| Formulário de inscrição em 4 etapas | ✅ |
| Tela de pagamento Pix (QR Code, copia e cola, contador regressivo) | ✅ |
| Acesso de volta à cobrança por URL assinada | ✅ |
| Testes E2E com Playwright | ✅ |
| Área do participante completa (timeline, histórico, reenvio de acesso) | ❌ **plano 5b** |
| Painel administrativo | ❌ Fase 6 |
| Envio de e-mails | ❌ Fase 7 |

**Stack:** PHP 8.4 · Laravel 12 · Vue 3.5 · TypeScript strict · Inertia 2 · Tailwind · shadcn-vue sobre **`radix-vue`** (atenção: este projeto usa `radix-vue`, **não** `reka-ui` — ver §3.7) · Playwright · Pest 4.

---

## 2. Direct Objective

Dar rosto ao backend que já funciona: um visitante encontra o evento em `/eventos/{slug}`, entende a programação e as vagas, preenche a inscrição em quatro etapas com as regras de seleção explicadas na tela, e termina diante de uma cobrança Pix que pode pagar pelo celular — voltando a ela depois por um link seguro, sem senha.

Nenhuma regra de negócio nova. A tela **explica** e **antecipa** o que o servidor já decide; o servidor continua sendo a única autoridade.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas pelo dono do produto (NÃO reabrir)

| Tema | Decisão |
|------|---------|
| Escopo | Fase 5 dividida: **5a** (fluxo público, este plano) e **5b** (área do participante) |
| Acesso de volta à cobrança (DA-05) | **URL assinada com validade** (`URL::temporarySignedRoute`), sem tabela nova e sem senha |
| QR Code | **`bacon/bacon-qr-code`** no backend, gerando **SVG** (sem imagick, sem peso no bundle) |
| Identidade visual | **Logo e paleta do CCC entregues e já no projeto** (`public/img/logo-ccc.png`). Paleta extraída da própria logo e validada em contraste — ver §3.6 |

### 3.2 O que o backend JÁ oferece (verificado — não reimplementar)

- Domínio completo das Fases 0-4, **177 testes verdes**, HEAD `6530b2c`.
- `POST /inscricoes` → `InscricaoController@store`, com `StoreInscricaoRequest`. Payload aceito:
  `evento_id`, `cidade_id`, `grupo_participante_id`, `nome_completo`, `email`, `telefone`, `documento` (CPF validado), `data_nascimento`, `atividades` (array de ids, `present` — pode vir vazio), `aceite_termos` (accepted), `chave_idempotencia` (uuid).
- Resposta atual: **JSON** `201` (criada) ou `200` (mesma `chave_idempotencia` reenviada), com `codigo_publico`, `situacao`, `situacao_rotulo`, `valor_centavos`, `prazo_pagamento`, `atividades`.
- Recusas de domínio chegam como **422** com erros por campo, já escritos em linguagem para leigo (`InscricaoInvalidaException::erros()`).
- `Pagamento` já é criado junto com a inscrição, com `pix_copia_e_cola`, `expira_em` e `situacao`.
- Provedor simulado com rotas de simulação em `routes/dev.php` (só `local`/`testing`).
- Enums expõem `rotulo()` para texto amigável — **use-os**, não reescreva rótulos no frontend.

### 3.3 O que este plano precisa criar no backend (fino, sem regra nova)

- `EventoPublicoController@show` → `Inertia::render('Eventos/Show')`. Só eventos com inscrições abertas ou publicados aparecem; `rascunho`/`cancelado` → **404**.
- Resources (`app/Http/Resources/`) que montam os props **sem vazar** `documento`, `documento_hash`, contadores crus nem `configuracoes` internas. Expor `vagas_disponiveis` já calculado.
- `InscricaoPublicaController@create` → `Inertia::render('Inscricoes/Criar')` com evento, dias, grupos, atividades, cidades e grupos de participantes.
- **Adaptar** `InscricaoController@store` para negociar a resposta: quando `$request->inertia()` for verdadeiro → **redirect** para a URL assinada da cobrança; caso contrário → JSON exatamente como hoje. Isso preserva os 177 testes existentes, que esperam JSON.
- `PagamentoController@show`, rota `GET /inscricoes/{codigo_publico}/pagamento` protegida pelo middleware **`signed`** → `Inertia::render('Inscricoes/Pagamento')`.
- `PagamentoController@situacao`, rota assinada `GET /inscricoes/{codigo_publico}/situacao` → JSON enxuto (`situacao`, `situacao_rotulo`, `pago_em`) para a tela detectar a confirmação sem recarregar.
- `app/Services/Pagamentos/GeradorQrCodePix.php` → recebe `pix_copia_e_cola`, devolve SVG.
- Validade da URL assinada: **casada com `prazo_pagamento`** da inscrição, com folga de 24h para o participante ainda ver a tela de "expirada" em vez de bater num 403 sem explicação.

### 3.4 As telas

**Página do evento — `/eventos/{slug}`**
Nome, banner, descrição, datas, programação por dia (grupos e atividades com horário e vagas), valor, vagas restantes, regulamento e botão de inscrição. Quando as inscrições estiverem fechadas ou esgotadas, o botão dá lugar a uma explicação do motivo.

**Formulário — 4 etapas, navegação no cliente, uma única gravação no fim**

1. **Dados pessoais** — nome, e-mail, telefone, CPF, data de nascimento, cidade e grupo. O grupo só lista os da cidade escolhida.
2. **Participação** — por dia, os grupos de atividades com suas regras visíveis: "Escolha entre 1 e 2". Cada atividade mostra horário e vagas. Estados: normal, `Esgotado`, `Indisponível — conflito de horário com Futebol`, e o contador `2 de 2 selecionadas` que desabilita o restante.
3. **Revisão** — resumo completo, valor, prazo e aceite do regulamento.
4. **Pagamento** — resultado da gravação: redireciona para a tela da cobrança.

**Tela de pagamento** — valor, QR Code, código copia e cola com botão de copiar, prazo, contador regressivo e instruções. Detecta a confirmação e troca para o estado "inscrição confirmada" sem exigir F5. Quando o prazo vence, mostra a tela de expirada com o motivo.

### 3.5 Regras espelhadas no cliente (conforto, nunca autoridade)

O composable `useSelecaoAtividades` espelha RN-03 a RN-09 para que a tela explique antes de o servidor recusar:
mínimo e máximo por grupo · grupo obrigatório · conflito de horário (`comecaA < terminaB && terminaA > comecaB`, **limites que se tocam são permitidos**) · conflito explícito nos dois sentidos · faixa etária na data da atividade · esgotado.

**Regra inegociável:** o 422 do servidor manda. Ao receber erro, o formulário **volta ao passo que contém o campo com problema** e destaca o campo — nunca deixa o participante preso numa etapa sem entender o que houve.

### 3.6 Tema e identidade — Caminhada Comunitária com Cristo (CCC)

A logo está em **`public/img/logo-ccc.png`** (1937×2000, PNG com transparência). Não existe versão vetorial; use-a como está, sempre com `width`/`height` explícitos para não causar deslocamento de layout, e com `alt="Caminhada Comunitária com Cristo"`.

**Paleta extraída da própria logo** (amostragem real dos pixels, não estimativa):

| Papel | Hex | Origem na logo |
|-------|-----|----------------|
| Vermelho | `#D0020D` | primeiro C, faixa, "CAMINHADA" |
| Verde | `#019018` | segundo C, morro, "COMUNITÁRIA" |
| Azul | `#0684D5` | terceiro C, água, "CRISTO" |
| Amarelo sol | `#FAD119` | disco do sol |
| Amarelo raios | `#FCF222` | raios do sol |
| Preto | `#000000` | cruz |

**Contraste medido — estas são regras, não sugestões:**

| Cor | Sobre branco | Pode ser texto no modo claro? | Texto POR CIMA da cor |
|-----|:---:|---|---|
| Vermelho `#D0020D` | 5.68:1 | ✅ sim, inclusive texto pequeno | branco (5.68:1) |
| Verde `#019018` | 4.20:1 | ⚠️ só título grande e elemento de UI | **preto** (5.00:1) |
| Azul `#0684D5` | 3.99:1 | ⚠️ só título grande e elemento de UI | **preto** (5.27:1) |
| Amarelo sol `#FAD119` | 1.48:1 | ❌ nunca | **preto** (14.20:1) |
| Amarelo raios `#FCF222` | 1.18:1 | ❌ nunca | **preto** (17.86:1) |

**Variantes obrigatórias para texto pequeno no modo claro** (escurecidas até 4.5:1, mesma matiz):
`verde-texto #018917` (4.57:1) · `azul-texto #0677C0` (4.76:1) · `amarelo-texto #88710D` (4.76:1)

**Modo escuro** (fundo `#0B0B0C`): verde (4.69:1), azul (4.94:1) e os amarelos (13:1 e 16:1) funcionam **sem alteração**. Só o vermelho precisa clarear para **`#DB3F47`** (4.51:1) — o `#D0020D` puro dá apenas 3.46:1 sobre fundo escuro e reprova.

**Papéis semânticos** — a cor entra pelo significado, nunca pela decoração:
- **Vermelho** = ação principal (botão de inscrever, botão de copiar código Pix). É a única que passa em texto pequeno no claro e é a cor mais forte da marca.
- **Verde** = sucesso e disponibilidade (inscrição confirmada, pagamento aprovado, "8 vagas disponíveis").
- **Azul** = informação e navegação (links, dicas, indicador de etapa).
- **Amarelo** = atenção e contagem de tempo (prazo se aproximando, contador regressivo) — **sempre com texto preto por cima**, nunca como cor de texto.
- **Cinza neutro do starter kit** = todo o resto. A logo já é muito colorida; a tela precisa ser sóbria para ela respirar.

Toda cor vive em **tokens semânticos** (CSS custom properties como `--cor-acao`, `--cor-sucesso`), definidos uma vez para o modo claro e redefinidos para o escuro. Nenhum componente escreve hex nem classe de cor literal — trocar a paleta tem que ser editar um arquivo.

**Este é um evento de uma comunidade católica.** O tom das telas acompanha: acolhedor e claro, sem gíria e sem agressividade de marketing. A logo é elemento de identidade e respeito — nunca a distorça, recorte ou aplique efeito sobre ela.

### 3.7 Armadilhas conhecidas deste projeto

- **`radix-vue`, não `reka-ui`.** O starter kit é da geração anterior do shadcn-vue. Rodar o CLI do shadcn-vue às cegas pode instalar componentes que importam `reka-ui` e **quebrar o build**. Conferir cada componente adicionado e alinhar os imports ao padrão dos 17 componentes que já existem em `resources/js/components/ui/`.
- **Componentes ausentes que este plano exige:** `select`, `radio-group`, `badge`, `alert`, `progress`, `sonner` (toast) e um campo de data. Instalar só esses.
- **`@vueuse/core` já está instalado** — use-o para clipboard e contador regressivo em vez de escrever do zero ou instalar mais nada.
- **Sem Vitest no projeto.** Não instalar: as regras do composable são cobertas pelos cenários do Playwright, e o backend já as testa em 79 testes de domínio. Se durante a execução ficar evidente que falta teste unitário de lógica pura, registrar como pendência — não instalar por conta própria.
- **Postgres do Sail responde na porta 55432** do host (decisão D-19); os testes usam o banco `testing` via `phpunit.xml`.
- **Executores morrem em ~60 chamadas de ferramenta**, sem aviso. Commitar ao fim de **cada** step.

### 3.8 Arquivos a ler antes de começar

- `.planning/feat/features/plataforma-eventos-backend-mvp/plan.md` §3.2 e §3.3 — schema e regras RN-01..RN-13
- `app/Http/Requests/StoreInscricaoRequest.php` — o contrato exato do formulário
- `app/Http/Controllers/InscricaoController.php` — a resposta que será adaptada
- `resources/js/components/ui/` (amostra de 2 componentes) e `resources/js/layouts/AuthLayout.vue` — o padrão a seguir
- `docs/PROGRESS.md` — decisões D-01..D-29 já tomadas

---

## 4. Output Format

### Backend (fino)

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/EventoPublicoController.php` | create | `show` do evento por slug |
| `app/Http/Controllers/InscricaoPublicaController.php` | create | `create` (formulário) |
| `app/Http/Controllers/PagamentoController.php` | create | `show` (assinada) + `situacao` (JSON) |
| `app/Http/Controllers/InscricaoController.php` | modify | negociar resposta: Inertia → redirect assinado; demais → JSON como hoje |
| `app/Http/Resources/{EventoPublicoResource,DiaEventoResource,GrupoAtividadeResource,AtividadeResource,CidadeResource,InscricaoResource,PagamentoResource}.php` | create | props sem vazamento de dado interno |
| `app/Services/Pagamentos/GeradorQrCodePix.php` | create | Pix copia e cola → SVG |
| `routes/web.php` | modify | rotas públicas + rotas assinadas |
| `composer.json` | modify | `bacon/bacon-qr-code` |

### Frontend

| File | Action | Description |
|------|--------|-------------|
| `resources/js/layouts/PublicoLayout.vue` | create | cabeçalho, rodapé, sem barra lateral, mobile-first |
| `resources/js/pages/Eventos/Show.vue` | create | vitrine do evento |
| `resources/js/pages/Inscricoes/Criar.vue` | create | formulário de 4 etapas |
| `resources/js/pages/Inscricoes/Pagamento.vue` | create | cobrança Pix, confirmada e expirada |
| `resources/js/components/eventos/*` | create | CabecalhoEvento, ProgramacaoDoDia, ResumoDeVagas |
| `resources/js/components/inscricao/*` | create | IndicadorDePassos, PassoDadosPessoais, PassoParticipacao, PassoRevisao, GrupoDeAtividades, CartaoDeAtividade |
| `resources/js/components/pagamento/*` | create | QrCodePix, CodigoCopiaECola, ContadorRegressivo |
| `resources/js/composables/{useSelecaoAtividades,useContadorRegressivo,useGruposDaCidade}.ts` | create | regras espelhadas e utilidades |
| `resources/js/types/*.ts` | create/modify | tipos dos props vindos do Inertia |
| `resources/js/components/ui/*` | create | apenas os componentes shadcn ausentes (§3.7) |

### Testes

| File | Action | Description |
|------|--------|-------------|
| `tests/Feature/Publico/{EventoPublicoTest,PaginaPagamentoTest}.php` | create | props corretos, evento em rascunho → 404, URL sem assinatura → 403 |
| `tests/e2e/*.spec.ts` + `playwright.config.ts` | create | cenários de §5 |
| `package.json` | modify | Playwright + scripts `test:e2e` |

---

## 5. Quality Criteria

### Funcional
- [ ] `/eventos/{slug}` exibe nome, banner, descrição, datas, programação, valor, vagas e regulamento
- [ ] Evento em `rascunho` ou `cancelado` responde **404**; inscrições fechadas exibem o motivo no lugar do botão
- [ ] O grupo de participante só lista os da cidade escolhida
- [ ] Atividade esgotada aparece como `Esgotado` e não é selecionável
- [ ] Conflito de horário exibe **qual** atividade conflita, pelo nome
- [ ] Atingido o máximo, aparece `2 de 2 selecionadas` e as demais ficam desabilitadas até liberar uma
- [ ] Erro 422 do servidor devolve o participante ao passo do campo com problema, com o campo destacado
- [ ] Tela de pagamento mostra valor, QR Code, copia e cola, botão copiar, prazo e contador regressivo
- [ ] Pagamento confirmado troca a tela sem F5; prazo vencido mostra a tela de expirada
- [ ] URL da cobrança **sem assinatura válida** responde 403

### Qualidade
- [ ] `vendor/bin/pint --test` limpo · `npm run lint` limpo · `vue-tsc` sem erro (TypeScript strict)
- [ ] Os **177 testes** existentes continuam verdes — o `store` adaptado não pode quebrar nenhum
- [ ] Nenhum dado sensível nos props do Inertia (sem `documento`, sem `documento_hash`, sem contadores crus)
- [ ] Nenhuma regra de negócio nova no Vue; nenhuma alteração em Action, Service ou migration do domínio
- [ ] Cores só via tokens semânticos; nenhuma cor escrita direto no componente
- [ ] Nenhuma dependência além de `bacon/bacon-qr-code` e Playwright sem justificativa no PROGRESS

### Acessibilidade e mobile (a experiência mobile é prioritária)
- [ ] Formulário inteiro navegável por teclado, com foco sempre visível
- [ ] Todo campo tem `<label>` associado; erros ligados por `aria-describedby` e anunciados com `role="alert"`
- [ ] Mudança de passo anuncia o novo passo para leitor de tela
- [ ] Contraste WCAG AA nos modos claro e escuro
- [ ] Alvos de toque com no mínimo 44×44 px
- [ ] Layout sem rolagem horizontal em telas a partir de 320 px

### Playwright E2E (obrigatórios)
- [ ] **Caminho feliz:** da página do evento até o QR Code visível
- [ ] **Erro de validação:** CPF inválido e campo obrigatório vazio, com mensagem em linguagem simples
- [ ] **Esgotado:** atividade sem vaga não é selecionável
- [ ] **Conflito de horário:** seleção que conflita é bloqueada e explicada
- [ ] **Máximo atingido:** contador e desabilitação
- [ ] **Confirmação:** simular o pagamento e ver a tela virar "confirmada" sem recarregar
- [ ] **Acesso indevido:** URL de cobrança sem assinatura → 403
- [ ] Cada cenário roda contra banco semeado de forma determinística, sem depender de ordem entre testes

---

## 6. Ambiguity Handling

**Assumptions made:**
- **Uma única gravação no fim.** As 4 etapas são navegação no cliente; não existe rascunho no servidor, porque o backend cria a inscrição inteira num POST só. Sair no meio perde o preenchimento — aceitável no MVP e registrado como tal.
- **Vagas na tela são um retrato do momento do carregamento.** Podem envelhecer enquanto a pessoa preenche; a autoridade é a revalidação no envio, que já existe e já devolve 422 amigável. Não haverá polling de vagas nesta fase.
- **A `chave_idempotencia` é gerada no cliente** ao abrir o formulário (`crypto.randomUUID()`) e reenviada em cada tentativa, para que o duplo clique e o retry não criem duas inscrições.
- **O link assinado é entregue por redirect na própria tela**, porque e-mail só existe na Fase 7. Quando a Fase 7 chegar, o mesmo link passa a ser enviado por e-mail — sem mudar esta tela.
- **A tela de pagamento consulta a situação por polling leve** (a cada poucos segundos, parando ao confirmar ou expirar). É a solução mais simples que funciona sem WebSocket.
- **Sem Vitest** (§3.7).

**If unsure during execution:**
- Dúvida de texto para o participante → escrever a frase mais simples possível, como se explicasse para alguém que nunca usou o sistema; registrar no PROGRESS.
- Dúvida de layout ou espaçamento → seguir o padrão dos componentes que já existem em `resources/js/components/ui/`.
- Cor não prevista em §3.6 → derivá-la dos tokens existentes e validar o contraste antes de usar. **Não inventar cor de marca nova.**
- Algo exigiria mudar regra de domínio → **PARAR**. Este plano não altera o backend de negócio.

---

## 7. Prohibitions

- ❌ **Nunca** implementar regra de negócio nova no Vue — a tela espelha, o servidor decide
- ❌ **Nunca** alterar Actions, Services, Enums ou migrations do domínio
- ❌ **Nunca** confiar em parâmetro do cliente para dizer que algo foi pago
- ❌ **Nunca** expor `documento`, `documento_hash` ou contadores internos nos props do Inertia
- ❌ **Nunca** usar `codigo_publico` sozinho como autenticação — a assinatura da URL é obrigatória
- ❌ **Nunca** escrever cor direto no componente; só tokens semânticos
- ❌ **Nunca** instalar componente shadcn que importe `reka-ui` neste projeto (§3.7)
- ❌ **Nunca** instalar dependência sem verificar se `@vueuse/core`, Laravel ou Vue já resolvem
- ❌ **Nunca** entregar tela sem estado de carregamento e sem estado de erro
- ❌ **Nunca** implementar a área do participante (5b), o painel (Fase 6) ou e-mails (Fase 7)
- ❌ **Nunca** dar `git push` sem autorização explícita

---

## Execution Steps

1. **Base do site público.** Instalar `bacon/bacon-qr-code` e Playwright. Adicionar apenas os componentes shadcn ausentes, conferindo que importam `radix-vue` (§3.7). Criar `PublicoLayout.vue` e os tokens de tema (logo/cores do CCC se já disponíveis; neutros caso contrário). Confirmar que `npm run build` e `vue-tsc` passam. → commit `chore(publico): add public layout, qr code lib and playwright`

2. **Backend de leitura do evento.** `EventoPublicoController@show`, Resources sem vazamento, rota `/eventos/{slug}`, e `tests/Feature/Publico/EventoPublicoTest.php` (props corretos, `vagas_disponiveis` calculado, rascunho → 404). → commit `feat(publico): add public event page endpoint`

3. **Página do evento.** `Eventos/Show.vue` + componentes de vitrine, mobile-first, com programação por dia e o estado de inscrições fechadas/esgotadas. → commit `feat(publico): add event showcase page`

4. **Regras espelhadas.** `useSelecaoAtividades.ts` (mín/máx, obrigatório, conflito de horário com limites que se tocam permitidos, conflito explícito nos dois sentidos, faixa etária, esgotado), `useGruposDaCidade.ts` e os tipos em `resources/js/types/`. → commit `feat(inscricao): add activity selection rules composable`

5. **Formulário, etapas 1 e 2.** `Inscricoes/Criar.vue` com `IndicadorDePassos`, `PassoDadosPessoais` e `PassoParticipacao` (cartões de atividade com horário, vagas, esgotado, conflito e contador de seleções). → commit `feat(inscricao): add personal data and participation steps`

6. **Etapas 3 e 4 + gravação.** `PassoRevisao` com aceite do regulamento; `chave_idempotencia` gerada no cliente; adaptar `InscricaoController@store` para redirecionar Inertia à URL assinada mantendo o JSON para os demais; mapear o 422 de volta ao passo correto. Rodar a suíte Pest inteira para provar que os 177 testes seguem verdes. → commit `feat(inscricao): add review step and signed redirect to payment`

7. **Tela de pagamento.** `GeradorQrCodePix`, `PagamentoController@show` (assinada) e `@situacao`, `Inscricoes/Pagamento.vue` com QR, copia e cola, botão copiar, contador regressivo, polling da situação e os estados confirmada e expirada. Teste de feature: URL sem assinatura → 403. → commit `feat(pagamentos): add pix payment page with qr code and countdown`

8. **Playwright — caminho feliz.** Configurar `playwright.config.ts`, semeadura determinística e o cenário completo da página do evento até o QR Code, mais o cenário de confirmação via simulação de pagamento. → commit `test(e2e): add happy path and payment confirmation`

9. **Playwright — os caminhos que dão errado.** Erro de validação, esgotado, conflito de horário, máximo de seleções e acesso sem assinatura. → commit `test(e2e): add validation, capacity and conflict scenarios`

10. **Acessibilidade, responsividade e fechamento.** Varredura de teclado, foco, rótulos, `aria-describedby`, anúncio de troca de passo, contraste AA nos dois modos, alvos de 44 px e ausência de rolagem horizontal a partir de 320 px. `pint`, `lint`, `vue-tsc`, suíte Pest e suíte Playwright. Atualizar `docs/PROGRESS.md` e `docs/IMPLEMENTATION_PLAN.md`. → commit `feat(publico): polish accessibility and responsive behavior`

## Done

Um visitante abre `/eventos/{slug}` no celular, entende o que vai acontecer nos dois dias, se inscreve escolhendo modalidades com as regras explicadas na própria tela, chega a uma cobrança Pix que consegue pagar, e — depois de pagar — vê a inscrição confirmada sem recarregar a página. Se fechar o navegador, volta pelo link assinado. A suíte Pest continua verde, a suíte Playwright cobre o caminho feliz e os quatro caminhos que dão errado, e nenhuma regra de negócio foi duplicada no Vue.

## Commit

```
chore(publico): add public layout, qr code lib and playwright
feat(publico): add public event page endpoint
feat(publico): add event showcase page
feat(inscricao): add activity selection rules composable
feat(inscricao): add personal data and participation steps
feat(inscricao): add review step and signed redirect to payment
feat(pagamentos): add pix payment page with qr code and countdown
test(e2e): add happy path and payment confirmation
test(e2e): add validation, capacity and conflict scenarios
feat(publico): polish accessibility and responsive behavior
```
