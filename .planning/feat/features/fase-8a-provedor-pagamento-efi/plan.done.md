# Execution Report — Fase 8a: provedor de pagamento real Efí (Pix)

> **Plan:** fase-8a-provedor-pagamento-efi
> **Executed:** 2026-08-27 (duas rodadas — ver "Desvios")
> **Branch:** `feat/fase-8a-efi`
> **Status:** ✅ COMPLETE

---

## Sobre esta execução ter precisado de duas rodadas

Vale dizer isso primeiro, porque quem ler o relatório vai ver o histórico de commits e notar.

A fase foi executada em **duas rodadas**. A primeira morreu por limite de chamadas de ferramenta no meio do step 7, com os steps 1 a 6 commitados e três arquivos de teste na árvore de trabalho. **Nada foi perdido**, e isso não é sorte: o plano mandava commitar ao fim de cada step exatamente para que a morte custasse zero, e custou. A segunda rodada leu os três arquivos, julgou o que estava pronto, terminou o step 7 e fez o step 8.

O único custo real foi de contexto: a segunda rodada precisou reconstruir, lendo os commits, o que a primeira tinha feito de verdade — e é por isso que a seção "O que foi feito" abaixo descreve **os commits**, não o que o plano pedia.

---

## What Was Done

### Step 1 — Configuração e credenciais · `11e0a5d`

| File | Action | Description |
|---|---|---|
| `composer.json` / `composer.lock` | modify | `efipay/sdk-php-apis-efi` — **a única dependência nova da fase** |
| `config/payments.php` | modify | bloco `efi` (+42 linhas), com o aviso explícito de que **nenhuma taxa comercial** entra ali e de que a única classe autorizada a ler o bloco é `ConfiguracaoEfi` |
| `.env.example` | modify | as seis variáveis, vazias e comentadas, cada uma dizendo para que serve e o que acontece se faltar |
| `.gitignore` | modify | pasta de certificados (`/storage/certificados`) |

As URLs base **não** foram para o ambiente: são constantes do provedor, derivadas de `EFI_ENVIRONMENT`. Deixá-las configuráveis seria permitir que uma linha errada mandasse cobrança de teste contra produção.

### Step 2 — `ConfiguracaoEfi` e `EfiClient` · `cc68889`

| File | Action | Description |
|---|---|---|
| `app/Services/Payments/Efi/ConfiguracaoEfi.php` | create | 170 linhas. **O único ponto do sistema que lê configuração da Efí** (DA-24). Sabe dizer se está completa, exige completude com erro claro, e resolve a URL base a partir do ambiente — com valor desconhecido caindo para homologação |
| `app/Services/Payments/Efi/EfiClient.php` | create | 312 linhas. **O único ponto que instancia o SDK** (C-12). Certificado, token com cache e margem de renovação, chave de cache por ambiente, tradução de erro |
| `app/Services/Payments/Efi/TraducaoDeStatus.php` | create | as constantes literais da Efí e a tradução para o vocabulário do domínio |
| `app/Exceptions/Payments/EfiException.php` | create | erro do provedor com código HTTP e identificador, mensagem em português, **sem credencial nem caminho de certificado no texto** |
| `tests/Feature/Pagamentos/Efi/EfiClientFake.php` | create | o duplo que a suíte usa no lugar do SDK |

### Step 3 — O contrato em lote e o desacoplamento da assinatura · `b9bfc57`

Este foi o step que mexeu em código que já existia e funcionava — o de maior risco da fase.

| File | Action | Description |
|---|---|---|
| `app/Contracts/Payments/PaymentGateway.php` | modify | `parseWebhook()` passou a devolver `list<WebhookResult>` (C-1/DA-17) e ganhou `webhookRequest()` (C-10) |
| `app/DTOs/Payments/WebhookRequestData.php` | modify | `fromRequestQuery()` — o caminho de leitura por query string (C-2) |
| `app/Http/Controllers/Webhooks/PaymentWebhookController.php` | modify | desdobra o lote em N registros e N jobs, cada um com o payload recortado; **deixou de citar `FakePaymentGateway`** |
| `app/Jobs/ProcessarWebhookPagamento.php` | modify | pega o primeiro item da lista (§3.4) **e** grava o `endToEndId` em `pagamentos.metadados` (C-4) |
| `app/Services/Payments/Fake/FakePaymentGateway.php` | modify | acompanha o contrato novo, **sem mudar o comportamento simulado** |
| `tests/Feature/Pagamentos/PaymentGatewayTest.php` | modify | ajuste ao contrato novo — fronteira, não domínio |

