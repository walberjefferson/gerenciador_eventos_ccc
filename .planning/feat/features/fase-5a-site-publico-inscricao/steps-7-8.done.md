# Execution Report — Fase 5a, steps 7 e 8

> **Plan:** fase-5a-site-publico-inscricao (execução parcial: steps 7 e 8)
> **Executed:** 2026-08-20
> **Status:** ✅ COMPLETE

## O que foi feito

### Step 7 — `8effed6` feat(pagamentos): add pix payment page with qr code and countdown

| Arquivo | Ação | Descrição |
|---|---|---|
| `app/Services/Pagamentos/GeradorQrCodePix.php` | create | copia e cola → SVG inline (bacon/bacon-qr-code), sem declaração XML e com `role="img"` + `aria-label` |
| `app/Http/Controllers/PagamentoController.php` | modify | `show` completo (valor, QR, copia e cola, prazo, estado, `url_situacao` assinada) + `situacao` (JSON enxuto) |
| `routes/web.php` | modify | `GET inscricoes/{codigo_publico}/situacao` → `inscricoes.situacao`, middleware `signed` |
| `resources/js/types/pagamento.ts` | create | `EstadoDaCobranca`, `CobrancaPix`, `PropsDaCobranca`, `SituacaoDaCobranca` |
| `resources/js/composables/useContadorRegressivo.ts` | create | tempo restante em português comum, nunca negativo |
| `resources/js/components/pagamento/QrCodePix.vue` | create | SVG embutido + legenda explicando o que fazer com ele |
| `resources/js/components/pagamento/CodigoCopiaECola.vue` | create | `useClipboard` (`legacy: true`), botão vermelho de 48 px, confirmação em três lugares |
| `resources/js/components/pagamento/ContadorRegressivo.vue` | create | amarelo com texto preto na última hora; emite `expirou` |
| `resources/js/pages/Inscricoes/Pagamento.vue` | create | os três estados + polling leve que para sozinho |
| `tests/Feature/Publico/PaginaPagamentoTest.php` | create | 9 testes |

**Decisões do step 7**

- **Quem escolhe a tela é o servidor.** A prop `estado` (`aguardando` \| `confirmada` \| `expirada`) é calculada em `PagamentoController::estado()` a partir do que o domínio já gravou: situação da inscrição, situação da cobrança e prazo vencido. O Vue só desenha. Nenhum parâmetro do cliente declara pagamento.
- **Prazo vencido conta como expirado na hora**, mesmo antes de a rotina de expiração passar — para quem está olhando a tela, já acabou. Isso é leitura, não escrita: nada é gravado.
- **Cobrança fechada não mostra mais como pagar.** `pix_copia_e_cola` e `qr_code_svg` só vão para a tela quando o estado é `aguardando`; nos outros dois vão como `null` (coberto por teste).
- **Polling leve, 5 s**, começa só se o estado inicial for `aguardando` e é interrompido em três situações: estado deixou de ser `aguardando`, resposta não-OK (link vencido — insistir não adiantaria) e `onBeforeUnmount`. Falha de rede não para: guarda o aviso discreto e tenta de novo.
- **Botão "Já paguei, conferir agora"** para quem não quer esperar o ciclo — chama a mesma consulta assinada, com estado de carregamento (`Conferindo...`).
- **O contador emite `expirou`** e a página aproveita para consultar o servidor: o fim do tempo vira uma pergunta ao domínio, não uma decisão da tela.
- **Rota de situação também assinada**, com a mesma validade da tela (`prazo_pagamento + 24h`), pelo mesmo motivo: `codigo_publico` não é senha.

### Step 8 — `db2676a` test(e2e): add happy path and payment confirmation

| Arquivo | Ação | Descrição |
|---|---|---|
| `playwright.config.ts` | create | projeto único `celular` (Pixel 5), `webServer` próprio, `globalSetup` |
| `tests/e2e/ambiente.ts` | create | todas as variáveis do servidor de teste, explícitas |
| `tests/e2e/apoio.ts` | create | `artisan`, `semearBanco`, gestos do formulário, `idExternoDaCobranca` |
| `tests/e2e/semear.ts` | create | `globalSetup`: `migrate:fresh --seed` |
| `tests/e2e/base.ts` | create | `test` estendido que corta pedidos para fora do servidor |
| `tests/e2e/caminho-feliz.spec.ts` | create | vitrine → 4 etapas → QR Code visível |
| `tests/e2e/confirmacao-do-pagamento.spec.ts` | create | simulação do pagamento → tela vira confirmada sem F5 |
| `.gitignore` | modify | artefatos do Playwright |

