# Action Plan — Fase 5b: área do participante, linha do tempo, histórico da cobrança e recuperação de acesso

> **Type:** feature
> **Created:** 2026-08-20
> **Status:** pending

---

## 1. Persona & Scope

**Persona:** Senior Fullstack Engineer **Laravel 12 + PHP 8.4** e **Vue 3.5 + TypeScript strict + Inertia 2 + Tailwind + shadcn-vue**, mobile-first, com prática em acessibilidade WCAG 2.1 AA e em fluxos públicos sem login. Escreve controllers Inertia finos, Resources e Mailables — mas **não** inventa regra de domínio.

**Scope — Fase 5b:** o que o participante faz **depois** de se inscrever: acompanhar, entender e voltar.

| Entrega | Nesta fase |
|---------|:----------:|
| Página de acompanhamento da inscrição com linha do tempo | ✅ |
| Histórico da cobrança (todos os pagamentos da inscrição) | ✅ |
| Segunda via do Pix sob demanda, enquanto o prazo não venceu | ✅ |
| Recuperação do link de acesso por e-mail (um único Mailable) | ✅ |
| Testes E2E com Playwright dos caminhos novos | ✅ |
| Cancelamento da inscrição pelo participante | ❌ **fora do escopo** (§3.1) |
| Ouvintes de `InscricaoCriada`/`Confirmada`/`Expirada` e demais e-mails | ❌ Fase 7 |
| Painel administrativo | ❌ Fase 6 |
| Provedor de pagamento real | ❌ Fase 8 |

**Stack:** PHP 8.4 · Laravel 12 · Vue 3.5 · TypeScript strict · Inertia 2 · Tailwind · shadcn-vue sobre **`radix-vue`** (não `reka-ui` — ver §3.7) · Playwright · Pest 4 · Mailpit (SMTP local, portas 1025/8025 pelo Sail).

---

## 2. Direct Objective

Dar ao participante um lugar próprio para acompanhar a inscrição: uma página assinada que mostra, em linguagem simples, o que já aconteceu (linha do tempo), o que está pendente, o histórico da cobrança e — enquanto o prazo não venceu — o Pix novamente. E um caminho de volta quando ele fecha o navegador: informa o e-mail e recebe o link de acesso por mensagem.

**Nenhuma regra de negócio nova.** A página **lê** o que o domínio já gravou. A única escrita permitida é a segunda via do Pix, que chama uma Action existente e idempotente.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas pelo dono do produto (NÃO reabrir)

| Tema | Decisão |
|------|---------|
| Recuperação de acesso (**DA-06**) | **Antecipar apenas o e-mail de acesso**: um único Mailable disparado por um formulário público. Os e-mails de confirmação, comprovante e prazo vencido continuam sendo **Fase 7**, e os três anúncios de domínio continuam **sem ouvinte** (decisão D-12 permanece de pé) |
| Cancelamento pelo participante (**DA-07**) | **Fora do escopo da 5b.** A pendência continua aberta para o dono do produto. Consequência: esta fase **não cria nenhuma Action, Enum ou migration de domínio** |
| Organização das telas (**DA-08**) | **Nova rota de acompanhamento**, separada. `Inscricoes/Pagamento.vue` continua existindo como está; as duas telas se ligam por link. Nenhum dos 12 cenários Playwright da 5a pode ser reescrito |
| Segunda via do Pix (**DA-09**) | **Reemitir sob demanda**, chamando `CriarPagamentoDaInscricao` (já idempotente): havendo cobrança pendente devolve a mesma; não havendo, emite outra com o mesmo prazo. Isso fecha o buraco deixado pela decisão D-27 (emissão fora da transação pode falhar e deixar inscrição sem cobrança) |

### 3.2 O que já existe (verificado no HEAD `01ae2b8` — não reimplementar)