### Step 4 — `EfiPaymentGateway`, a cobrança · `c3d7bfd`

| File | Action | Description |
|---|---|---|
| `app/Services/Payments/Efi/EfiPaymentGateway.php` | create | 339 linhas: `name()`, `createPayment()` com ULID e a nova tentativa única no 409, `getPayment()`, `cancelPayment()`, `refundPayment()`, `webhookRequest()`, `verifyWebhookSignature()` e `parseWebhook()` |
| `app/Exceptions/Payments/EstornoNaoSuportadoException.php` | create | DA-18 — a recusa em voz alta |

**Desvio de recorte, sem consequência:** o plano previa a parte de webhook do gateway no step 5. Ela nasceu junto no step 4, porque estava no mesmo arquivo. O step 5 ficou sendo só a rota.

### Step 5 — O webhook da Efí · `9a82106`

| File | Action | Description |
|---|---|---|
| `app/Providers/PaymentServiceProvider.php` | modify | a rota alternativa com sufixo `/pix` (C-6) — a Efí acrescenta o sufixo sozinha ao notificar |

### Step 6 — Registro e diagnóstico · `ff9c36a`

| File | Action | Description |
|---|---|---|
| `app/Providers/PaymentServiceProvider.php` | modify | o braço `'efi'` no `match` |
| `app/Console/Commands/DiagnosticoEfi.php` | create | 206 linhas. `php artisan efi:diagnostico`, travado fora de `local`/`testing`, imprimindo passo a passo: certificado, token, cobrança, `pixCopiaECola` |

### Step 7 — A prova · `9b4beb9`

| File | Action | Description |
|---|---|---|
| `tests/Feature/Pagamentos/Efi/EfiPaymentGatewayTest.php` | create | 347 linhas · 24 testes: formato do identificador (mil gerações), conversão de valor, ausência de ponto flutuante, código Pix da própria resposta, nova tentativa única no 409, tradução de erro sem segredo, tradução de situação, cancelamento, estorno recusado, recusa sem credencial, URL base não configurável, e **o teste que percorre `app/` e falha se um segundo arquivo ler a configuração da Efí** |
| `tests/Feature/Pagamentos/Efi/EfiWebhookTest.php` | create | 287 linhas · 12 testes: confirmação ponta a ponta, `endToEndId` guardado, aviso com dois pagamentos gerando dois efeitos, recorte do payload por evento, sufixo `/pix`, assinatura errada com 200 e sem efeito, assinatura ausente, assinatura errando por um caractere, comparação em tempo constante, reentrega idempotente, cobrança desconhecida ignorada, e **falha nossa devolvendo 500** |
| `tests/Feature/Pagamentos/Efi/EfiClientFake.php` | modify | um ajuste de uma linha |

### Step 8 — Documentação e fechamento

| File | Action | Description |
|---|---|---|
| `docs/PAYMENTS.md` | modify | versão 2.0. Efí como provedor escolhido; o `match` deixou de ser comentário; contrato atualizado (lista + `webhookRequest`); seções **9** (a integração, as quatro peças, as decisões que a Efí impôs, o `endToEndId`, o que não faz, como provar) e **10** (Fase 8b e implantação). P-06 registrada como aberta |
| `docs/ARCHITECTURE.md` | modify | versão 1.2. Seção 7 com o aviso em lote e a distinção entre assinatura inválida e falha nossa; **8.2** (as duas fronteiras dentro da fronteira) e **8.3** (o roteiro de servidor: certificado, cadeia da Efí no servidor web, registro do endereço, ordem de ligar); §11.4 ampliada |
| `docs/PROGRESS.md` | modify | **Etapa 17**, decisões **DA-16 a DA-24**, **P-01 fechada**, **P-02 e P-06 abertas**, **Fase 8b pendente**, **LGPD ainda não feita**, SDK na tabela de dependências |
| `docs/IMPLEMENTATION_PLAN.md` | modify | versão 1.7. Fase 8 partida em **8a concluída** e **8b pendente**, com os números novos |
| `.planning/.../plan.done.md` | create | este relatório |