**Como a semeadura funciona (importante para os steps 9 e 10)**

- `globalSetup` roda **uma vez** `php artisan migrate:fresh --seed --force` com o ambiente de `tests/e2e/ambiente.ts`. Isso recria `CidadeSeeder` (São Paulo/Campinas/… com grupos) e `EventoDemoSeeder` (`copa-ccc-2026`, R$ 120,00, dia 1 esportes com bloco obrigatório de 1 a 2, dia 2 trilha opcional).
- O servidor sobe pelo próprio Playwright: `php artisan serve --host=127.0.0.1 --port=8123`, com `APP_ENV=testing` (é o que liga as rotas de simulação), `DB_DATABASE=testing`, `SESSION_DRIVER=file`, `CACHE_STORE=file`, `PAYMENT_FAKE_SIMULATION_ENABLED=true` e `PHP_CLI_SERVER_WORKERS=4`. O Dotenv do Laravel não sobrescreve variável já presente no processo, então essas vencem o arquivo de ambiente da máquina; usuário e senha do banco continuam vindo de lá.
- **A suíte usa o banco `testing`, o mesmo do Pest, e o recria.** Não rodar `npm run test:e2e` e `php artisan test` ao mesmo tempo. Está escrito no cabeçalho de `tests/e2e/ambiente.ts`.
- **Independência entre cenários:** um worker, sem paralelismo, e **cada spec usa um CPF e um e-mail próprios** (`11122233396` no caminho feliz, `44455566619` na confirmação). Nenhum depende do que o outro deixou. Para os steps 9-10 há `77788899941` livre; gerar mais com o mesmo algoritmo de `Cenario::cpfValido()`.
- **`tests/e2e/base.ts` corta a rede externa** (a fonte de `fonts.bunny.net`). Sem isso o `page.goto` esperava o `load` para sempre e os dois cenários estouravam o timeout — foi exatamente a primeira falha desta execução. **Todo spec novo deve importar `test`/`expect` de `./base`, não de `@playwright/test`.**
- **O identificador da cobrança no provedor simulado** não existe no navegador (e não deveria). `idExternoDaCobranca(codigo)` pergunta ao banco por `php artisan tinker --execute`, fora do navegador, e o teste então faz `POST /dev/pagamentos/{idExterno}/pagar` pelo `page.request`. As rotas de `routes/dev.php` ficam **fora do grupo `web`** (registradas em `PaymentServiceProvider`), portanto não exigem CSRF.
- **Seletores que valem para os próximos cenários:** o cartão de atividade é um `<label>` envolvendo um checkbox `sr-only` → clicar no `<label>` (`escolherAtividade`); o bloco de atividades é um `<fieldset>`/`<legend>` → `getByRole('group', { name: ... })`, **não** `heading`; as listas de cidade/grupo são do radix-vue → `getByLabel(...).click()` e depois `getByRole('option', ...)` (helper `escolherNaLista`).

## Critérios de qualidade

| Critério | Status | Evidência |
|---|---|---|
| URL da cobrança sem assinatura → 403 | ✅ | teste `recusa a tela da cobranca quando a URL nao esta assinada` |
| URL da situação sem assinatura → 403 | ✅ | teste `recusa a consulta de situacao quando a URL nao esta assinada` |
| Tela mostra valor, QR, copia e cola, prazo | ✅ | teste de props + e2e (`valor-da-cobranca` = `R$ 120,00`, `codigo-copia-e-cola` começa com `000201`) |
| QR Code é SVG inline | ✅ | prop `qr_code_svg` começa com `<svg`; no e2e, `qr-code-pix > svg` tem `role="img"` |
| Estado confirmado renderiza | ✅ | teste de props (`estado = confirmada`) + e2e (`cobranca-confirmada` aparece) |
| Estado expirado renderiza | ✅ | dois testes: cobrança expirada e prazo vencido antes da rotina |
| Confirmação sem F5 | ✅ | e2e: `expect(page.url()).toBe(enderecoDaCobranca)` depois da troca de estado |
| Polling para na confirmação/expiração | ✅ | `watch(estado)` chama `pararConsulta()`; resposta não-OK também para; `onBeforeUnmount` idem |
| Nada de regra nova no Vue | ✅ | a tela só lê `estado` vindo do servidor; nenhuma Action/Service/Enum/migration do domínio tocada |
| Sem dado sensível nos props | ✅ | teste `nao vaza documento nem hash do documento nos props` |
| Cor só por token semântico | ✅ | `bg-acao`, `bg-atencao`/`text-atencao-foreground` (preto sobre amarelo), `bg-sucesso`/`text-sucesso-texto`; nenhum hex |
| Contador nunca mostra negativo | ✅ | `Math.max(0, ...)` no cálculo e frase própria (`O prazo para pagar terminou`) |
| Alvos de toque ≥ 44 px | ✅ | `h-12` no botão de copiar, no "Já paguei" e nos botões dos outros estados |
| Anúncio da troca de estado | ✅ | `role="alert" aria-live="assertive"` único na página + `aria-live="polite"` no contador e na confirmação da cópia |
| Estado de carregamento e de erro | ✅ | `Conferindo...` no botão e a frase de falha em `estado-da-consulta` |
| Suíte Pest verde | ✅ | **205 passed (795 assertions)** — 196 anteriores + 9 novos |
| Sem dependência nova | ✅ | nada instalado; só `npx playwright install chromium` (binário do navegador, não pacote) |

