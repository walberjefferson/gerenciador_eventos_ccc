# Execution Report — Fase 5a: site público do evento, inscrição e pagamento Pix

> **Plan:** fase-5a-site-publico-inscricao
> **Executed:** 2026-08-20 (steps 1-10, em quatro execuções; esta cobre os steps 9 e 10 e fecha o plano)
> **Status:** ✅ COMPLETE

---

## 1. O que foi construído (steps 1 a 10)

Um visitante abre `/eventos/copa-ccc-2026` no celular, entende os dois dias de programação, se
inscreve em quatro etapas com as regras de escolha explicadas na própria tela, e termina diante de
um QR Code Pix que consegue pagar. Quando o pagamento chega, a tela vira "inscrição confirmada"
sozinha. Se fechar o navegador, volta pelo link assinado.

### Backend (fino, sem regra nova)

| Arquivo | Ação | Descrição |
|---|---|---|
| `app/Http/Controllers/EventoPublicoController.php` | create | `show` do evento por slug; rascunho e cancelado → 404 |
| `app/Http/Controllers/InscricaoPublicaController.php` | create | `create` do formulário, com evento, dias, grupos, atividades, cidades e grupos de participantes |
| `app/Http/Controllers/PagamentoController.php` | create | `show` (assinada) e `situacao` (JSON enxuto, assinada) |
| `app/Http/Controllers/InscricaoController.php` | modify | negocia a resposta: Inertia → redirecionamento assinado; qualquer outro chamador → o mesmo JSON de sempre |
| `app/Http/Resources/*.php` (7 arquivos) | create | props sem `documento`, sem `documento_hash`, sem contador cru e sem `configuracoes` |
| `app/Services/Pagamentos/GeradorQrCodePix.php` | create | copia e cola → SVG embutido, com `role="img"` e `aria-label` |
| `routes/web.php` | modify | rotas públicas e as duas rotas atrás do middleware `signed` |
| `composer.json` | modify | `bacon/bacon-qr-code` |

### Frontend

| Arquivo | Ação | Descrição |
|---|---|---|
| `resources/css/app.css` | modify | tokens semânticos do CCC (ação, sucesso, informação, atenção), claro e escuro |
| `tailwind.config.js` | modify | as famílias `acao`, `sucesso`, `informacao`, `atencao` |
| `resources/js/layouts/PublicoLayout.vue` | create | cabeçalho com a logo, atalho "Ir direto para o conteúdo", rodapé de contato |
| `resources/js/pages/Eventos/Show.vue` | create | vitrine do evento |
| `resources/js/pages/Inscricoes/Criar.vue` | create | formulário de quatro etapas, envio único, tratamento do 422 |
| `resources/js/pages/Inscricoes/Pagamento.vue` | create | cobrança, confirmada e expirada |
| `resources/js/components/eventos/*` | create | `CabecalhoEvento`, `ProgramacaoDoDia`, `ResumoDeVagas` |
| `resources/js/components/inscricao/*` | create | `IndicadorDePassos`, `PassoDadosPessoais`, `PassoParticipacao`, `PassoRevisao`, `GrupoDeAtividades`, `CartaoDeAtividade` |
| `resources/js/components/pagamento/*` | create | `QrCodePix`, `CodigoCopiaECola`, `ContadorRegressivo` |
| `resources/js/composables/*` | create | `useSelecaoAtividades`, `useGruposDaCidade`, `useContadorRegressivo` |
| `resources/js/types/*` | create | tipos dos props vindos do Inertia |
| `resources/js/components/ui/*` | create | apenas os componentes shadcn ausentes, todos importando `radix-vue` |

### Testes

| Arquivo | Ação | Descrição |
|---|---|---|
| `tests/Feature/Publico/EventoPublicoTest.php` | create | props corretos, `vagas_disponiveis` calculado, rascunho → 404 |
| `tests/Feature/Publico/PaginaPagamentoTest.php` | create | 9 testes: estados da cobrança, URL sem assinatura → 403, ausência de dado sensível |
| `playwright.config.ts`, `tests/e2e/{ambiente,apoio,base,semear}.ts` | create | infraestrutura determinística: banco recriado, servidor próprio na porta 8123, rede externa cortada |
| `tests/e2e/*.spec.ts` (8 arquivos) | create | 12 cenários |

### Steps 9 e 10 em detalhe (esta execução)

**Step 9 — `3f3e2c0` `test(e2e): add validation, capacity and conflict scenarios`**