- **205 testes Pest / 795 asserções** e **12 cenários Playwright**, todos verdes. Fases 0 a 5a fechadas.
- `Inscricao` — `codigo_publico` (ULID único), `situacao` (`SituacaoInscricao`), `valor_centavos`, `prazo_pagamento`, `confirmada_em`, `expirada_em`, `cancelada_em`, `motivo_cancelamento`, `created_at`. Métodos: `evento()`, `grupoParticipante()`, `atividades()`, `pagamentos()`, `pagamentoPendente()`, `estaAtiva()`, `prazoVencido()`, escopos `ativas()` e `vencidas()`.
- `Pagamento` — `codigo_publico`, `gateway`, `id_externo`, `metodo`, `valor_centavos`, `situacao`, `pix_copia_e_cola`, `expira_em`, `pago_em`, `cancelado_em`, `estornado_em`, `valor_estornado_centavos`, `metadados`, `created_at`. Métodos: `estaAberto()`, escopos `pendentes()` e `vencidos()`.
- `PagamentoController@show` e `@situacao`, ambas atrás do middleware **`signed`**, com o helper privado `urlDaSituacao()` (validade = `prazo_pagamento` + 24h) e o cálculo privado `estado()` (`aguardando` | `confirmada` | `expirada`).
- `App\Services\Pagamentos\GeradorQrCodePix` — Pix copia e cola → SVG.
- `App\Actions\Pagamentos\CriarPagamentoDaInscricao` — **idempotente**: devolve a cobrança pendente existente ou emite uma nova com `expira_em` = `prazo_pagamento`.
- Enums com `rotulo()` — `SituacaoInscricao`, `SituacaoPagamento`, `MetodoPagamento`. **Use-os**; não reescreva rótulo em português no Vue.
- Frontend: `PublicoLayout.vue`, `pages/Eventos/Show.vue`, `pages/Inscricoes/Criar.vue`, `pages/Inscricoes/Pagamento.vue`, componentes em `components/{eventos,inscricao,pagamento}/`, composables `useContadorRegressivo`, `useSelecaoAtividades`, `useGruposDaCidade`, tipos em `types/{evento,inscricao,pagamento}.ts`.
- Componentes shadcn já presentes, entre eles `alert`, `badge`, `button`, `card`, `input`, `label`, `separator`, `skeleton`, `toast` — **provavelmente suficientes**: confira antes de instalar qualquer coisa.
- Tokens semânticos de cor (`--cor-acao`, `--cor-sucesso`, `--cor-informacao`, `--cor-atencao`), claro e escuro, com contraste AA medido (decisões D-40, D-41).

### 3.3 A linha do tempo — só leitura de carimbo de tempo

**Não existe tabela de auditoria e esta fase não cria uma.** A linha do tempo é derivada dos campos que o domínio já grava. O serviço `LinhaDoTempoDaInscricao` monta a lista de marcos, ordenada por momento, cada um com `chave`, `titulo`, `descricao`, `momento` (ISO-8601 ou `null`) e `estado` (`concluido` | `atual` | `futuro` | `encerrado`):

| Marco | Fonte | Quando aparece |
|-------|-------|----------------|
| Inscrição feita | `inscricoes.created_at` | sempre |
| Cobrança Pix emitida | `pagamentos.created_at` (o mais antigo) | quando existe pagamento |
| Prazo para pagar | `inscricoes.prazo_pagamento` | enquanto `aguardando_pagamento`; vira `encerrado` se venceu |
| Pagamento recebido | `pagamentos.pago_em` | quando houver |
| Inscrição confirmada | `inscricoes.confirmada_em` | quando houver |
| Prazo vencido | `inscricoes.expirada_em` | quando houver |
| Inscrição cancelada | `inscricoes.cancelada_em` + `motivo_cancelamento` | quando houver |
| Valor estornado | `pagamentos.estornado_em` + `valor_estornado_centavos` | quando houver |

Regras do serviço:
- **Ordena por momento**; marcos sem momento (futuros, como "prazo para pagar" ainda não vencido) vão para o fim, na ordem natural do fluxo.
- Um marco no futuro **nunca** é `concluido`.
- Só existe **um** marco `atual`: o próximo passo esperado. Inscrição confirmada ou encerrada não tem `atual`.
- O texto de cada marco é escrito para leigo — "Recebemos seu pagamento", não "payment.confirmed".

### 3.4 O histórico da cobrança

Todos os `pagamentos` da inscrição, do mais recente ao mais antigo (a segunda via pode gerar mais de um). Por pagamento: situação com rótulo, valor formatado, método, `created_at`, `expira_em`, `pago_em`, `cancelado_em`, `estornado_em` e `valor_estornado_centavos`.

