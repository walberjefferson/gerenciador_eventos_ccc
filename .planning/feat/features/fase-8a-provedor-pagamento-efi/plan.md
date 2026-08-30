# Action Plan — Fase 8a: provedor de pagamento real Efí (Pix)

> **Type:** feature
> **Created:** 2026-08-27
> **Status:** pending
> **Ordem:** primeiro dos dois planos da Fase 8. Depende de todas as fases anteriores, que
> já estão fechadas. **A Fase 8b** (`fase-8b-credenciais-pela-interface`) vem depois e
> troca a fonte da configuração do `.env` para o banco, com tela de cadastro.

---

## 1. Persona & Scope

**Persona:** Senior Backend Engineer **Laravel 12 + PHP 8.4**, com prática em integração
com instituição financeira: mTLS, OAuth2 `client_credentials`, webhook idempotente e
tradução de vocabulário de provedor. Sabe que a fronteira com o fornecedor é o único lugar
onde o nome dele pode aparecer, e trata dinheiro de verdade com a desconfiança que ele
merece.

**Scope — Fase 8:** trocar o provedor simulado por dinheiro de verdade **sem que nenhuma
regra de inscrição perceba a diferença**.

| Entrega | Nesta fase |
|---------|:----------:|
| `EfiPaymentGateway` implementando `PaymentGateway` | ✅ |
| Autenticação mTLS + OAuth2 com cache do token | ✅ |
| Cobrança imediata (`cob`) com `pixCopiaECola` | ✅ |
| Webhook real: validação, tradução e persistência do `endToEndId` | ✅ |
| Contrato de webhook em **lote** (§3.3, C-1) | ✅ |
| Comando de diagnóstico contra homologação | ✅ |
| Roteiro de infraestrutura (nginx/mTLS) em documento | ✅ |
| Configuração vinda do **`.env`**, atrás de um ponto único de leitura (DA-24) | ✅ |
| **Tela de cadastro de credenciais e certificado** | ❌ **Fase 8b** — mas o terreno é preparado aqui (DA-24) |
| **Devolução (estorno) Pix** | ❌ **fora do escopo** — depende da **P-02** (§3.1) |
| Cobrança com vencimento (`cobv`) | ❌ fora do escopo (§3.2) |
| Split, Pix enviado, Pix Automático, cartão | ❌ fora do escopo |
| Registro efetivo do webhook na Efí | ❌ **tarefa de implantação**, não de código (§3.1) |
| LGPD, credenciamento, lista de espera | ❌ fora do escopo, como nas fases anteriores |

**Stack:** PHP 8.4 · Laravel 12 · PostgreSQL 18 · Redis · Pest 4 · SDK
`efipay/sdk-php-apis-efi` ^1.19 (a única dependência nova desta fase — §3.1).

---

## 2. Direct Objective

Fazer com que `PAYMENT_GATEWAY=efi` no `.env` seja **a única mudança necessária** para o
sistema passar a cobrar de verdade: a mesma inscrição, o mesmo prazo, o mesmo QR Code na
tela, o mesmo e-mail e a mesma confirmação automática — com o dinheiro entrando na conta
da Efí em vez de num arquivo em `storage/`.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas — **NÃO reabrir**

