# Efí — API Pix: varredura da documentação oficial

> **Consultado em:** 2026-08-27 · **Fonte:** `dev.efipay.com.br/docs/api-pix/*`
> **Para que serve:** alimentar o plano da **Fase 8 — provedor de pagamento real**.
> **Regra:** o que está aqui foi lido na documentação. O que **não** foi confirmado está
> marcado como ⚠️ **LACUNA** e não pode virar código sem verificação.

---

## 1. Credenciais e autenticação

| Item | Valor |
|---|---|
| URL base produção | `https://pix.api.efipay.com.br` |
| URL base homologação | `https://pix-h.api.efipay.com.br` |
| Token | `POST /oauth/token`, corpo `{"grant_type":"client_credentials"}` |
| Autorização do token | HTTP **Basic** com as duas credenciais da aplicação em Base64 |
| Validade do token | `expires_in: 3600` (uma hora) |
| Uso do token | header `Authorization: Bearer {access_token}` |

**O certificado é obrigatório em TODAS as requisições** — inclusive na de token. É mTLS: o
`.p12` baixado no painel da Efí, convertido para PEM com `openssl pkcs12`.

Há **um par de credenciais e um certificado por ambiente** (produção e homologação), e o
`.p12` **só pode ser baixado no momento em que é criado**.

**Escopos** (selecionados na criação da aplicação): `cob.read`, `cob.write`, `pix.read`,
`pix.write`, `pix.send`, `webhook.read`, `webhook.write`, `payloadlocation.read`,
`payloadlocation.write`, `cobv.read`, `cobv.write`, `gn.balance.read`.

> Para esta fase bastam: `cob.read`, `cob.write`, `pix.read`, `webhook.read`,
> `webhook.write` — mais `pix.write` **se** houver devolução (ver §7).

---

## 2. Cobrança imediata (`cob`) — é a que este projeto usa

| Método | Endpoint | Escopo | Para quê |
|---|---|---|---|
| `PUT` | `/v2/cob/:txid` | `cob.write` | criar com txid nosso |
| `POST` | `/v2/cob` | `cob.write` | criar com txid gerado pela Efí |
| `GET` | `/v2/cob/:txid` | `cob.read` | consultar |
| `PATCH` | `/v2/cob/:txid` | `cob.write` | revisar / remover |
| `GET` | `/v2/cob` | `cob.read` | listar (`inicio` e `fim` obrigatórios, ISO-8601) |

**Requisição mínima:**

```json
{
  "calendario": { "expiracao": 3600 },
  "devedor":    { "cpf": "12345678909", "nome": "Francisco da Silva" },
  "valor":      { "original": "123.45" },
  "chave":      "71cdf9ba-c695-4e3c-b010-abb521a3f1be"
}
```

- `calendario.expiracao` — **em segundos**, inteiro > 0.
- `valor.original` — **string com ponto decimal**, não centavos. Conversão obrigatória
  na fronteira (o domínio guarda centavos inteiros — D-06).
- `chave` — a chave Pix da conta recebedora (UUID), não a do pagador.
- `devedor` — **cpf/cnpj + nome obrigatórios**.
- Opcionais: `solicitacaoPagador`, `infoAdicionais[]` (nome/valor), `loc.id`.

**Resposta** traz `txid`, `revisao`, `status`, `loc {id, location, tipoCob}`,
**`pixCopiaECola`** (o BRCode pronto) e o array `pix[]` quando já houver pagamento.

**Erros:** `documento_bloqueado` (400), `chave_invalida` (400), `valor_invalido` (400),
`txid_duplicado` (**409**), `cobranca_nao_encontrada` (400), `status_cobranca_invalido`
(409), limite de requisições (**429**).

### 2.1 `txid` — regra de formato que nos afeta

Do glossário: **26 a 35 caracteres alfanuméricos**, regex `^[a-zA-Z0-9]{26,35}$`, único
por CPF/CNPJ recebedor. **Sem hífen, sem underscore.**

### 2.2 Status de cobrança

`ATIVA` · `CONCLUIDA` · `REMOVIDA_PELO_USUARIO_RECEBEDOR` · `REMOVIDA_PELO_PSP`