**Nunca** expor `id_externo`, `gateway`, `metadados` nem `pix_copia_e_cola` fora do estado que ainda aceita pagamento — o copia e cola continua sendo assunto da tela de pagamento.

### 3.5 A recuperação do link de acesso

Fluxo, do jeito que um "esqueci a senha" bem-feito funciona:

1. `GET /acesso` — formulário com um campo: e-mail. Aceita `?evento={slug}` para dar contexto quando a pessoa vem da vitrine.
2. `POST /acesso` — valida o formato do e-mail, aplica **limite de tentativas** e responde **sempre a mesma mensagem neutra**: *"Se houver inscrição com esse e-mail, enviamos o link de acesso para ele."* — com ou sem inscrição encontrada, mesmo texto e mesmo tempo de resposta perceptível.
3. Havendo inscrições daquele e-mail (situação **diferente de cancelada**), envia **um** e-mail listando cada uma: nome do evento, situação e o link assinado da página de acompanhamento.
4. Não havendo, **nada é enviado** — e a tela não diz isso.

Regras inegociáveis:
- **Limite de tentativas** na rota `POST`: `throttle` por IP **e** por e-mail (sugestão: 5 por minuto, 15 por hora). Estourado o limite, a resposta continua sendo a mesma mensagem neutra — nunca um 429 que confirme atividade.
- **Nenhuma** resposta, código HTTP ou tempo de resposta pode diferenciar "e-mail existe" de "e-mail não existe".
- O e-mail nunca contém CPF, telefone, data de nascimento nem valor de cobrança em aberto. Nome do evento, situação e link — só.
- **Validade do link enviado: 7 dias** (`config('inscricoes.validade_link_acesso')`, em dias). É mais curto que a vida útil da inscrição de propósito: link que circula em caixa de entrada precisa envelhecer. Vencido, a pessoa pede outro.

### 3.6 A segunda via do Pix

`POST /inscricoes/{codigo_publico}/segunda-via`, **assinada** e com limite de tentativas. Chama `CriarPagamentoDaInscricao` e redireciona para a tela de pagamento (URL assinada), **somente quando**:

- `situacao === aguardando_pagamento`, **e**
- `prazoVencido()` é falso.

Fora disso, redireciona de volta ao acompanhamento com uma explicação em linguagem simples ("O prazo desta inscrição venceu"). **Não** recria prazo, **não** mexe em vaga, **não** muda situação. A Action existente cuida de tudo.

### 3.7 Armadilhas conhecidas deste projeto

- **`radix-vue`, não `reka-ui`.** Rodar o CLI do shadcn-vue às cegas instala componente que importa `reka-ui` e quebra o build. Confira os imports contra os componentes que já existem em `resources/js/components/ui/`.
- **Antes de instalar qualquer componente**, verifique a lista de §3.2 — esta fase provavelmente não precisa de nenhum novo.
- **`@vueuse/core` já está instalado** — clipboard e contagem de tempo saem dele; `useContadorRegressivo` já existe e deve ser reaproveitado.
- **Sem Vitest no projeto** e não se instala nesta fase (mesma justificativa da 5a).
- **Postgres do Sail na porta 55432** do host (D-19); os testes usam o banco `testing` fixado em `phpunit.xml`.
- **A suíte Playwright usa o mesmo banco `testing`** e o recria antes de começar (D-42) — não rode `npm run test:e2e` e `php artisan test` ao mesmo tempo.
- **20 erros de verificação de tipos conhecidos** (pendência **P-09**) vivem em telas do pacote inicial e são da Fase 6. Não são desta fase, **e nenhum erro novo pode ser somado a eles**.
- **Executores morrem em ~60 chamadas de ferramenta**, sem aviso. Commite ao fim de **cada** step.

### 3.8 Arquivos a ler antes de começar