| # | Tema | Decisão | Origem |
|---|------|---------|--------|
| **DA-16** | Provedor | **Efí**, API Pix. Encerra a pendência **P-01** | dono do produto, 2026-08-27 |
| **DA-17** | Webhook em lote | `parseWebhook()` passa a devolver **`list<WebhookResult>`**. O aviso da Efí é um array e pode trazer vários pagamentos num POST só; devolver apenas o primeiro perderia dinheiro em silêncio | entrevista |
| **DA-18** | Estorno | **Fora desta fase.** `refundPayment()` lança exceção de "não suportado". Mas o **`endToEndId` passa a ser persistido** desde já, senão a informação se perde e a P-02, quando for decidida, começa com um passivo | entrevista |
| **DA-19** | Ambiente | **Não existe ambiente publicado ainda.** O código sai completo e o roteiro de infraestrutura vai para `docs/`. O registro do webhook em `PUT /v2/webhook/:chave` é **tarefa de implantação**, como o worker de fila da Fase 7 | entrevista |
| **DA-20** | Testes | **Duplo do cliente na suíte automatizada** (sem credencial, sempre verde no CI) **+** `php artisan efi:diagnostico` para a pessoa desenvolvedora provar à mão contra homologação | entrevista |
| **DA-21** | SDK | **SDK oficial `efipay/sdk-php-apis-efi`**, e não o cliente HTTP do Laravel. Escolha do dono do produto. **Consequência obrigatória:** como o SDK usa cliente Guzzle próprio, `Http::fake()` não o alcança — por isso o §3.3 C-12 exige o wrapper | entrevista |
| **DA-22** | Certificado | **Caminho de arquivo em variável de ambiente**, nesta fase. O `.pem` fica fora do repositório. A Fase 8b troca a fonte para o banco (cifrado, materializado em arquivo) sem que o gateway perceba — ver DA-24 | entrevista |
| **DA-24** | Fonte da configuração | **Toda leitura de credencial, certificado, chave Pix e HMAC passa por um ponto único** — uma classe de configuração do provedor, nunca `config()` espalhado pelo gateway. Nesta fase ela lê do `.env`; na **Fase 8b** ela passa a ler do banco com o `.env` como reserva, **e nenhum outro arquivo precisa mudar**. É a mesma ideia da D-17 aplicada à configuração: uma fronteira, um lugar para trocá-la | entrevista |
| **DA-23** | Taxa | `docs/PAYMENTS.md` já registra **1,19% por transação Pix**, consultado em `sejaefi.com.br/tarifas` em 2026-08-20. A **P-06** (confirmação com o comercial) **continua aberta** e não bloqueia esta fase: nenhuma taxa entra em código ou configuração | `docs/PAYMENTS.md` §6.3 |

**Decisões anteriores que esta fase deve preservar intactas:**

| # | O que diz | Por que importa aqui |
|---|---|---|
| **D-03** | A inscrição não tem estado "pago" | A Efí confirma **a cobrança**; quem fica confirmada é a inscrição, por Action própria |
| **D-06** | Dinheiro sempre em centavos inteiros | A Efí fala em string decimal. A conversão vive **só** na fronteira (C-5) |
| **D-17** | O provedor **traduz** (`parseWebhook`), nunca **age** | O `EfiPaymentGateway` não pode tocar em `Inscricao` nem em `Pagamento` |
| **D-18** | Webhook responde **200** mesmo com assinatura inválida | Mantida. Ver a leitura nova em §3.3 C-13 |
| **D-27** | A cobrança é emitida **fora** da transação | Agora vale ainda mais: é uma chamada de rede real |
| **D-31** | O aviso é guardado com campo sensível substituído por `[removido]` | O `semDadoSensivel()` do controller já cobre; conferir contra o formato da Efí |
| **D-32** | Anúncios internos só depois de a transação fechar | Não muda |
| **D-33/D-34** | Reconciliação a cada 5 min; ela **fecha** a cobrança vencida mas **não devolve vaga** | Ver C-7: a Efí não tem status `EXPIRADA` |

### 3.2 A documentação da Efí

> **LEIA PRIMEIRO:** `.planning/feat/context/efi-api-pix.md`

É a varredura da documentação oficial feita em 2026-08-27, com endpoints, formato de
requisição e resposta, status literais, o desenho do webhook e a lista de colisões. **Não
invente endpoint nem campo que não esteja lá.** Onde o documento diz ⚠️ **LACUNA**, a
informação não foi confirmada — trate conforme §6.

Por que `cobv` está fora: exige **endereço completo** do devedor (logradouro, cidade, UF,
CEP), que o formulário de inscrição não coleta. `cob` cobre o caso inteiro.