---

## Quality Criteria

### Fronteira e domínio

| Criterion | Status | Evidence |
|---|:--:|---|
| Nenhuma Action, Model ou Enum de domínio alterado | ✅ | `git diff --stat 34f455c HEAD -- app/Actions/ app/Models/ app/Enums/` → **saída vazia** |
| `SituacaoPagamento::deStatusExterno()` não tocado | ✅ | `git log 34f455c..HEAD -- app/Enums/SituacaoPagamento.php` → **vazio**. O vocabulário neutro dele já cobria o da Efí |
| `Efi` não aparece em Action, Model, Job ou Service de inscrição | ✅ | `grep -rln "Efi" app/Actions/ app/Models/ app/Jobs/ app/Services/Inscricoes/` → **vazio** |
| `PaymentWebhookController` não cita mais `FakePaymentGateway` | ✅ | `grep -n "FakePaymentGateway" app/Http/.../PaymentWebhookController.php` → **vazio** |

### Cobrança

| Criterion | Status | Evidence |
|---|:--:|---|
| `txid` casa com `^[a-zA-Z0-9]{26,35}$` — 1.000 gerações | ✅ | `it('gera mil identificadores e nenhum foge do formato nem se repete')` — 1.000 gerados, 1.000 únicos, todos no formato |
| Conversão correta em `5`, `99`, `12345`, `100000`, sem ponto flutuante | ✅ | 6 casos parametrizados (`0.05`, `0.99`, `123.45`, `1000.00`, `0.01`, `10.00`) + `grep -rn "(float)\|(double)\|floatval\|number_format" app/Services/Payments/Efi/` → **nenhum** |
| `pixPayload` preenchido a partir de `pixCopiaECola` | ✅ | `it('devolve o codigo pix copia e cola da propria resposta da cobranca')` |
| Nenhuma chamada a `/v2/loc/:id/qrcode` | ✅ | `grep -rin "qrcode" app/Services/Payments/Efi/` → **nenhuma** |
| `409 txid_duplicado` gera **uma** nova tentativa | ✅ | Dois testes: um prova que tenta de novo (`criacoes === 2`, txids diferentes); o outro prova que **desiste na segunda** (`criacoes === 2`, exceção) |
| `429` e erro de rede viram `EfiException` sem segredo no texto | ✅ | `it('traduz excesso de requisicoes e queda de rede sem vazar segredo na mensagem')` — assere que a mensagem **não** contém client id, client secret nem o segredo do webhook |
| `refundPayment()` lança `EstornoNaoSuportadoException` | ✅ | `it('recusa devolver dinheiro em voz alta...')` |

### Webhook

| Criterion | Status | Evidence |
|---|:--:|---|
| HMAC com `hash_equals`, nunca `==`/`===` | ✅ | Teste que lê o código e exige `hash_equals($segredo, $request->signature)` e a **ausência** das quatro formas de comparação comum entre os dois valores |
| Dois itens em `pix[]` → dois registros e dois jobs | ✅ | `it('nao perde dinheiro quando um unico aviso traz dois pagamentos')`: 2 registros, 2 processados, 2 pagamentos pagos, 2 inscrições confirmadas, `vagas_confirmadas === 2` |
| `endToEndId` gravado em `pagamentos.metadados`, sem migração | ✅ | `it('guarda o identificador da transferencia...')`. **Zero migrações na fase** |
| Rota responde no caminho configurado e no com `/pix` | ✅ | Suíte inteira usa o caminho puro; `it('recebe o aviso tambem no endereco com o sufixo...')` usa o com sufixo |
| D-18 preservada: assinatura inválida → 200, gravado como inválido, sem job | ✅ | Três testes: assinatura errada, assinatura ausente e assinatura errando por um caractere — todos 200, `assinatura_valida = false`, situação `Ignorado`, nenhum efeito |
| C-13: falha interna → resposta não-2XX | ✅ | `it('devolve erro quando a falha e nossa, para a efi reentregar o aviso')` — `assertStatus(500)` com o banco simulado fora do ar |
| Aviso repetido sem segundo efeito | ✅ | `it('confirma uma vez so quando a efi reentrega o mesmo aviso')` — 1 registro, `confirmada_em` idêntico, 1 pagamento pago, resposta `repetido: true` |