- `app/Http/Controllers/PagamentoController.php` — o padrão de rota assinada, o cálculo de `estado()` e a geração de URL temporária a copiar
- `app/Models/Inscricao.php` e `app/Models/Pagamento.php` — os campos que alimentam a linha do tempo
- `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` — a idempotência de que a segunda via depende
- `resources/js/pages/Inscricoes/Pagamento.vue` — o padrão visual e de acessibilidade das telas públicas
- `tests/Feature/Publico/PaginaPagamentoTest.php` — como se testa rota assinada aqui
- `tests/e2e/{ambiente,semear,apoio,base}.ts` — a semeadura determinística a reaproveitar
- `docs/PROGRESS.md` — decisões D-01..D-43 e pendências P-01..P-09

---

## 4. Output Format

### Backend

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/AcompanhamentoController.php` | create | `show` da página do participante (rota assinada) |
| `app/Http/Controllers/SegundaViaPagamentoController.php` | create | `store` — segunda via do Pix sob demanda |
| `app/Http/Controllers/AcessoInscricaoController.php` | create | `create` (formulário) e `store` (envio do link, resposta neutra) |
| `app/Http/Requests/EnviarLinkAcessoRequest.php` | create | valida o e-mail; nada além disso |
| `app/Services/Inscricoes/LinhaDoTempoDaInscricao.php` | create | monta os marcos a partir dos carimbos de tempo (§3.3) |
| `app/Services/Inscricoes/GeradorLinkDeAcesso.php` | create | URL assinada do acompanhamento, com a validade de §3.5 |
| `app/Http/Resources/InscricaoAcompanhamentoResource.php` | create | props do participante, sem dado sensível |
| `app/Http/Resources/PagamentoHistoricoResource.php` | create | um pagamento do histórico (§3.4) |
| `app/Mail/LinkDeAcessoInscricao.php` | create | o único e-mail desta fase |
| `resources/views/emails/link-de-acesso.blade.php` | create | corpo do e-mail, texto simples e acolhedor |
| `config/inscricoes.php` | create | `validade_link_acesso` (dias) e limites de tentativa |
| `app/Http/Controllers/PagamentoController.php` | modify | expor `url_acompanhamento` nos props, para a tela de pagamento linkar |
| `routes/web.php` | modify | 5 rotas novas (§3.5, §3.6 e o acompanhamento) |

### Frontend

| File | Action | Description |
|------|--------|-------------|
| `resources/js/pages/Inscricoes/Acompanhar.vue` | create | a página do participante |
| `resources/js/pages/Inscricoes/RecuperarAcesso.vue` | create | formulário de um campo + mensagem neutra |
| `resources/js/components/participante/LinhaDoTempo.vue` | create | `<ol>` semântica dos marcos |
| `resources/js/components/participante/MarcoDaLinhaDoTempo.vue` | create | um marco, com estado indicado por texto **e** ícone (não só cor) |
| `resources/js/components/participante/HistoricoDaCobranca.vue` | create | lista dos pagamentos |
| `resources/js/components/participante/ResumoDaInscricao.vue` | create | evento, atividades escolhidas, valor, situação |
| `resources/js/types/participante.ts` | create | tipos dos props vindos do Inertia |
| `resources/js/pages/Inscricoes/Pagamento.vue` | modify | link "acompanhar minha inscrição"; na tela de expirada, link para pedir novo acesso |
| `resources/js/pages/Eventos/Show.vue` | modify | link discreto "já me inscrevi — acessar minha inscrição" |

### Testes

| File | Action | Description |
|------|--------|-------------|
| `tests/Feature/Participante/AcompanhamentoTest.php` | create | props corretos, sem assinatura → 403, sem vazamento |
| `tests/Feature/Participante/LinhaDoTempoTest.php` | create | os oito marcos, ordenação e o marco `atual` único |
| `tests/Feature/Participante/SegundaViaTest.php` | create | reemite, reaproveita a pendente, recusa fora do prazo |
| `tests/Feature/Participante/RecuperarAcessoTest.php` | create | resposta neutra nos dois casos, `Mail::fake()`, link assinado válido, limite de tentativas |
| `tests/e2e/acompanhamento.spec.ts` | create | linha do tempo aguardando e confirmada |
| `tests/e2e/segunda-via-do-pix.spec.ts` | create | segunda via devolve QR Code válido |
| `tests/e2e/recuperar-acesso.spec.ts` | create | mensagem neutra idêntica com e sem inscrição |

---

## 5. Quality Criteria

### Funcional
- [ ] `/inscricoes/{codigo}/acompanhar` **sem assinatura válida** responde **403**
- [ ] A página mostra: evento, situação com rótulo, valor, atividades escolhidas, linha do tempo e histórico da cobrança
- [ ] A linha do tempo cobre os oito marcos de §3.3, ordenada por momento, com **um único** marco `atual`
- [ ] Inscrição confirmada e inscrição expirada não têm marco `atual`
- [ ] Enquanto o prazo não venceu, a página oferece o caminho de volta ao Pix; vencido, explica o motivo em vez de oferecer o botão
- [ ] Segunda via com cobrança pendente devolve **a mesma** cobrança (não cria uma segunda)
- [ ] Segunda via sem cobrança aberta e dentro do prazo **emite** uma nova, com `expira_em` = `prazo_pagamento`
- [ ] Segunda via fora do prazo ou de inscrição não-pendente **não** cria nada e explica o porquê
- [ ] `POST /acesso` responde **exatamente a mesma coisa** com e sem inscrição para o e-mail informado
- [ ] O e-mail chega com um link assinado que abre a página de acompanhamento
- [ ] Estourado o limite de tentativas, a resposta continua neutra
- [ ] A tela de pagamento ganha link para o acompanhamento, e a de expirada, link para pedir novo acesso

### Qualidade
- [ ] `vendor/bin/pint --test` limpo · `npm run lint` limpo · `vue-tsc` **sem nenhum erro novo** além dos 20 conhecidos da P-09
- [ ] Os **205 testes Pest** existentes continuam verdes
- [ ] Os **12 cenários Playwright** da 5a continuam verdes, **sem serem editados**
- [ ] Nenhum `documento`, `documento_hash`, `id_externo`, `gateway`, `metadados` ou contador cru nos props do Inertia
- [ ] Nenhuma Action, Enum, Model ou migration de domínio alterada — a única escrita é a chamada a `CriarPagamentoDaInscricao`
- [ ] Nenhum ouvinte registrado para os eventos de domínio (a Fase 7 continua dona disso)
- [ ] Cor só via token semântico; nenhuma cor literal em componente
- [ ] Nenhuma dependência nova sem justificativa registrada no PROGRESS

### Acessibilidade e mobile
- [ ] A linha do tempo é um `<ol>` de verdade, e cada estado é legível **sem depender de cor** (texto + ícone)
- [ ] Formulário de recuperação com `<label>` associado, erro ligado por `aria-describedby` e anunciado com `role="alert"`
- [ ] A mensagem neutra de sucesso é anunciada para leitor de tela (`role="status"`)
- [ ] Foco sempre visível; navegação completa por teclado
- [ ] Contraste WCAG AA nos modos claro e escuro
- [ ] Alvos de toque de no mínimo 44×44 px
- [ ] Sem rolagem horizontal a partir de 320 px

### Playwright E2E (obrigatórios)
- [ ] **Acompanhamento pendente:** inscrição recém-criada → linha do tempo com "aguardando pagamento" como marco atual
- [ ] **Acompanhamento confirmado:** simular o pagamento → recarregar → marcos "pagamento recebido" e "inscrição confirmada" presentes
- [ ] **Segunda via:** botão devolve a tela de pagamento com QR Code visível
- [ ] **Recuperação de acesso:** e-mail com inscrição e e-mail sem inscrição produzem **a mesma** mensagem na tela
- [ ] **Acesso indevido:** `/acompanhar` sem assinatura → 403
- [ ] Cada cenário roda contra banco semeado de forma determinística, sem depender de ordem entre testes

---

## 6. Ambiguity Handling

**Assumptions made:**
- **O e-mail de acesso é enviado de forma síncrona** (`Mail::to()->send()`), não em fila, apesar de `QUEUE_CONNECTION=redis`. Enfileirar exigiria um `queue:work` rodando em toda máquina de desenvolvimento para o fluxo funcionar, e o volume aqui é de um e-mail por pedido humano. A Fase 7, que monta o envio em escala, troca isso adicionando `implements ShouldQueue` — uma linha.
- **Um e-mail lista todas as inscrições daquele endereço**, em vez de um e-mail por inscrição. Menos mensagem na caixa de entrada e nenhuma ambiguidade sobre qual link é qual.
- **Inscrição cancelada não entra no e-mail de acesso.** Não há o que acompanhar, e listá-la só geraria dúvida.
- **A validade do link de acesso é de 7 dias**, independente do prazo de pagamento. Um link em caixa de entrada precisa envelhecer sozinho; quem precisar de outro, pede outro.
- **A página de acompanhamento não faz consulta contínua de situação.** Quem está esperando o Pix cair fica na tela de pagamento, que já tem essa consulta desde a 5a. O acompanhamento é uma tela de leitura — recarregar basta.
- **A linha do tempo é derivada, não gravada.** Enquanto os marcos vierem de carimbo de tempo que o domínio já mantém, não há segunda fonte da verdade para divergir. Registro de auditoria de verdade é assunto da **Fase 9**.
- **`Inscricoes/Pagamento.vue` não é reescrita** (DA-08): ganha dois links e nada mais.

**If unsure during execution:**
- Dúvida de texto para o participante → escreva a frase mais simples possível, como se explicasse para alguém que nunca usou o sistema; registre no PROGRESS.
- Dúvida de layout, espaçamento ou componente → siga o padrão de `pages/Inscricoes/Pagamento.vue` e de `components/ui/`.
- Cor não prevista → derive dos tokens existentes e meça o contraste antes de usar. **Não invente cor de marca nova.**
- Faltar carimbo de tempo para um marco que parece necessário → **não crie coluna**. Registre como pendência e monte a linha do tempo com o que existe.
- Algo exigiria mudar regra de domínio, criar Action nova ou migration → **PARE** e pergunte. Esta fase é de leitura.

---

## 7. Prohibitions

- ❌ **Nunca** criar Action, Enum, Model, migration ou evento de domínio novo — a única escrita permitida é chamar `CriarPagamentoDaInscricao`
- ❌ **Nunca** implementar cancelamento de inscrição pelo participante (DA-07)
- ❌ **Nunca** registrar ouvinte para `InscricaoCriada`, `InscricaoConfirmada` ou `InscricaoExpirada` — é Fase 7
- ❌ **Nunca** enviar e-mail além do link de acesso
- ❌ **Nunca** deixar `POST /acesso` revelar, por texto, código HTTP ou tempo de resposta, se o e-mail tem inscrição
- ❌ **Nunca** colocar CPF, telefone, data de nascimento ou dado de cobrança dentro do corpo do e-mail
- ❌ **Nunca** usar `codigo_publico` sozinho como autenticação — a assinatura da URL é obrigatória em toda rota do participante
- ❌ **Nunca** expor `documento`, `documento_hash`, `id_externo`, `gateway`, `metadados` ou contadores internos nos props
- ❌ **Nunca** confiar em parâmetro do navegador para dizer que algo foi pago
- ❌ **Nunca** editar os 12 cenários Playwright da 5a para fazer os novos passarem
- ❌ **Nunca** escrever cor direto no componente; só tokens semânticos
- ❌ **Nunca** instalar componente shadcn que importe `reka-ui` (§3.7), nem dependência que `@vueuse/core`, Laravel ou Vue já resolvam
- ❌ **Nunca** entregar tela sem estado de carregamento e sem estado de erro
- ❌ **Nunca** implementar o painel (Fase 6) ou o provedor real (Fase 8)
- ❌ **Nunca** dar `git push` sem autorização explícita

---

## Execution Steps

1. **Linha do tempo e backend do acompanhamento.** `LinhaDoTempoDaInscricao` (os oito marcos de §3.3, ordenação, marco `atual` único), `InscricaoAcompanhamentoResource`, `PagamentoHistoricoResource`, `AcompanhamentoController@show`, a rota assinada e `tests/Feature/Participante/{AcompanhamentoTest,LinhaDoTempoTest}.php` — cobrindo os quatro finais possíveis (pendente, confirmada, expirada, cancelada), sem assinatura → 403 e ausência de dado sensível nos props. → commit `feat(participante): add registration tracking endpoint`

2. **A página do participante.** `Inscricoes/Acompanhar.vue` sobre `PublicoLayout`, com `ResumoDaInscricao`, `LinhaDoTempo`, `MarcoDaLinhaDoTempo` e `HistoricoDaCobranca`; tipos em `types/participante.ts`. Estado indicado por texto e ícone, nunca só por cor. Confirmar `npm run build` e `vue-tsc` sem erro novo. → commit `feat(participante): add registration tracking page`

3. **Segunda via do Pix.** `config/inscricoes.php`, `SegundaViaPagamentoController@store` (rota assinada + limite de tentativas, guardas de §3.6), botão na página de acompanhamento e `tests/Feature/Participante/SegundaViaTest.php` — reaproveita a pendente, emite quando não há nenhuma, recusa fora do prazo, recusa inscrição não-pendente. → commit `feat(pagamentos): add pix second copy on demand`

4. **Ligação entre as telas.** `PagamentoController@show` passa a expor `url_acompanhamento`; `Inscricoes/Pagamento.vue` ganha o link para o acompanhamento e, no estado expirado, o link para pedir novo acesso; `Eventos/Show.vue` ganha o link discreto de quem já se inscreveu. Rodar a suíte Pest inteira e a Playwright da 5a para provar que nada regrediu. → commit `feat(participante): link payment and tracking screens`

5. **Recuperação de acesso — backend.** `GeradorLinkDeAcesso` (validade de 7 dias por configuração), `EnviarLinkAcessoRequest`, `AcessoInscricaoController@create/@store` com limite por IP e por e-mail, `LinkDeAcessoInscricao` + `resources/views/emails/link-de-acesso.blade.php`, e `tests/Feature/Participante/RecuperarAcessoTest.php` com `Mail::fake()`: resposta idêntica nos dois casos, nenhum e-mail quando não há inscrição, link assinado que abre a página, cancelada de fora, limite de tentativas mantendo a resposta neutra. → commit `feat(participante): add access link recovery by email`

6. **Recuperação de acesso — tela.** `Inscricoes/RecuperarAcesso.vue`: um campo, mensagem neutra anunciada por `role="status"`, estados de carregamento e de erro. Conferir a mensagem chegando no Mailpit (`http://localhost:8025`) manualmente uma vez. → commit `feat(participante): add access recovery page`