### 3.3 As colisões com o código atual — cada uma com o que fazer

Estas são o coração do plano. Nenhuma pode ser resolvida por improviso.

| # | Colisão | O que fazer |
|---|---------|-------------|
| **C-1** | O webhook da Efí é `{"pix":[...]}` — **array**. `WebhookResult` descreve um evento | **DA-17:** `parseWebhook()` passa a devolver `list<WebhookResult>`. O **controller** desdobra o lote em **N registros** de `WebhookPagamento`, um por evento, cada um com o payload recortado para aquele item. O **Job continua processando um evento por vez** (§3.4) |
| **C-2** | A assinatura vem em **query param** (`?hmac=`), não em header | `WebhookRequestData` ganha um caminho para ler da query string. **A escolha de onde ler é do provedor, não do controller** — ver C-10 |
| **C-3** | `txid` exige `^[a-zA-Z0-9]{26,35}$` — sem hífen | O gateway gera um **ULID** (26 caracteres, alfanumérico) por cobrança e o usa como `txid`. Ele volta em `PaymentResult.externalId` e é gravado em `pagamentos.id_externo`, como já acontece hoje |
| **C-4** | A devolução usa `endToEndId`, que hoje se perde | Gravar em `pagamentos.metadados['end_to_end_id']` (**`jsonb` já existe — sem migração**) quando o webhook for processado. `WebhookResult.raw` carrega o valor da fronteira até lá |
| **C-5** | Valor: string decimal na Efí ↔ centavos inteiros no domínio | Conversão **só** dentro do `EfiPaymentGateway`. Nunca com `float`: usar `intdiv`/formatação de string. Teste dedicado com valores que quebram arredondamento (ex.: `12345` → `"123.45"`, `5` → `"0.05"`, `100000` → `"1000.00"`) |
| **C-6** | A Efí acrescenta **`/pix`** ao final da URL ao notificar | Registrar a URL terminando em `?ignorar=` **e** deixar a rota responder também no caminho com sufixo `/pix`. Cinto e suspensório: a documentação descreve as duas formas e o custo de aceitar as duas é uma linha de rota |
| **C-7** | A Efí **não tem status `EXPIRADA`** | `getPayment()` traduz `ATIVA` → `pending`, sempre. **Quem decide que venceu continua sendo o `prazo_pagamento` do domínio (D-25).** Antes de escrever, **ler `app/Console/Commands/ReconciliarPagamentosPendentes.php`** e garantir que o comportamento da D-33/D-34 não muda |
| **C-8** | mTLS exige HTTPS válido + cadeia da Efí no servidor | **Não é código.** Vira roteiro em `docs/ARCHITECTURE.md` (DA-19) |
| **C-9** | Token expira em 1 h e toda requisição carrega certificado | Cache do token com margem de segurança (renovar antes de expirar, não no limite). Chave de cache **por ambiente** — homologação e produção não podem compartilhar token |
| **C-10** | `PaymentWebhookController:28` referencia **`FakePaymentGateway::SIGNATURE_HEADER`** | O controller está acoplado ao provedor simulado. Mover a construção do `WebhookRequestData` para **dentro do contrato** — o provedor sabe onde mora a assinatura dele (header, no fake; query param, na Efí); o controller não precisa saber |
| **C-11** | Uma inscrição pode ter **segunda cobrança** após cancelamento → `txid_duplicado` (409) | O ULID de C-3 é gerado **por cobrança**, não derivado do código da inscrição. Ainda assim, tratar o **409** explicitamente: gerar novo `txid` e tentar de novo, **uma vez** |
| **C-12** | O SDK usa cliente Guzzle próprio — **`Http::fake()` não o alcança** | Consequência direta da DA-21. Isolar o SDK atrás de um **wrapper fino** (`EfiClient`), que é o que a suíte substitui por um duplo. O `EfiPaymentGateway` **nunca** instancia o SDK diretamente |
| **C-13** | Responder 200 sempre **desliga a reentrega** da Efí (9 tentativas, até ~5 h) | **Manter a D-18 para assinatura inválida** (200, grava como inválido, morre ali). Mas **falha nossa** — banco fora do ar, erro inesperado — deve **propagar** e virar 5xx, para a Efí reentregar. Verificar que o controller já se comporta assim e **provar com teste** |