| Arquivo | Ação | Descrição |
|---|---|---|
| `tests/e2e/validacao-do-formulario.spec.ts` | create | 2 cenários: o aviso da própria tela (e-mail vazio + CPF incompleto) e a recusa do servidor (CPF de onze dígitos impossível) devolvendo o participante ao passo 1 com o campo em foco |
| `tests/e2e/capacidade-esgotada.spec.ts` | create | "Trilha leve" com capacidade zero: aparece `Esgotado`, a caixa fica desabilitada e o toque forçado não seleciona |
| `tests/e2e/conflito-de-horario.spec.ts` | create | Futebol 08h-10h trava o Vôlei 09h-11h **pelo nome**; Handebol 10h-12h, que só encosta, continua livre |
| `tests/e2e/maximo-de-selecoes.spec.ts` | create | contador `2 de 2 selecionadas`, demais opções desabilitadas com a frase "Desmarque uma para trocar", e a liberação ao desmarcar |
| `tests/e2e/acesso-indevido.spec.ts` | create | cobrança real: sem assinatura → 403, assinatura adulterada → 403, `/situacao` sem assinatura → 403 |
| `tests/e2e/apoio.ts` | modify | `definirCapacidadeDaAtividade`, para montar o cenário de esgotado sem inventar dezenas de inscrições |

**Step 10 — `806ab4b` `feat(publico): polish accessibility and responsive behavior`**

| Arquivo | Ação | Descrição |
|---|---|---|
| `resources/css/app.css` | modify | `--destructive` do modo claro escurecido de `#EF4444` (3.76:1, reprova) para `#D31212` (5.43:1) — é a cor das mensagens de erro que o participante lê |
| `resources/js/components/inscricao/PassoRevisao.vue` | modify | a caixa de aceite do regulamento continua com 24 px, mas ganhou 44 px de área que responde ao dedo, e o rótulo ao lado passou a ter 44 px de altura |
| `tests/e2e/acessibilidade-e-responsividade.spec.ts` | create | 4 cenários que medem, no navegador: alvos de toque, rolagem horizontal a 320 px, varredura de teclado com foco visível, anúncio da troca de etapa e marcação de atividade só pelo teclado |
| `docs/PROGRESS.md` | modify | Etapa 11, decisões D-36 a D-42, P-05 resolvida, Fase 5a concluída e Fase 5b descrita |
| `docs/IMPLEMENTATION_PLAN.md` | modify | estado real, Fase 5 dividida em 5a (✅) e 5b (❌) |

---

## 2. Critérios de qualidade (§5 do plano), um a um

### Funcional

| Critério | Status | Evidência real |
|---|---|---|
| `/eventos/{slug}` exibe nome, banner, descrição, datas, programação, valor, vagas e regulamento | ✅ | `EventoPublicoTest` (props) + e2e `caminho-feliz`: `heading` nível 1 com "Copa CCC 2026", `R$ 120,00`, `heading` "Programação", "Futebol" visível |
| Evento em `rascunho` ou `cancelado` → 404; inscrições fechadas exibem o motivo | ✅ | `EventoPublicoTest` (dois testes de 404 e o estado de inscrições fechadas) |
| Grupo de participante só lista os da cidade escolhida | ✅ | `useGruposDaCidade`; e2e: `#grupo_participante_id` está `disabled` enquanto não há cidade (`acessibilidade-e-responsividade`, cenário do teclado) |
| Atividade esgotada aparece como `Esgotado` e não é selecionável | ✅ | e2e `capacidade-esgotada`: badge visível, `toBeDisabled()`, clique forçado não marca, contador continua `0 de 1 selecionadas` |
| Conflito de horário exibe **qual** atividade conflita, pelo nome | ✅ | e2e `conflito-de-horario`: `toContainText('conflito de horário com Futebol')` e o mesmo texto no elemento apontado por `aria-describedby` |
| Atingido o máximo, aparece `2 de 2 selecionadas` e as demais ficam desabilitadas até liberar uma | ✅ | e2e `maximo-de-selecoes`: `toHaveText('2 de 2 selecionadas')`, Basquete `toBeDisabled()`, e volta a `toBeEnabled()` depois de desmarcar o Handebol |
| Erro 422 devolve o participante ao passo do campo, com o campo destacado | ✅ | e2e `validacao-do-formulario`, cenário 2: depois do envio, `heading` "Seus dados" visível e `expect(page.locator('#documento')).toBeFocused()` |
| Tela de pagamento mostra valor, QR Code, copia e cola, botão copiar, prazo e contador | ✅ | e2e `caminho-feliz`: `valor-da-cobranca` = `R$ 120,00`, `qr-code-pix > svg[role=img]`, `codigo-copia-e-cola` começa com `000201`, `contador-regressivo` contém "para pagar", botão "Copiar código Pix" |
| Pagamento confirmado troca a tela sem F5; prazo vencido mostra a tela de expirada | ✅ | e2e `confirmacao-do-pagamento`: `cobranca-confirmada` aparece e `page.url()` é idêntica à de antes. Expirada: dois testes em `PaginaPagamentoTest` |
| URL da cobrança sem assinatura válida → 403 | ✅ | e2e `acesso-indevido`: três asserções de `status() === 403` (sem assinatura, assinatura adulterada, `/situacao`) + navegação do próprio navegador retornando 403 |