### 2.3 Homologação confirma cobranças pequenas sozinha

A documentação de `cob` e de `cobv` afirma: *"Cobranças com valor entre R$ 0.01 à
R$ 10.00 são confirmadas, e você receberá informação via Webhook"*. Acima de R$ 10,00 a
cobrança permanece `ATIVA` sem confirmação. **É o que permite testar o webhook fim a fim
sem dinheiro real.** ⚠️ A doc não diz explicitamente que isso é exclusivo da homologação —
confirmar antes de apoiar teste automatizado nisso.

---

## 3. Cobrança com vencimento (`cobv`) — **não** serve para este projeto

`PUT/PATCH/GET /v2/cobv/:txid`, `GET /v2/cobv`. Usa `calendario.dataDeVencimento` +
`validadeAposVencimento`, e aceita `multa`, `juros`, `desconto`, `abatimento`.

**Por que fica de fora:** o `devedor` de uma `cobv` exige **endereço completo** — nome,
logradouro, cidade, UF, CEP, CPF/CNPJ. O formulário de inscrição não coleta endereço, e o
prazo de pagamento do domínio é curto e sem multa nem juros. `cob` cobre o caso inteiro.

---

## 4. Payload locations e QR Code

| Método | Endpoint | Escopo | Observação |
|---|---|---|---|
| `POST` | `/v2/loc` | `payloadlocation.write` | corpo `{"tipoCob":"cob"}` |
| `GET` | `/v2/loc` · `/v2/loc/:id` | `payloadlocation.read` | |
| `GET` | **`/v2/loc/:id/qrcode`** | `payloadlocation.read` | devolve `qrcode` (BRCode), `imagemQrcode` (**SVG em base64**) e `linkVisualizacao` |
| `DELETE` | `/v2/loc/:id/txid` | | desvincula cobrança da location |

**Consequência prática:** o `PUT /v2/cob/:txid` **já devolve `pixCopiaECola`**. A tela de
cobrança de hoje recebe o payload e desenha o QR no navegador. Portanto
**`/v2/loc/:id/qrcode` é dispensável** — uma chamada de rede a menos e nenhuma mudança no
frontend.

---

## 5. Webhook — aqui está a decisão de arquitetura da fase

### 5.1 A Efí **não** manda header de assinatura

A autenticação do webhook é **mTLS reverso**: a Efí apresenta o certificado dela, e o
**nosso servidor** é que valida. A cadeia pública fica em:

- Produção: `https://certificados.efipay.com.br/webhooks/certificate-chain-prod.crt`
- Homologação: `https://certificados.efipay.com.br/webhooks/certificate-chain-homolog.crt`

Requisitos do nosso endpoint: **HTTPS**, TLS 1.2 no mínimo, certificado SSL válido para o
domínio. A validação acontece em **duas requisições**: a primeira chega sem certificado e
o nosso servidor deve **recusar**; a segunda vem com o certificado da Efí.

> Isso é configuração de **servidor web (nginx)**, não de aplicação Laravel.

### 5.2 As três camadas disponíveis

| Camada | Onde vive | Serve para `verifyWebhookSignature()`? |
|---|---|---|
| mTLS com a cadeia da Efí | nginx / servidor web | ❌ não chega ao PHP |
| Lista de IP permitido — **`34.193.116.226`** | nginx ou middleware | parcialmente |
| **HMAC como parâmetro da URL** | **aplicação** | ✅ **sim** |

O HMAC é registrado **dentro da própria URL do webhook**:
`https://dominio.com/webhooks/pagamentos?hmac=VALOR&ignorar=` — a Efí devolve o
parâmetro em toda notificação, e nós conferimos.

**→ É esta a resposta para a dúvida levantada antes de abrir a fase:**
`verifyWebhookSignature()` na implementação Efí valida o **query param `hmac`** com
`hash_equals` contra um valor de configuração. **O contrato `PaymentGateway` não precisa
mudar.** Mas `WebhookRequestData::fromRequest()` hoje lê a assinatura **de um header** —
precisa de um caminho que leia da query string.

### 5.3 O sufixo `/pix` que a Efí acrescenta sozinha