## Verificação

| Comando | Resultado |
|---|---|
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | limpo (sem saída) |
| `npx vue-tsc --noEmit` | 20 erros, **todos pré-existentes do starter kit**; zero em arquivo da Fase 5a (filtro por `pagamento`/`Pagamento`/`useContador` não retorna nada) |
| `npm run build` | `✓ built in 1.49s` (`Pagamento-K-QZiWR1.js` 15.50 kB) |
| `php artisan test` | **205 passed (795 assertions)** |
| `npx playwright test` | **2 passed (9.3s)** — `caminho-feliz` 1.5s, `confirmacao-do-pagamento` 5.9s |

## Desvios do plano

1. **`tests/e2e/base.ts` não estava previsto no Output Format.** Foi necessário: `resources/views/app.blade.php` carrega a fonte de `fonts.bunny.net`, e sem internet o evento `load` da página nunca acontecia — os dois cenários morriam no `page.goto` por timeout. O `test` estendido corta qualquer pedido fora do próprio servidor, o que também torna a suíte imune à lentidão de rede alheia. A página continua idêntica; só a família tipográfica cai para a do sistema.
2. **`tests/e2e/ambiente.ts`, `apoio.ts` e `semear.ts` também são arquivos além dos nomeados** (`tests/e2e/*.spec.ts` + `playwright.config.ts`). Estão dentro de `tests/e2e/` e existem para que a semeadura seja determinística e os gestos do formulário fiquem escritos uma vez só.
3. **A suíte E2E usa o banco `testing`, compartilhado com o Pest**, em vez de um banco próprio. Criar um banco novo exigiria um `createdb` fora do que o plano autoriza. O risco (rodar as duas suítes ao mesmo tempo) está documentado no cabeçalho de `ambiente.ts` e aqui.
4. **`nome_completo` vai como prop da tela de cobrança.** É o próprio dado que a pessoa acabou de digitar, mostrado para ela confirmar que a cobrança é a dela. Não é dado sensível na acepção da proibição (`documento`, `documento_hash`, contadores).
5. **`prop `moeda`** vem do evento para o `Intl.NumberFormat`; o backend já guardava `moeda` no evento.
6. **`npx playwright install chromium` foi executado** (o handoff avisava que ainda não tinha rodado). É download de binário do navegador, não instalação de dependência do projeto — `package.json` não mudou.

## Pendências conhecidas (steps 9 e 10)

- Os **20 erros de `vue-tsc` do starter kit** continuam de pé (`AppHeader`, `AppSidebar`, `Welcome`, páginas de auth, `app.ts`). Fora do escopo desta fase; decisão para o step 10.
- Cenários que faltam (step 9): erro de validação (CPF inválido/campo vazio), esgotado, conflito de horário, máximo atingido e acesso sem assinatura. O evento semeado já serve aos três últimos: **Futebol 08:00-10:00 e Vôlei 09:00-11:00 se sobrepõem**, o bloco é de 1 a 2, e "Trilha longa" tem `idade_minima: 16`. Para o cenário "esgotado" será preciso ocupar as vagas antes (via `artisan` no próprio spec, como já se faz com `idExternoDaCobranca`).
- `docs/PROGRESS.md` e `docs/IMPLEMENTATION_PLAN.md` ainda não foram atualizados — é o step 10.

## Commits

- `8effed6` feat(pagamentos): add pix payment page with qr code and countdown
- `db2676a` test(e2e): add happy path and payment confirmation