### Qualidade

| Critério | Status | Evidência real |
|---|---|---|
| `vendor/bin/pint --test` limpo | ✅ | `{"tool":"pint","result":"passed"}` |
| `npm run lint` limpo | ✅ | sem saída |
| `vue-tsc` sem erro | ⚠️ | **20 erros, todos pré-existentes do pacote inicial** (`app.ts`, `AppHeader`, `AppSidebar`, `AppSidebarHeader`, `NavMain`, `NavUser`, `TextLink`, `UserInfo`, `AuthSplitLayout`, `Welcome`, `auth/Login`, `auth/Register`, `settings/Profile`). **Zero** em arquivo da Fase 5a — ver caveat 1 |
| Os testes existentes continuam verdes | ✅ | `php artisan test` → **205 passed (795 assertions)**. Eram 177 ao fim da Fase 4; os 28 novos são os testes desta fase, e nenhum dos 177 precisou de ajuste |
| Nenhum dado sensível nos props do Inertia | ✅ | teste `nao vaza documento nem hash do documento nos props` em `PaginaPagamentoTest` e as asserções de props em `EventoPublicoTest` |
| Nenhuma regra de negócio nova no Vue; nenhuma alteração em Action, Service ou migration do domínio | ✅ | `git diff 6530b2c..HEAD --stat -- app/Actions app/Services/Inscricoes database/migrations app/Enums` → vazio. O único `app/Services` novo é `Pagamentos/GeradorQrCodePix`, que desenha um SVG |
| Cores só via tokens semânticos | ✅ | `grep -rn "#[0-9a-fA-F]\{6\}" resources/js/{pages,components/eventos,components/inscricao,components/pagamento,layouts}` → nada. Os hex vivem só em `resources/css/app.css`, com o contraste medido ao lado |
| Nenhuma dependência além de `bacon/bacon-qr-code` e Playwright | ✅ | as duas estão registradas na nova tabela "Dependências externas adicionadas" do `PROGRESS.md` |

### Acessibilidade e mobile

| Critério | Status | Evidência real |
|---|---|---|
| Formulário navegável por teclado, com foco sempre visível | ✅ | e2e: 25 `Tab` seguidos; todos os campos do passo 1 alcançados, nenhum elemento recebeu foco sem anel (`outline` ou `box-shadow`), nenhuma armadilha. O primeiro alvo da página é o atalho "Ir direto para o conteúdo". Marcar e desmarcar atividade só com `Space` também é testado |
| Todo campo tem `<label>`; erros ligados por `aria-describedby` e anunciados com `role="alert"` | ✅ | `PassoDadosPessoais` tem `<Label for>` nos sete campos; e2e verifica `aria-invalid="true"` e `aria-describedby="erro-email"` / `erro-documento`, e localiza as mensagens por `getByRole('alert')` |
| Mudança de passo anunciada para leitor de tela | ✅ | e2e: a região `[aria-live="polite"][role="status"].sr-only` passa a "Etapa Sua participação.", depois "Etapa Revisão.", e volta ao voltar; e o foco vai para o título da etapa nova |
| Contraste WCAG AA nos modos claro e escuro | ✅ | medição sobre os tokens reais de `app.css`. **Claro:** ação 5.68:1, sucesso 4.57:1, informação 4.76:1, atenção 4.76:1, erro 5.43:1, texto 19.80:1, texto esmaecido 4.74:1; texto **preto** sobre verde 5.00:1, sobre azul 5.27:1, sobre amarelo 14.20:1 e 17.86:1; branco sobre vermelho 5.68:1. **Escuro:** ação `#DB3F47` 4.54:1, sucesso 4.72:1, informação 4.97:1, atenção 13.39:1, erro 5.24:1, texto 18.97:1, esmaecido 7.85:1 |
| Alvos de toque com no mínimo 44×44 px | ✅ | e2e mede, no navegador, todo `button`, `a[href]`, `label` com caixa de marcar e `[role=button]` da vitrine, dos três passos e da tela de cobrança → lista de violações vazia. Duas exceções declaradas no código: link escrito no meio de um parágrafo (exceção de elemento em linha da WCAG) e o atalho de leitor de tela, que só existe quando recebe foco |
| Layout sem rolagem horizontal a partir de 320 px | ✅ | e2e com janela de 320×720: `scrollWidth - clientWidth <= 0` na vitrine, nos passos 1, 2 e 3 e na tela da cobrança |