Ao notificar de verdade, a Efí **acrescenta `/pix` ao final da URL registrada** (só a
notificação de teste vai na URL base). Contorno documentado: terminar a URL com
`?ignorar=`. Nossa rota é `webhooks/pagamentos` (`PAYMENT_WEBHOOK_PATH`). **Decisão a
tomar no plano:** registrar com `?ignorar=` ou aceitar também `webhooks/pagamentos/pix`.

### 5.4 Formato do aviso — **é um array, e isso colide com o nosso DTO**

```json
{
  "pix": [
    {
      "endToEndId": "E1803615022211340s08793XPJ",
      "txid": "fc9a43k6ff384ryP5f41719",
      "chave": "2c3c7441-b91e-4982-3c25-6105581e18ae",
      "valor": "0.01",
      "horario": "2020-12-21T13:40:34.000Z",
      "infoPagador": "pagando o pix",
      "gnExtras": { }
    }
  ]
}
```

Três consequências:

1. **Um POST pode trazer VÁRIOS pix.** Nosso `WebhookResult` descreve **um** evento.
2. **Não existe campo `status`.** O aviso diz "um Pix caiu", e não "a cobrança mudou para
   X". A tradução é: *recebi um pix com este `txid` → a cobrança está paga*.
3. **`endToEndId` só existe aqui**, e é ele — não o `txid` — que a devolução exige (§7).

### 5.5 Endpoints de configuração e reenvio

| Método | Endpoint | Escopo |
|---|---|---|
| `PUT` / `GET` / `DELETE` | `/v2/webhook/:chave` | `webhook.write` / `webhook.read` |
| `GET` | `/v2/webhook` | `webhook.read` (`inicio` e `fim`) |
| `POST` | `/v2/gn/webhook/reenviar` | `webhook.write` — `{"tipo":"PIX_RECEBIDO","e2eids":[...]}`, responde **202**, janela de 30 dias |

Valores de `tipo`: `PIX_RECEBIDO`, `PIX_ENVIADO`, `DEVOLUCAO_RECEBIDA`, `DEVOLUCAO_ENVIADA`.

### 5.6 Reentrega — e o que isso faz com a decisão D-18

A Efí espera **HTTP 2XX**, com **timeout de 60 segundos**. Em falha, retenta **até 9
vezes**: imediata · 5 · 5 · 5 · 10 · 20 · 40 · 80 · 160 minutos.

**D-18 diz que respondemos 200 mesmo com assinatura inválida.** Isso continua correto e
seguro. Mas há um efeito novo a decidir: **responder 200 desliga a reentrega**. Se a nossa
aplicação falhar por motivo interno (banco fora do ar), responder 200 joga o aviso fora —
enquanto responder 5xx faria a Efí tentar de novo por até ~5 horas de graça.

---

## 6. Status da API (valores literais)

| Domínio | Valores |
|---|---|
| Cobrança (`cob`) | `ATIVA` · `CONCLUIDA` · `REMOVIDA_PELO_USUARIO_RECEBEDOR` · `REMOVIDA_PELO_PSP` |
| Devolução | `EM_PROCESSAMENTO` · `DEVOLVIDO` · `NAO_REALIZADO` |
| Pix enviado | `EM_PROCESSAMENTO` · `REALIZADO` · `NAO_REALIZADO` |

Mapeamento para o vocabulário neutro da fronteira (`pending`, `paid`, `failed`, `expired`,
`canceled`, `refunded`), que `SituacaoPagamento::deStatusExterno()` já consome:

| Efí | Fronteira |
|---|---|
| `ATIVA` | `pending` |
| `CONCLUIDA` | `paid` |
| `REMOVIDA_PELO_USUARIO_RECEBEDOR` | `canceled` |
| `REMOVIDA_PELO_PSP` | `canceled` |
| aviso de webhook com `pix[]` | `paid` |
| devolução `DEVOLVIDO` | `refunded` |

⚠️ **LACUNA:** a Efí **não tem status `EXPIRADA`**. Passada a `expiracao`, a cobrança
aparentemente continua `ATIVA` na consulta. Quem decide que venceu é o nosso
`prazo_pagamento` (D-25). Confirmar o comportamento real antes de escrever `getPayment()`
— a reconciliação da D-33 depende disso.