### 3.4 O conflito entre C-1 e "não alterar Job" — e como resolvê-lo

O pedido original diz: *"Sem alterar nenhuma Action, Model, Job ou teste de domínio."*
A DA-17 (webhook em lote) **torna isso parcialmente impossível**, e isso precisa ficar
explícito:

- `app/Jobs/ProcessarWebhookPagamento.php` chama `parseWebhook()` e usa
  `$resultado->isActionable()`. Mudar a assinatura do contrato **obriga** a tocar nele.
- **A mudança é de uma linha e não toca em regra nenhuma:** como o controller já desdobra o
  lote (C-1), o payload que chega ao Job tem **exatamente um** item, e o Job passa a pegar
  o primeiro elemento da lista. Toda a lógica de idempotência em três camadas, a busca do
  pagamento e as Actions chamadas **ficam idênticas**.
- **Actions e Models: zero alterações.** `ConfirmarPagamento`, `CancelarPagamento`,
  `CriarPagamentoDaInscricao`, `ConfirmarPagamentoManual`, `Inscricao`, `Pagamento` — nada.
- **Testes:** mudam apenas os que exercitam a **fronteira** (`PaymentGatewayTest`,
  `WebhookPagamentoTest`). Os que provam **regra de inscrição, vaga, concorrência, carga e
  auditoria ficam intactos** — e continuar verdes é critério de qualidade (§5).

### 3.5 Arquivos existentes a ler **antes de escrever qualquer linha**

| Arquivo | Por quê |
|---|---|
| `.planning/feat/context/efi-api-pix.md` | a documentação da Efí destilada |
| `app/Contracts/Payments/PaymentGateway.php` | o contrato a implementar |
| `app/Services/Payments/Fake/FakePaymentGateway.php` | o espelho a seguir — 331 linhas que mostram o padrão esperado |
| `app/DTOs/Payments/*.php` | os seis DTOs da fronteira |
| `app/Providers/PaymentServiceProvider.php` | o `match` e as rotas |
| `app/Http/Controllers/Webhooks/PaymentWebhookController.php` | C-10 e C-13 moram aqui |
| `app/Jobs/ProcessarWebhookPagamento.php` | §3.4 |
| `app/Console/Commands/ReconciliarPagamentosPendentes.php` | C-7 |
| `app/Enums/SituacaoPagamento.php` | **não muda** — já fala o vocabulário neutro |
| `app/Actions/Pagamentos/CriarPagamentoDaInscricao.php` | para confirmar que **não** precisa mudar |
| `config/payments.php` | onde entra o bloco `efi` |
| `tests/Feature/Pagamentos/` | as sete suítes que precisam continuar verdes |
| `docs/PAYMENTS.md` | a matriz de provedores e o `match` comentado na linha 94 |

### 3.6 Configuração e variáveis de ambiente

Novo bloco `efi` em `config/payments.php`, com **nenhuma taxa comercial** (regra já escrita
no arquivo). Variáveis novas, todas em `.env.example` com valor vazio e comentário:

| Variável | Para quê |
|---|---|
| `EFI_ENVIRONMENT` | `homologacao` ou `producao` — decide a URL base |
| `EFI_CLIENT_ID` / `EFI_CLIENT_SECRET` | as credenciais da aplicação |
| `EFI_CERT_PATH` | caminho absoluto do `.pem` (DA-22) |
| `EFI_PIX_KEY` | a chave Pix **da conta recebedora** |
| `EFI_WEBHOOK_HMAC` | o valor conferido no query param (C-2) |
| `EFI_TIMEOUT` | tempo limite das chamadas, com padrão conservador |