### Playwright E2E

| Cenário exigido | Status | Arquivo |
|---|---|---|
| Caminho feliz | ✅ | `caminho-feliz.spec.ts` |
| Erro de validação | ✅ | `validacao-do-formulario.spec.ts` (2 cenários) |
| Esgotado | ✅ | `capacidade-esgotada.spec.ts` |
| Conflito de horário | ✅ | `conflito-de-horario.spec.ts` |
| Máximo atingido | ✅ | `maximo-de-selecoes.spec.ts` |
| Confirmação do pagamento | ✅ | `confirmacao-do-pagamento.spec.ts` |
| Acesso indevido | ✅ | `acesso-indevido.spec.ts` |
| Banco semeado de forma determinística, sem ordem entre testes | ✅ | `globalSetup` roda `migrate:fresh --seed` uma vez; um worker; **cada cenário usa o seu próprio CPF e e-mail**; o único que muda dado compartilhado (capacidade da "Trilha leve") devolve o valor original em `afterAll` |
| Extra — acessibilidade e responsividade | ✅ | `acessibilidade-e-responsividade.spec.ts` (4 cenários) |

---

## 3. Verificação (comandos e saída real)

| Comando | Resultado |
|---|---|
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | limpo, sem saída |
| `npm run build` | `✓ built in 1.55s` (`Criar-DjS5DPRd.js` 32.13 kB, `Pagamento-B57kGtzH.js` 15.50 kB) |
| `npx vue-tsc --noEmit` | 20 erros, **todos** em arquivos do pacote inicial; nenhum em arquivo da Fase 5a |
| `php artisan test` | **205 passed (795 assertions)** em 9.15s |
| `npx playwright test` | **12 passed (18.7s)**, projeto `celular` (Pixel 5) |

---

## 4. Desvios do plano

1. **`tests/e2e/base.ts`, `ambiente.ts`, `apoio.ts` e `semear.ts` não estavam no Output Format** (que nomeava só `tests/e2e/*.spec.ts` e `playwright.config.ts`). O `base.ts` foi necessário: `app.blade.php` carrega uma fonte de `fonts.bunny.net` e, sem internet, o `load` da página nunca acontecia. Os outros três existem para que a semeadura seja determinística e os gestos do formulário fiquem escritos uma vez só.
2. **`tests/e2e/acessibilidade-e-responsividade.spec.ts` é um cenário além dos sete exigidos.** Sem ele, os critérios de acessibilidade do step 10 seriam afirmação minha, não medição. Ele mede no navegador e falha se alguém regredir.
3. **Dois commits no step 10 em vez de um**, pelo risco de o executor ser interrompido: o código e a varredura em `806ab4b`, a documentação e este relatório no commit seguinte. A mensagem exigida pelo plano (`feat(publico): polish accessibility and responsive behavior`) está no commit do código.
4. **`--destructive` do pacote inicial foi alterado** (só no modo claro). É token, não componente, e a mudança era obrigatória: o tom original reprova em contraste e é justamente a cor de toda mensagem de erro do formulário. Ela melhora também as telas administrativas do pacote inicial, que usam o mesmo token.
5. **A suíte E2E usa o banco `testing`, compartilhado com o Pest.** Criar um banco próprio exigiria um passo de instalação fora do que o plano autoriza. O risco está no cabeçalho de `tests/e2e/ambiente.ts` e na decisão D-42.
6. **`nome_completo` vai como prop da tela de cobrança** — é o dado que a pessoa acabou de digitar, mostrado para ela conferir que a cobrança é a dela. Não é dado sensível na acepção da proibição.

---

## 5. Ressalvas (caveats)