---

## 7. Devolução (estorno) — ⚠️ **LACUNA de documentação**

A página específica **não foi localizada** nas URLs varridas. O que se sabe:

- O status de devolução existe (`EM_PROCESSAMENTO`, `DEVOLVIDO`, `NAO_REALIZADO`) e o
  array `devolucoes[]` aparece dentro de `pix[]` com os campos `id`, `rtrId`, `valor`,
  `natureza`, `status`.
- **A devolução é indexada pelo `endToEndId`, não pelo `txid`.**

**O problema concreto que isso cria:** o contrato é
`refundPayment(string $externalId, ?int $amountCents = null)`, e o nosso `externalId` é o
**txid**, gravado em `pagamentos.id_externo`. **O `endToEndId` não é persistido em lugar
nenhum hoje.** Ele chega no webhook (§5.4) e se perde.

**→ Recomendação, mesmo que a P-02 adie o estorno:** gravar o `endToEndId` em
`pagamentos.metadados` (a coluna `jsonb` já existe, sem migração) assim que o webhook
chegar. Sem isso, oferecer estorno depois exige varrer a API da Efí para reencontrar o
e2eid de cada pagamento.

---

## 8. Colisões com o código atual — lista para o plano resolver

| # | Colisão | Onde | Gravidade |
|---|---|---|---|
| C-1 | Webhook traz **array** `pix[]`; `WebhookResult` descreve **um** evento | `app/DTOs/Payments/WebhookResult.php` | **alta** — mexe no contrato ou exige decisão de recorte |
| C-2 | Assinatura vem em **query param**, não em header | `WebhookRequestData::fromRequest()` | **alta** |
| C-3 | `txid` exige `^[a-zA-Z0-9]{26,35}$` | gerador do identificador da cobrança | média |
| C-4 | Devolução precisa de `endToEndId`, que não é persistido | `pagamentos.metadados` | média |
| C-5 | Valor vai como **string decimal**; o domínio guarda **centavos** | conversão na fronteira | baixa (mas erra fácil) |
| C-6 | Sufixo `/pix` na URL de notificação | `PAYMENT_WEBHOOK_PATH` / rota | média |
| C-7 | Sem status `EXPIRADA` na Efí | `getPayment()` e reconciliação (D-33) | média |
| C-8 | mTLS exige HTTPS com certificado válido **e** a cadeia da Efí no nginx | infraestrutura, fora do Laravel | **alta** — não é código |
| C-9 | Token expira em 1 h e cada requisição carrega certificado | cliente HTTP + cache do token (Redis já existe) | média |

---

## 9. O que fica de fora desta fase (confirmado pela varredura)

- **`cobv`** — exige endereço completo do devedor (§3).
- **Split de pagamento, Pix por biometria, Pix Automático (`rec`/`locrec`)** — existem na
  API, não têm caso de uso aqui.
- **Cartão de crédito** — a Efí tem taxa de 3,49% registrada em `docs/PAYMENTS.md`, mas o
  domínio só conhece Pix.

---

## 10. Páginas varridas

| Página | Rendeu |
|---|---|
| `/docs/api-pix/credenciais` | §1 completo |
| `/docs/api-pix/cobrancas-imediatas` | §2 completo |
| `/docs/api-pix/webhooks` | §5 completo — **o achado mais importante da varredura** |
| `/docs/api-pix/payload-locations` | §4 completo |
| `/docs/api-pix/status` | §6 completo |
| `/docs/api-pix/cobrancas-com-vencimento` | §3 — suficiente para descartar `cobv` |
| `/docs/api-pix/glossario` | apenas `txid`, `location`, `payload`, `revisão`, `webhook`. Não define `endToEndId`, `BRCode`, `PSP`, `DICT`, `rtrId` |
| `/docs/api-pix/fluxogramas` | **nada aproveitável** — só diagramas, sem endpoints nem diferença entre ambientes |
| devolução / Pix recebidos | **página não localizada** — ver §7 |