URLs base **não** vão para o `.env`: são constantes do provedor
(`https://pix.api.efipay.com.br` e `https://pix-h.api.efipay.com.br`), derivadas de
`EFI_ENVIRONMENT`. Errar isso é enviar cobrança de teste para produção.

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `composer.json` / `composer.lock` | modify | `efipay/sdk-php-apis-efi` ^1.19 — a única dependência nova (DA-21) |
| `config/payments.php` | modify | bloco `efi`; `'default'` passa a suportar `fake` e `efi` |
| `.env.example` | modify | as seis variáveis de §3.6, vazias e comentadas |
| `.gitignore` | modify | a pasta de certificados |
| `app/Services/Payments/Efi/ConfiguracaoEfi.php` | create | **o ponto único de leitura da configuração (DA-24)**: ambiente, credenciais, certificado, chave Pix, HMAC. Nesta fase lê do `.env`; a Fase 8b troca só o corpo dela |
| `app/Services/Payments/Efi/EfiClient.php` | create | wrapper fino do SDK (C-12): certificado, token com cache (C-9), tradução de erro |
| `app/Services/Payments/Efi/EfiPaymentGateway.php` | create | a implementação do contrato |
| `app/Services/Payments/Efi/TraducaoDeStatus.php` | create | `ATIVA`→`pending`, `CONCLUIDA`→`paid`, `REMOVIDA_*`→`canceled` (C-7) |
| `app/Exceptions/Payments/EfiException.php` | create | erro do provedor traduzido, sem vazar credencial na mensagem |
| `app/Exceptions/Payments/EstornoNaoSuportadoException.php` | create | DA-18 |
| `app/Contracts/Payments/PaymentGateway.php` | modify | `parseWebhook(): list<WebhookResult>` (C-1) + construção do `WebhookRequestData` (C-10) |
| `app/DTOs/Payments/WebhookRequestData.php` | modify | caminho de leitura por query string (C-2) |
| `app/Services/Payments/Fake/FakePaymentGateway.php` | modify | acompanha o contrato novo, **sem mudar o comportamento simulado** |
| `app/Http/Controllers/Webhooks/PaymentWebhookController.php` | modify | desdobra o lote (C-1), deixa de citar o fake (C-10), preserva D-18 e propaga falha nossa (C-13) |
| `app/Jobs/ProcessarWebhookPagamento.php` | modify | **uma linha** (§3.4) + grava o `endToEndId` (C-4) |
| `app/Providers/PaymentServiceProvider.php` | modify | `'efi'` no `match`; rota alternativa com sufixo `/pix` (C-6) |
| `app/Console/Commands/DiagnosticoEfi.php` | create | `php artisan efi:diagnostico` (DA-20) |
| `tests/Feature/Pagamentos/Efi/EfiClientFake.php` | create | o duplo que substitui o SDK na suíte (C-12) |
| `tests/Feature/Pagamentos/Efi/EfiPaymentGatewayTest.php` | create | cobrança, consulta, cancelamento, conversão de valor, erros 409/429/400 |
| `tests/Feature/Pagamentos/Efi/EfiWebhookTest.php` | create | HMAC válido/inválido, lote com dois pix, `endToEndId` gravado, sufixo `/pix`, D-18, C-13 |
| `tests/Feature/Pagamentos/PaymentGatewayTest.php` | modify | ajuste ao contrato novo — **fronteira**, não domínio |
| `tests/Feature/Pagamentos/WebhookPagamentoTest.php` | modify | idem |
| `docs/PAYMENTS.md` | modify | Efí como provedor escolhido; o `match` da linha 94 deixa de ser comentário; P-06 segue aberta |
| `docs/ARCHITECTURE.md` | modify | seção nova: mTLS do webhook, cadeia da Efí no nginx, registro do webhook, `EFI_CERT_PATH` |
| `docs/PROGRESS.md` | modify | Etapa 17, decisões DA-16 a DA-23, Fase 8 concluída, P-01 fechada, P-02 e P-06 ainda abertas |
| `docs/IMPLEMENTATION_PLAN.md` | modify | Fase 8 concluída |
| `.planning/feat/features/fase-8-provedor-pagamento-efi/plan.done.md` | create | relatório de execução |