### Segurança

| Criterion | Status | Evidence |
|---|:--:|---|
| Nenhuma credencial, token ou certificado em log, exceção ou payload | ✅ | Assertivas de ausência nos testes de erro; e o teste do recorte do payload prova `chave === '[removido]'` no que fica guardado |
| `.gitignore` cobre certificados; nenhum `.p12`/`.pem` no repositório | ✅ | `.gitignore:25-29`; `git ls-files \| grep -Ei '\.(pem\|p12\|pfx\|key\|crt)$'` → **vazio** |
| Bloco `efi` sem taxa comercial | ✅ | `grep -in "taxa\|fee\|percent\|1.19" config/payments.php` → só as **duas linhas de aviso** proibindo taxas |
| `EfiClient`/configuração recusam operar sem certificado, com erro claro | ✅ | `it('recusa operar sem certificado e sem credencial, dizendo o que falta e sem revelar caminho')` — a mensagem cita `EFI_CERT_PATH` mas **não** o caminho real |
| **DA-24:** `config('payments.efi` só dentro de `ConfiguracaoEfi` | ✅ | `grep -rn "config('payments.efi" app/` → **8 ocorrências, todas em `ConfiguracaoEfi.php`**. Além disso, há teste que percorre `app/` e falha se aparecer um segundo arquivo |

### Testes

| Criterion | Status | Evidence |
|---|:--:|---|
| A suíte nova roda sem credencial e sem certificado | ✅ | As duas suítes usam `EfiClientFake`; nenhum `EFI_*` real no ambiente de teste |
| `php artisan efi:diagnostico` existe e é travado fora de `local`/`testing` | ✅ | `php artisan list \| grep efi` → `efi:diagnostico` |
| Os 452 testes Pest anteriores continuam verdes | ✅ | **488 passed (3455 assertions)** — os 452 anteriores mais 36 novos, nenhum vermelho |
| Os 32 cenários Playwright verdes, **sem edição** | ✅ | **32 passed (46.4s)**; `git status --short tests/e2e/` → **vazio** |
| `pint --test` · `npm run lint` · `vue-tsc --noEmit` · `composer audit` | ✅ | Todos limpos — saídas na tabela abaixo |

---

## Verification

| Command | Result |
|---|---|
| `php artisan test` | **488 passed (3455 assertions)** · 55.70s — base da fase: 452 / 2334 → **+36 testes, +1.121 asserções** |
| `php artisan test tests/Feature/Pagamentos/Efi/` | 36 passed (1113 assertions) · 1.40s |
| `npm run test:e2e` | **32 passed** (46.4s) — os mesmos 32, nenhum editado |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | sem saída (limpo) |
| `npx vue-tsc --noEmit` | sem saída (zero erros) |
| `composer audit` | `No security vulnerability advisories found.` |