7. **Playwright.** `acompanhamento.spec.ts` (pendente e confirmada, esta simulando o pagamento pelas rotas de `routes/dev.php`), `segunda-via-do-pix.spec.ts` e `recuperar-acesso.spec.ts` (mesma mensagem com e sem inscrição), mais o acesso sem assinatura → 403, reaproveitando `tests/e2e/{ambiente,semear,apoio,base}.ts`. Nenhum arquivo `.spec.ts` da 5a pode ser editado. → commit `test(e2e): add participant area scenarios`

8. **Acessibilidade, responsividade e fechamento.** Varredura de teclado, foco, rótulos, `aria-describedby`, semântica da lista da linha do tempo, contraste AA nos dois modos, alvos de 44 px e ausência de rolagem horizontal a 320 px. `pint`, `lint`, `vue-tsc`, suíte Pest e suíte Playwright completas. Atualizar `docs/PROGRESS.md` (etapa 12, decisões DA-06..DA-09 promovidas a D-44..D-47, Fase 5b marcada como concluída) e `docs/IMPLEMENTATION_PLAN.md`. → commit `feat(participante): polish accessibility and close phase 5b`

## Done

Quem se inscreveu tem um lugar próprio: abre um link assinado e vê, em linguagem simples, o que já aconteceu com a inscrição, o histórico da cobrança e — se ainda dá tempo — o Pix de volta na tela. Se fechou o navegador e perdeu o link, informa o e-mail, recebe a mensagem e volta. A suíte Pest continua verde, os 12 cenários Playwright da 5a seguem intactos e cinco novos cobrem os caminhos desta fase. Nenhuma regra de negócio foi criada, nenhuma tabela mudou e nenhum e-mail além do link de acesso foi enviado.

## Commit

```
feat(participante): add registration tracking endpoint
feat(participante): add registration tracking page
feat(pagamentos): add pix second copy on demand
feat(participante): link payment and tracking screens
feat(participante): add access link recovery by email
feat(participante): add access recovery page
test(e2e): add participant area scenarios
feat(participante): polish accessibility and close phase 5b
```