---

## 5. Quality Criteria

### Fronteira e domínio

- [ ] **Nenhuma Action, Model ou Enum de domínio alterado.** Provar com
      `git diff --stat` sobre `app/Actions/`, `app/Models/`, `app/Enums/` → **vazio**
- [ ] `SituacaoPagamento::deStatusExterno()` **não foi tocado**
- [ ] As palavras `Efi` / `EfiPaymentGateway` **não aparecem** em nenhuma Action, Model,
      Job ou Service de inscrição — só em `app/Services/Payments/Efi/`, no
      `PaymentServiceProvider`, no comando de diagnóstico e nos testes da fronteira
- [ ] `PaymentWebhookController` **não cita mais `FakePaymentGateway`** (C-10)

### Cobrança

- [ ] `txid` gerado sempre casa com `^[a-zA-Z0-9]{26,35}$` — teste com 1.000 gerações
- [ ] Conversão centavos ↔ string decimal correta em `5`, `99`, `12345`, `100000`,
      **sem `float` em lugar nenhum** do caminho
- [ ] `createPayment()` devolve `pixPayload` preenchido a partir de `pixCopiaECola`
- [ ] **Nenhuma chamada a `/v2/loc/:id/qrcode`** — o payload já vem na resposta da cobrança
- [ ] `409 txid_duplicado` gera **uma** nova tentativa com txid novo, e só uma (C-11)
- [ ] `429` e erro de rede viram `EfiException` com mensagem em português e **sem
      credencial, token ou caminho de certificado no texto**
- [ ] `refundPayment()` lança `EstornoNaoSuportadoException` (DA-18)

### Webhook

- [ ] HMAC conferido com **`hash_equals`**, nunca com `==` ou `===`
- [ ] Um POST com **dois** itens em `pix[]` gera **dois** registros de `WebhookPagamento` e
      **dois** jobs — nenhum pagamento perdido (C-1)
- [ ] `endToEndId` gravado em `pagamentos.metadados` (C-4), **sem migração nova**
- [ ] A rota responde tanto no caminho configurado quanto no caminho com sufixo `/pix` (C-6)
- [ ] **D-18 preservada:** assinatura inválida → **200**, gravado como inválido, sem job
- [ ] **C-13 provado:** falha interna (banco indisponível) → resposta **não-2XX**, para a
      Efí reentregar
- [ ] Aviso repetido continua sem produzir segundo efeito — a idempotência das três camadas
      segue valendo

### Segurança

- [ ] Nenhuma credencial, token ou conteúdo de certificado em log, em exceção ou em
      `webhooks_pagamento.payload`
- [ ] `.gitignore` cobre a pasta de certificados; **nenhum `.p12` ou `.pem` no repositório**
- [ ] O bloco `efi` de `config/payments.php` **não contém nenhuma taxa comercial**
- [ ] `EfiClient` recusa operar sem certificado, com erro claro — falha para o lado seguro,
      como o `PAYMENT_FAKE_WEBHOOK_SECRET` já faz
- [ ] **DA-24 provada:** `grep -rn "config('payments.efi" app/` encontra ocorrência **apenas
      dentro de `ConfiguracaoEfi`**. Se o gateway, o cliente ou o comando lerem configuração
      por conta própria, a Fase 8b terá de reescrevê-los

### Testes

- [ ] A suíte nova roda **sem credencial e sem certificado** (DA-20/C-12)
- [ ] `php artisan efi:diagnostico` existe, é **travado fora de `local`/`testing`** e
      imprime cada passo: certificado, token, cobrança, `pixCopiaECola`