1. **Vinte erros de `vue-tsc` continuam de pé, todos do pacote inicial.** Estão em `app.ts`, `AppHeader`, `AppSidebar`, `AppSidebarHeader`, `NavMain`, `NavUser`, `TextLink`, `UserInfo`, `AuthSplitLayout`, `Welcome`, `auth/Login`, `auth/Register` e `settings/Profile`. **Nenhum em arquivo da Fase 5a.** Não foram corrigidos porque o plano proíbe reescrever componente que funciona e o escopo é o site público.
2. **O `tsconfig` estava quebrado desde a criação do projeto e a checagem de tipos vinha sendo abortada em silêncio.** Foi consertado durante a Fase 5a — e foi o conserto que fez os 20 erros aparecerem. Ou seja: eles não são novos; eram invisíveis. Vale uma etapa própria para limpá-los antes da Fase 6, que vai mexer justamente no painel administrativo onde eles moram.
3. **Nenhum cenário Playwright foi pulado.** Os 12 rodam e passam.
4. **Três cenários precisaram de `click({ force: true })`.** O Playwright se recusa a clicar num rótulo cujo controle está desabilitado — e o objetivo do teste é exatamente provar que, mesmo com o toque forçado, nada é selecionado. Está comentado no código.
5. **Sem Vitest.** As regras do `useSelecaoAtividades` são cobertas pelos cenários do Playwright e pelos 79 testes de domínio do backend. Se a Fase 5b precisar testar lógica pura isolada, é a hora de reabrir essa decisão.
6. **A tela não avisa quando uma vaga acaba enquanto a pessoa preenche** (decisão D-39). A autoridade é a revalidação no envio, que devolve 422 em linguagem simples e leva o participante de volta ao passo certo.

---

## 6. O que o executor da Fase 5b precisa saber

1. **O link assinado é a única porta.** `URL::temporarySignedRoute`, validade igual ao `prazo_pagamento` mais 24 horas de folga — para o participante ainda ver "prazo vencido" em vez de um 403 sem explicação. `codigo_publico` **nunca** vale como autenticação sozinho. A área do participante tem que nascer com a mesma regra.
2. **Quem decide o estado da tela é o servidor.** `PagamentoController::estado()` devolve `aguardando`, `confirmada` ou `expirada` a partir do que o domínio gravou. A linha do tempo da 5b deve seguir o mesmo caminho: nenhum parâmetro vindo do navegador declara fato.
3. **A consulta de situação já existe e é assinada:** `GET /inscricoes/{codigo_publico}/situacao`, JSON enxuto (`situacao`, `situacao_rotulo`, `pago_em`). A 5b pode reaproveitá-la em vez de criar outra.
4. **Os Resources são a fronteira do que sai.** Nada de `documento`, `documento_hash`, contador cru ou `configuracoes`. Se a linha do tempo precisar de um campo novo, ele entra pelo Resource, com teste provando o que **não** aparece.
5. **Cor só por token semântico** (`bg-acao`, `text-sucesso-texto`, `bg-atencao` com `text-atencao-foreground`). Nenhum hex em componente. Cor nova precisa ser derivada e medida — o script de medição está descrito no critério de contraste acima.
6. **Todo cenário Playwright novo importa `test`/`expect` de `./base`**, nunca de `@playwright/test`: o `base` corta a rede externa e sem ele o `goto` fica esperando o `load` para sempre.
7. **Cada cenário usa o seu CPF.** Já foram consumidos: `11122233396`, `44455566619`, `77788899941`, `22233344405`, `55566677720`, `88899900078`, `32165498791`, `12345678909` e `45678912364` (estes dois últimos gerados e ainda livres). Gerar mais com o mesmo algoritmo dos dois dígitos verificadores.
8. **Seletores que valem para as telas novas:** o cartão de atividade é um `<label>` envolvendo um `checkbox` `sr-only`; o bloco é `<fieldset>`/`<legend>` → `getByRole('group')`, nunca `heading`; as listas são do **`radix-vue`** → `getByLabel(...).click()` e depois `getByRole('option', ...)`.
9. **Não rode `npm run test:e2e` e `php artisan test` ao mesmo tempo**: as duas suítes usam o banco `testing` e a de ponta a ponta o recria.
10. **O envio de e-mail é Fase 7.** Os três anúncios internos (`InscricaoCriada`, `InscricaoConfirmada`, `InscricaoExpirada`) já são disparados pelo domínio e continuam **sem nenhum ouvinte**. A 5b não deve plugar nenhum.

---

## 7. Commits

| Hash | Mensagem |
|---|---|
| — | `chore(publico): add public layout, qr code lib and playwright` |
| — | `feat(publico): add public event page endpoint` |
| — | `feat(publico): add event showcase page` |
| — | `feat(inscricao): add activity selection rules composable` |
| — | `feat(inscricao): add personal data and participation steps` |
| — | `feat(inscricao): add review step and signed redirect to payment` |
| `8effed6` | `feat(pagamentos): add pix payment page with qr code and countdown` |
| `db2676a` | `test(e2e): add happy path and payment confirmation` |
| `3f3e2c0` | `test(e2e): add validation, capacity and conflict scenarios` |
| `806ab4b` | `feat(publico): polish accessibility and responsive behavior` |
| (este) | `docs(publico): record fase 5a completion` |