Os testes rodaram **no host**, contra o PostgreSQL de verdade na porta **55432** (decisões D-19 e D-20) — `phpunit.xml` linhas 29-32: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=55432`, `DB_DATABASE=testing`.

---

## Deviations from Plan

1. **Duas rodadas.** A primeira morreu por limite de chamadas no meio do step 7. Os steps 1-6 já estavam commitados e os arquivos de teste estavam na árvore de trabalho — nada perdido. Foi exatamente o cenário que o §6 do plano previu ("Executores morrem em ~60 chamadas de ferramenta. Commite ao fim de cada step").

2. **Um teste da primeira rodada estava errado e foi corrigido, não o código.** O teste de comparação em tempo constante assertava que a string `'$segredo ==='` não aparecia no gateway. Ela aparece — em `if ($segredo === '' || ...)`, que é a **guarda de segredo vazio**, não a comparação de HMAC. A comparação de HMAC de verdade é `hash_equals($segredo, $request->signature)` e sempre foi. A assertiva era ampla demais e teria acusado código correto para sempre. Foi trocada por quatro assertivas precisas (as quatro formas de comparar **o segredo com a assinatura** por igualdade comum) mais a exigência da chamada exata a `hash_equals`. **Nenhuma linha de código de produção mudou por causa disso**, e foi acrescentado um teste comportamental de assinatura errando por um caractere.

3. **Recorte entre os steps 4 e 5 saiu diferente do plano.** O plano punha `verifyWebhookSignature()`/`parseWebhook()` no step 5; eles nasceram no step 4, porque moram no mesmo arquivo que o resto do gateway. O step 5 ficou sendo apenas a rota com sufixo `/pix`. Mesma entrega, fronteira de commit diferente.

4. **`tests/Feature/Pagamentos/WebhookPagamentoTest.php` não foi modificado**, embora o plano o listasse como "modify". Ele continuou verde sem ajuste: a mudança de contrato ficou contida no controller e no job, e o que aquele teste exercita — o comportamento observável do aviso — não mudou. **Não modificar teste que não precisava mudar é o resultado melhor**, não um atalho: se ele tivesse precisado de ajuste, seria sinal de que a mudança vazou mais do que devia.

5. **O caminho do relatório mudou.** O plano dizia `.planning/feat/features/fase-8-provedor-pagamento-efi/plan.done.md`; o diretório real é `fase-8a-...`, coerente com a fase ter sido partida em 8a e 8b.

6. **O arquivo `Prompt para Claude Code — ...md`, sem rastreamento na raiz, não foi tocado nem commitado.** É o briefing original, fora do escopo, e já estava assim antes da fase começar.

---

## O que ficou por fazer (de propósito, e o motivo)

- **Estorno.** `refundPayment()` lança "não suportado" (DA-18). Depende da **P-02**. O `endToEndId` que ele vai exigir **já está sendo guardado**, então a decisão não vai chegar com passivo.
- **Nada foi ligado contra dinheiro de verdade** (DA-19). Não há ambiente publicado. Certificado, cadeia de certificados no servidor web, HTTPS válido e o registro do endereço do aviso são **tarefas de implantação** — roteiro na seção 8.3 de `docs/ARCHITECTURE.md`, com a ordem de ligar.
- **A P-06 continua aberta.** A taxa da Efí registrada é o valor público da página de tarifas, não proposta escrita. Não bloqueou nada: nenhuma taxa entra em código (DA-23).
- **Fase 8b.** Credenciais pela tela, vindas do banco. Por causa da DA-24, muda o corpo de `ConfiguracaoEfi` e mais nada.
- **LGPD continua sem ser feita.** Fora do escopo desta fase, como das anteriores. Presa à **P-04** e à **P-03**.
- **Cobrança com vencimento (`cobv`), split, Pix enviado, Pix Automático e cartão:** fora de escopo, como o plano definiu.

---

## Commit

| Step | Message | Hash |
|---|---|---|
| 1 | `chore(pagamentos): add efi sdk and configuration` | `11e0a5d` |
| 2 | `feat(pagamentos): add isolated efi client` | `cc68889` |
| 3 | `refactor(pagamentos): parse webhooks in batch` | `b9bfc57` |
| 4 | `feat(pagamentos): add efi charge creation` | `c3d7bfd` |
| 5 | `feat(pagamentos): add efi webhook handling` | `9a82106` |
| 6 | `feat(pagamentos): register efi gateway and diagnostics` | `ff9c36a` |
| 7 | `test(pagamentos): prove efi integration` | `9b4beb9` |
| 8 | `docs(pagamentos): close phase 8a` | este commit — o relatório não fixa o próprio hash, porque escrevê-lo aqui exigiria reescrever o commit que acabou de citá-lo |