- [ ] **Os 452 testes Pest continuam verdes** (a base da fase); os novos entram por cima
- [ ] **Os 32 cenários Playwright continuam verdes, sem edição** — a tela de cobrança não
      muda, e é isso que prova que a troca de provedor é invisível para quem se inscreve
- [ ] `vendor/bin/pint --test` · `npm run lint` · `npx vue-tsc --noEmit` · `composer audit`

---

## 6. Ambiguity Handling

**Assumptions made:**

- **O `txid` é um ULID gerado por cobrança**, não derivado do código da inscrição. Motivo:
  uma inscrição pode gerar uma segunda cobrança depois de a primeira ser cancelada, e
  reusar o identificador daria 409 (C-11). ULID tem 26 caracteres alfanuméricos e cabe no
  formato exigido sem transformação.
- **O `endToEndId` vai para `pagamentos.metadados`**, não para coluna nova. A coluna `jsonb`
  já existe e a Fase 9 provou que índice sem medição que justifique não entra.
- **O Job muda uma linha** (§3.4). É a consequência inevitável da DA-17, e o menor recorte
  possível dela.
- **A rota aceita os dois caminhos** (com e sem sufixo `/pix`), porque a documentação
  descreve as duas formas e o custo de aceitar ambas é desprezível perto do custo de
  descobrir o erro com dinheiro real em jogo.
- **O `EfiClient` é o único ponto que conhece o SDK.** Se um dia o SDK sair, muda um
  arquivo. É também o que torna a suíte possível sem credencial (C-12).
- **P-06 não bloqueia.** Nenhuma taxa entra em código; `docs/PAYMENTS.md` mantém o valor
  público com a data da consulta e a pendência aberta.

**If unsure during execution:**

- **A devolução (§7 do documento de contexto) é uma LACUNA.** Não invente endpoint. A DA-18
  já mandou lançar "não suportado" — se por algum motivo parecer necessário implementar,
  **pare e pergunte**.
- **Se a Efí devolver campo que não está no documento de contexto**, guarde em `raw` e
  registre no relatório. Não descarte em silêncio.
- **Se algo exigir mudar uma Action, um Model ou uma regra de inscrição, PARE.** Isso é o
  contrário do objetivo desta fase e significa que o desenho da fronteira está errado.
- **Se a auto-confirmação de cobranças até R$ 10,00 em homologação (§2.3) não se
  confirmar**, não apoie teste automatizado nela — a suíte usa o duplo e não depende disso.
- **Executores morrem em ~60 chamadas de ferramenta.** Commite ao fim de **cada** step. A
  Fase 9 precisou de seis rodadas e não perdeu nada por causa disso.

---

## 7. Prohibitions

- ❌ **NUNCA** citar `Efi` em Action, Model, Job ou Service de inscrição — a regra
  inegociável de `docs/PAYMENTS.md` linha 100
- ❌ **NUNCA** deixar o `EfiPaymentGateway` tocar em `Inscricao` ou `Pagamento`. Ele
  **traduz**, não age (D-17)
- ❌ **NUNCA** usar `float` ou `double` no caminho do dinheiro (D-06)
- ❌ **NUNCA** responder 401/403 a webhook com assinatura inválida (D-18)
- ❌ **NUNCA** comparar HMAC com `==` ou `===`
- ❌ **NUNCA** gravar credencial, token, ou conteúdo de certificado em log, exceção ou banco
- ❌ **NUNCA** commitar `.p12` ou `.pem`
- ❌ **NUNCA** registrar taxa comercial em código ou configuração
- ❌ **NUNCA** instanciar o SDK fora do `EfiClient` (C-12)
- ❌ **NUNCA** editar os 32 cenários Playwright existentes
- ❌ **NUNCA** adicionar dependência além do SDK aprovado na DA-21
- ❌ **NUNCA** deixar a chave Pix, o ambiente ou a URL base decididos por valor fixo no
  código do gateway

---

## Execution Steps

1. **Configuração e credenciais.** Instalar `efipay/sdk-php-apis-efi` ^1.19; bloco `efi` em
   `config/payments.php`; as seis variáveis em `.env.example`; `.gitignore` para
   certificados. Nenhuma taxa. Confirmar que `composer audit` segue limpo.
   → commit `chore(pagamentos): add efi sdk and configuration`

2. **`ConfiguracaoEfi` e `EfiClient`.** Primeiro o ponto único de leitura da configuração
   (DA-24) — é ele que faz a Fase 8b ser barata. Depois o wrapper que isola o SDK:
   carregamento do certificado (falha clara se ausente), token com cache e margem de
   renovação (C-9), chave de cache por ambiente, tradução de erro para `EfiException` sem
   vazar segredo. Mais o duplo de teste.
   → commit `feat(pagamentos): add isolated efi client`

3. **O contrato em lote e o desacoplamento da assinatura.** `parseWebhook()` →
   `list<WebhookResult>` (C-1); `WebhookRequestData` lê da query string (C-2); a construção
   do request sai do controller e entra no contrato (C-10); `FakePaymentGateway`, controller
   e Job acompanham (§3.4); os dois testes de fronteira ajustados. **Suíte inteira verde ao
   fim deste step** — este é o step que mais pode quebrar coisa.
   → commit `refactor(pagamentos): parse webhooks in batch`

4. **`EfiPaymentGateway` — a cobrança.** `name()`, `createPayment()` (`PUT /v2/cob/:txid`
   com ULID, C-3, com o retry único do 409, C-11), `getPayment()`, `cancelPayment()`,
   `refundPayment()` lançando não-suportado (DA-18). `TraducaoDeStatus` (C-7) e a conversão
   de valor (C-5).
   → commit `feat(pagamentos): add efi charge creation`

5. **O webhook da Efí.** `verifyWebhookSignature()` por HMAC de query param com
   `hash_equals`; `parseWebhook()` traduzindo `pix[]`; `endToEndId` persistido (C-4); rota
   com sufixo `/pix` (C-6); D-18 preservada e C-13 provado.
   → commit `feat(pagamentos): add efi webhook handling`

6. **Registro e diagnóstico.** `'efi'` no `match` do `PaymentServiceProvider`;
   `php artisan efi:diagnostico`, travado fora de `local`/`testing`, imprimindo cada passo.
   → commit `feat(pagamentos): register efi gateway and diagnostics`

7. **A prova.** As duas suítes novas (§4). Rodar tudo: Pest, Playwright sem editar cenário,
   pint, lint, vue-tsc, `composer audit`. Registrar os números — antes e depois — como as
   fases anteriores fizeram.
   → commit `test(pagamentos): prove efi integration`

8. **Documentação e fechamento.** `PAYMENTS.md`, `ARCHITECTURE.md` (o roteiro de mTLS e
   nginx da DA-19/C-8), `PROGRESS.md` (Etapa 17, DA-16 a DA-24, **P-01 fechada**, **P-02 e
   P-06 ainda abertas**, **Fase 8b pendente**, LGPD **ainda não feita**),
   `IMPLEMENTATION_PLAN.md` e o `plan.done.md`.
   → commit `docs(pagamentos): close phase 8a`

---

## Done

`PAYMENT_GATEWAY=efi` mais as variáveis do `.env` fazem o sistema cobrar pela Efí de verdade
— cobrança criada, QR Code na tela, webhook conferido e inscrição confirmada — **sem uma
linha alterada em Action, Model ou regra de inscrição**, com os 452 testes Pest e os 32
cenários Playwright anteriores verdes, o roteiro de infraestrutura escrito, a **P-01
fechada** em `docs/PROGRESS.md` e a configuração inteira atrás de `ConfiguracaoEfi`, pronta
para a Fase 8b trocar a fonte sem tocar em mais nada.

## Commit

`feat(pagamentos): add efi payment provider`
