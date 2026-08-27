# Execution Report — Fase 8b: credenciais e certificado cadastrados pela interface

> **Plan:** `fase-8b-credenciais-pela-interface`
> **Executed:** 2026-08-27 (em **três rodadas** de executor — ver "Como esta fase foi executada")
> **Branch:** `feat/fase-8a-efi`
> **Status:** ⚠️ WITH CAVEATS

---

## Para quem lê isto daqui a seis meses

A Fase 8a deixou o sistema capaz de cobrar de verdade pela Efí, mas a credencial da
instituição financeira morava no **arquivo de ambiente do servidor**. Isso tem dois custos
que só aparecem no pior dia: trocar uma chave exige alguém com acesso ao servidor, e
arquivo de ambiente **não entra no backup do banco** — um contêiner recriado leva a
configuração junto, e a descoberta acontece quando uma pessoa tenta se inscrever.

Esta fase tirou a credencial de lá e a colocou numa **tela do painel**
(`/admin/pagamentos/credenciais`), com tudo cifrado no banco, dois ambientes independentes
(homologação e produção) e **nada voltando para o navegador**.

A frase que resume o resultado técnico: **a fase inteira alterou um único arquivo do lado
do provedor de pagamento** — `ConfiguracaoEfi.php`. Era exatamente o que a decisão **DA-24**
da Fase 8a tinha prometido, e é a primeira vez que essa promessa foi cobrada.

**O que esta fase NÃO fez, e é importante saber antes de contar com isso:** nada foi ligado
contra dinheiro de verdade. Não há ambiente publicado, não há credencial real cadastrada e
o endereço do aviso automático não está registrado no painel da Efí. Ver "O que ficou por
fazer", no fim.

---

## Como esta fase foi executada (leia antes de comparar com o plano)

A execução **morreu duas vezes por limite de chamadas de ferramenta do agente** e foi
retomada do zero de contexto nas duas vezes. Nada se perdeu, e o motivo é o processo e não
a sorte: o plano mandava **commitar ao fim de cada passo** (§6), então cada retomada
encontrou a árvore num estado provado e só precisou descobrir onde recomeçar.

Duas consequências ficaram no histórico e não vale escondê-las:

1. **Os passos 4 e 5 saíram num commit só** (`3cd18f3`). O plano previa
   `feat(pagamentos): add credential management endpoints` e
   `feat(pagamentos): add credential management screen` separados; a rodada que os executou
   fez o backend e a tela na mesma sequência e commitou junto. A mensagem que ficou é a do
   passo 4.
2. **Um efeito colateral do passo 3 passou despercebido até a rodada seguinte.** A permissão
   nova entrou no seeder e o `AutorizacaoTest`, que conta permissões, esperava nove. A
   correção para dez veio junto com o commit da prova (`31b9d17`), com a contagem literal
   ajustada e **nenhuma asserção afrouxada**.

A terceira rodada (esta) **não escreveu código**: fechou a documentação, re-rodou a
verificação e escreveu este relatório.

---

## O que foi feito, passo a passo

### Passo 1 — A tabela e o modelo · `4f7b94b feat(pagamentos): add encrypted credential storage`

| Arquivo | Ação | O que é |
|---|---|---|
| `database/migrations/2026_08_27_100001_create_credenciais_pagamento_table.php` | create | 100 linhas |
| `app/Models/CredencialPagamento.php` | create | 262 linhas |
| `app/Enums/AmbientePagamento.php` | create | 51 linhas |
| `tests/Feature/Pagamentos/CredenciaisPagamentoTest.php` | create | 236 linhas |

A tabela saiu como o plano descreve em §3.3, com três garantias **no banco** e não em PHP:

- `CHECK (ambiente IN ('homologacao','producao'))` — coluna livre aceitaria `produção` com
  acento ou `PROD`, e o sistema cairia para homologação em silêncio, cobrando de mentira
  quem devia pagar de verdade.
- `UNIQUE (gateway, ambiente)` — não existem dois cadastros do mesmo ambiente.
- `CREATE UNIQUE INDEX credenciais_pagamento_um_ativo_por_gateway ON credenciais_pagamento
  (gateway) WHERE ativo = true` — o índice **parcial** que impede o segundo ativo. É a
  mesma lição da **D-66**: quem impede o segundo é o banco, porque verificação em PHP não
  sobrevive a duas requisições ao mesmo tempo.

Os cinco campos sigilosos são `text` (e não `string`) porque o texto cifrado é bem maior
que o valor original e o conteúdo do certificado não tem tamanho previsível. O model
declara os cinco com a conversão `encrypted` — **o mesmo mecanismo de `Inscricao::documento`**,
por decisão **D-08**, e não um esquema novo.

`materializarCertificado()` escreve o certificado em `storage/certificados` criando a pasta
com `0700` e o arquivo com `0600` **antes de ele ter conteúdo** — a ordem importa: criar
primeiro e restringir depois deixa uma janela em que o segredo está legível para o sistema
todo.

### Passo 2 — A troca da fonte · `3d66377 feat(pagamentos): read credentials from database`

| Arquivo | Ação | Diff |
|---|---|---|
| `app/Services/Payments/Efi/ConfiguracaoEfi.php` | **modify** | +215 / −26 |
| `tests/Feature/Pagamentos/CredenciaisPagamentoTest.php` | modify | +124 |

**Este é o único arquivo do provedor que a fase inteira tocou.** A classe ganhou
`credencialAtiva()`, `paraCredencial()`, `origem()`, `recarregar()`,
`esquecerTokensGuardados()`, `estaCompleta()`, `certificadoExiste()` e `exigirCompleta()`,
e os métodos de leitura (`clientId()`, `clientSecret()`, `caminhoDoCertificado()`,
`chavePix()`, `segredoDoWebhook()`) passaram a consultar o cadastro antes da configuração.

Duas sutilezas que o plano pedia e que valem registro:

- **A precedência não se mistura** (**DA-26**). Quando há cadastro ativo, ele responde por
  tudo; o arquivo de ambiente **não completa** o que falta. Um cadastro pela metade falha
  dizendo o que falta, em vez de cobrar com meia credencial da tela e meia do servidor —
  provavelmente de ambientes diferentes. Provado por
  `it('nao completa com o arquivo de ambiente o que falta no cadastro ativo')`.
- **A leitura tolera não haver banco.** A consulta é protegida para o caso de rodar durante
  migração ou `config:cache`, antes de existir schema.

### Passo 3 — Permissão e auditoria · `b2fdccc feat(pagamentos): add credential permission and audit trail`

| Arquivo | Ação | Diff |
|---|---|---|
| `app/Actions/Pagamentos/SalvarCredencialPagamento.php` | create | 124 linhas |
| `app/Actions/Pagamentos/AtivarAmbientePagamento.php` | create | 103 linhas |
| `app/Enums/AcaoAuditada.php` | modify | **+12 / −0** (acréscimo de caso, zero remoção) |
| `app/Models/CredencialPagamento.php` | modify | +59 / −1 |
| `database/seeders/PapeisSeeder.php` | modify | +7 |
| `tests/Feature/Auditoria/AuditoriaCredenciaisTest.php` | create | 176 linhas |

A permissão `pagamentos.credenciais` entrou no seeder idempotente da 6a (**D-50**) e, o que
importa mais, na constante `FORA_DO_ORGANIZADOR` — junto com `pagamentos.confirmar-manual`,
`usuarios.gerenciar` e `auditoria.ver`. É **exclusiva do administrador** (**D-55**).

As duas Actions chamam `ConfiguracaoEfi::recarregar()`, que por sua vez chama
`esquecerTokensGuardados()` e apaga o token guardado **dos dois ambientes**. Sem isso, o
sistema continuaria falando com a Efí usando a credencial antiga por até uma hora depois da
troca — e ninguém desconfiaria.

O rastro grava **quais campos** mudaram, nunca os valores; e a troca de ambiente grava o
nome do anterior e o do novo, porque nome de ambiente não é segredo.

### Passos 4 e 5 — Backend e tela · `3cd18f3 feat(pagamentos): add credential management endpoints`

> **Este commit contém dois passos do plano.** Ver "Como esta fase foi executada".

| Arquivo | Ação | Diff |
|---|---|---|
| `app/Http/Controllers/Admin/CredenciaisPagamentoController.php` | create | 249 linhas |
| `app/Http/Requests/Admin/SalvarCredencialPagamentoRequest.php` | create | 126 linhas |
| `resources/js/pages/Admin/Pagamentos/Credenciais/Index.vue` | create | **459 linhas** |
| `routes/web.php` | modify | +25 |
| `resources/js/components/AppSidebar.vue` | modify | +12 / −4 |
| `app/Services/Payments/Efi/ConfiguracaoEfi.php` | modify | +22 |
| `tests/Feature/Pagamentos/CredenciaisPagamentoTest.php` | modify | +272 / −2 |

Quatro rotas, todas atrás de `permission:pagamentos.credenciais`: `GET /`,
`POST {ambiente}`, `POST {ambiente}/ativar` e `POST {ambiente}/testar`. **O ambiente vai na
URL e não no corpo**, para que o rastro do servidor registre em qual deles se mexeu mesmo
quando o envio falha.

A tela tem os dois blocos independentes, o gerador do valor do aviso, o endereço do webhook
montado pronto para copiar (com `?hmac=` e `?ignorar=`), o envio do certificado, a troca de
ambiente com confirmação escrita e o botão de testar conexão.

**O teste de conexão da tela não emite cobrança**, ao contrário do `efi:diagnostico` de
terminal: ele confere configuração completa, certificado que abre e não venceu, e token
aceito pela Efí. O motivo é direto — a tela roda em produção, e cobrança de teste ali seria
dinheiro de verdade. Isso é um **desvio consciente** do texto do plano, que dizia
"reaproveita o diagnóstico da 8a"; o que se reaproveitou foi a sequência de verificação, não
o passo de emissão.

### Passo 6 — A prova · `31b9d17 test(pagamentos): prove the credential screen in the browser`

| Arquivo | Ação | Diff |
|---|---|---|
| `tests/e2e/credenciais-pagamento.spec.ts` | create | 174 linhas, 4 cenários |
| `tests/Feature/Admin/AutorizacaoTest.php` | modify | +4 / −4 (contagem de permissões: nove → dez) |

Os quatro cenários: quem administra cadastra homologação e salva, e o que salvou não volta
para a tela; quem organiza o evento recebe a porta fechada, não uma tela vazia; passar a
cobrar de verdade exige confirmação escrita; o teste de conexão diz o que falta sem repetir
o que foi digitado.

**Nenhum cenário de ponta a ponta envia certificado, de propósito** — certificado não entra
em repositório. Quem prova o caminho do upload é o Pest, que fabrica o arquivo em tempo de
execução.

### Passo 6 (continuação) — Documentação · este commit

| Arquivo | Ação | Descrição |
|---|---|---|
| `docs/ARCHITECTURE.md` | modify | versão 1.3; seção **8.4 nova** (onde a configuração mora), 8.3 e a ordem de ligar reescritas |
| `docs/PAYMENTS.md` | modify | versão 2.1; seção **10 reescrita** — passo a passo da tela para quem não programa |
| `docs/PROGRESS.md` | modify | Etapa 18, decisões **DA-25 a DA-27**, Fase 8b concluída, "Em andamento" e "Próximas tarefas" reescritas |
| `docs/IMPLEMENTATION_PLAN.md` | modify | versão 1.8; Fase 8b ✅, linha `8 (8a+8b)` na visão geral, "todo o escopo planejado entregue" |
| `.planning/.../plan.done.md` | create | este relatório |

---

## Critérios de qualidade (§5 do plano)

Evidência real, com o comando que a produziu. **Onde não houve comando, está dito.**

### Guarda do segredo

| Critério | Status | Evidência |
|---|:--:|---|
| Cinco campos cifrados em repouso, provado com `DB::table()` cru | ✅ | `it('nao deixa nada legivel na linha crua do banco')` — `tests/Feature/Pagamentos/CredenciaisPagamentoTest.php:56`. Verde em `php artisan test` |
| Nenhum segredo volta nas props do Inertia | ✅ | `it('nao devolve nada sigiloso nas props da tela')` (:414) e `it('nao devolve nenhum valor sigiloso no retrato que vai para a tela')` (:115). Cenário Playwright `quem administra cadastra homologação, salva, e o que salvou não volta para a tela` confere também o HTML cru |
| Nenhum segredo em `logs_auditoria` | ✅ | `AuditoriaCredenciaisTest`: `it('registra a alteracao da credencial sem guardar um unico valor')` (:62) e `it('nunca guarda valor nenhum em nenhum registro de auditoria desta tela')` (:159) |
| Nenhum segredo em log, exceção ou resposta de erro | ⚠️ **parcial** | Provado **na resposta de erro**: `it('nao expoe valor nenhum na resposta do teste de conexao')` (:612) e `it('diz o que falta quando o teste de conexao roda sem cadastro')` (:598). **Não foi escrito teste que varra o log de aplicação** — a garantia aqui é de leitura de código, não de comando |
| Certificado materializado com permissão restrita, fora do repositório, coberto pelo `.gitignore` | ✅ | `it('materializa o certificado com permissao restrita e fora do repositorio')` (:201) e `it('reescreve o certificado quando ele some do disco, porque o arquivo e cache')` (:227). `.gitignore` linhas 25–32 cobrem `/storage/certificados`, `*.p12`, `*.pem`, `*.pfx` — **já desde a 8a** (`11e0a5d`), esta fase não precisou tocá-lo |
| Campo vazio mantém o valor guardado | ✅ | `it('mantem o valor guardado quando o campo chega vazio')` (:451) |

### Controle de acesso e rastro

| Critério | Status | Evidência |
|---|:--:|---|
| Rota exige `permission:pagamentos.credenciais`; organizador recebe 403 | ✅ | `it('recusa a tela de credenciais para o organizador')` (:399), `it('da a permissao de credenciais so ao administrador')` (:391), `it('exige estar autenticado para abrir a tela de credenciais')` (:410). No navegador: cenário `quem organiza o evento recebe a porta fechada, não uma tela vazia` |
| Toda alteração e toda troca de ambiente geram registro | ✅ | `AuditoriaCredenciaisTest:62` e `:98` (`registra a troca de ambiente com o nome do anterior e o do novo`) |
| Falha de auditoria não desfaz a gravação | ✅ | `it('nao desfaz a gravacao quando a auditoria falha')` — `AuditoriaCredenciaisTest:121` |
| Trocar para produção exige confirmação explícita | ✅ | `it('recusa ativar producao sem confirmacao explicita')` (:530) — **cobrada no servidor**, não só na tela. Cenário Playwright `passar a cobrar de verdade exige confirmação escrita` |

### Correção

| Critério | Status | Evidência |
|---|:--:|---|
| Um ativo por gateway garantido pelo banco | ✅ | `it('deixa o banco recusar um segundo ambiente ativo do mesmo provedor')` (:142) espera a exceção do PostgreSQL; `it('permite dois ambientes cadastrados desde que so um esteja ativo')` (:189) prova o outro lado. Índice `credenciais_pagamento_um_ativo_por_gateway ... WHERE ativo = true` na migration |
| Alterar credencial invalida o cache do token | ✅ | `it('joga fora o token dos dois ambientes quando a credencial muda')` (:356), `it('joga fora o token guardado ao salvar uma credencial pela tela')` (:369), `it('guarda o token da Efi exatamente sob a chave que a configuracao calcula')` (:343) |
| Cadastro vazio cai para o arquivo de ambiente | ✅ | `it('cai para o arquivo de ambiente quando nao ha cadastro nenhum')` (:253) e `it('faz o cadastro ativo vencer o arquivo de ambiente')` (:271) |
| Teste de conexão usa a mesma lógica do `efi:diagnostico`, em português, sem expor credencial | ⚠️ **com desvio** | Sem exposição: provado (:598, :612). **A lógica é a mesma exceto pelo passo de emissão de cobrança, que a tela não executa de propósito** — ver passo 4/5. Não há teste que compare os dois caminhos linha a linha |
| URL do webhook com HMAC e `?ignorar=`, pronta para colar | ⚠️ **provado na tela, não no Pest** | A montagem está em `Index.vue` e aparece no cenário Playwright do cadastro. **Não há teste Pest dedicado à composição da URL** |

### O que não pode ter mudado

| Critério | Status | Evidência (comando desta rodada) |
|---|:--:|---|
| `git diff --stat` sobre `app/Services/Payments/Efi/` mostra apenas `ConfiguracaoEfi.php` | ✅ | `git diff --stat 8e7fb20..HEAD -- app/Services/Payments/Efi/` → `1 file changed, 237 insertions(+), 26 deletions(-)`, e o arquivo é `ConfiguracaoEfi.php` |
| Nenhuma alteração em `EfiPaymentGateway`, `EfiClient`, Actions de pagamento, Models de inscrição ou regra de domínio | ✅ | `git diff --stat 8e7fb20..HEAD -- app/Actions/ app/Models/ app/Enums/` → **só arquivos novos** (`AtivarAmbientePagamento`, `SalvarCredencialPagamento`, `AmbientePagamento`, `CredencialPagamento`) mais `AcaoAuditada.php` com **+12 / −0**. Nenhuma linha existente removida em lugar nenhum |
| A suíte Pest da Fase 8a continua verde sem edição | ✅ | Nenhum arquivo de teste da 8a aparece no diff da fase; `php artisan test` verde |
| Cenários Playwright anteriores verdes sem edição | ⚠️ **não re-executado nesta rodada** | Os 32 arquivos anteriores não foram tocados (`git diff` da fase só adiciona `credenciais-pagamento.spec.ts`). **A execução verde foi feita na rodada que produziu `31b9d17`; esta rodada não subiu servidor para re-rodar Playwright** |

### Testes

| Critério | Status | Evidência (comando desta rodada) |
|---|:--:|---|
| Suíte Pest | ✅ | `php artisan test` → **522 passed (3.661 assertions)**, 60,24s. Base antes da 8b: 488/3.455 → **+34 testes, +206 asserções** |
| `vendor/bin/pint --test` | ✅ | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | ✅ | `eslint . --fix` sem saída e sem alterar arquivo nenhum (`git status` limpo depois) |
| `npx vue-tsc --noEmit` | ✅ | saída vazia, `exit=0` |
| `composer audit` | ✅ | `No security vulnerability advisories found.` |
| Playwright: 4 cenários novos + 32 anteriores | ⚠️ **contagem estática apenas** | `grep -h "^test(" tests/e2e/*.spec.ts \| wc -l` → **36**, sendo 4 em `credenciais-pagamento.spec.ts`. **A execução no navegador não foi repetida nesta rodada** |

---

## Verificação (comandos rodados nesta rodada)

| Comando | Resultado |
|---|---|
| `php artisan test` | **522 passed (3661 assertions)** — 60,24s |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | limpo, sem alterações |
| `npx vue-tsc --noEmit` | zero erros (`exit=0`) |
| `composer audit` | `No security vulnerability advisories found.` |
| `git diff --stat 8e7fb20..HEAD -- app/Services/Payments/Efi/` | 1 arquivo: `ConfiguracaoEfi.php` |
| `git diff --stat 8e7fb20..HEAD -- app/Actions/ app/Models/ app/Enums/` | 5 arquivos, **610 inserções e 0 remoções** |
| `npx playwright test` | ❌ **não executado nesta rodada** (exige servidor de pé). Última execução verde: rodada de `31b9d17` |

---

## Desvios do plano

1. **Passos 4 e 5 num commit só** (`3cd18f3`), por causa da quebra de rodada. O plano previa
   dois commits.
2. **`.gitignore` não foi alterado.** O plano listava o arquivo em §4 como `modify`; a
   cobertura (`/storage/certificados`, `*.p12`, `*.pem`, `*.pfx`) **já existia desde a 8a**
   (`11e0a5d`). Critério atendido, arquivo não tocado.
3. **Dois arquivos fora da tabela §4 foram alterados**, e ambos eram necessários:
   `resources/js/components/AppSidebar.vue` (o item de menu, que o plano descreve em §3.5
   mas não lista) e `tests/Feature/Admin/AutorizacaoTest.php` (a contagem de permissões, que
   passou de nove para dez por causa da permissão nova). **Nenhuma asserção foi afrouxada** —
   só o número literal mudou.
4. **O teste de conexão da tela não emite cobrança**, ao contrário do `efi:diagnostico`. O
   plano dizia "reaproveita o diagnóstico da 8a"; reaproveitou-se a sequência de verificação,
   não o passo de emissão, porque a tela roda em produção e cobrança de teste ali é dinheiro
   de verdade. Desvio consciente, documentado em `PAYMENTS.md` §10.
5. **Três rodadas de executor**, com duas retomadas de contexto zero. Ver a seção própria.

---

## O que ficou por fazer

**Fora do escopo por decisão do plano (§1) — não é dívida escondida:**

- Rotação automática de credencial e alerta de vencimento de certificado. A tela mostra a
  validade quando o formato permite lê-la, e nada mais. **No dia em que o certificado
  vencer, toda cobrança nova para de funcionar de uma vez.**
- Qualquer provedor além da Efí. A coluna `gateway` existe para o futuro; a tela fala de Efí.

**Provas que não foram feitas, e que valem a pena existir um dia:**

- Teste que varra o log de aplicação atrás de segredo (hoje a garantia é leitura de código).
- Teste Pest dedicado à composição da URL do webhook (hoje só o Playwright a exercita).
- Re-execução do Playwright nesta rodada de fechamento.

**O que falta para cobrar dinheiro de verdade — e nada disso é código:**

1. Cadastrar credenciais e certificado reais **pela tela nova**, começando por homologação,
   e usar o botão *Testar conexão* antes de qualquer outra coisa (`PAYMENTS.md` §10).
2. Publicar em **HTTPS válido e público**, com a **cadeia de certificados da Efí instalada
   no servidor web** e verificação do certificado do cliente como opcional — é assim que o
   aviso automático chega autenticado por mTLS (`ARCHITECTURE.md` §8.3).
3. **Registrar o endereço do aviso no painel da Efí.** Sem isso, as cobranças nascem
   normalmente e **nenhuma se confirma sozinha**, sem erro na tela e sem ninguém reclamar.

**Pendências que continuam abertas com o dono do produto:**

- **P-02** — política de reembolso. Enquanto não houver, não existe estorno:
  `refundPayment()` lança "não suportado" em voz alta (**DA-18**), e o `endToEndId` que a
  devolução vai exigir já está sendo guardado desde a 8a.
- **P-06** — taxa efetiva, tarifas de conta e prazo de liquidação confirmados com o
  comercial da Efí. Não bloqueia a operação (**DA-23**), bloqueia a precificação do evento.

**O maior buraco conhecido do projeto, e ele não é desta fase:**

- **A revisão de LGPD nunca foi feita.** Não há política de retenção, não há prazo de
  descarte e não há anonimização de dado pessoal neste sistema. Ficou fora da Fase 9 **de
  propósito** (**D-76**), porque descarte de dado pessoal é decisão jurídica, e depende da
  **P-04** e da **P-03**.

**E a que continua valendo desde a Fase 7:**

- **Ninguém subiu o trabalhador da fila.** Sem `php artisan queue:work redis --queue=emails`
  de pé, como serviço supervisionado, **nenhum e-mail chega a ninguém** — sem erro na tela,
  sem aviso, sem nada de errado aparecendo para quem se inscreveu.

---

## Commit

- **Mensagem:** `docs(pagamentos): close phase 8b`
- **Arquivos:** `docs/ARCHITECTURE.md`, `docs/PAYMENTS.md`, `docs/PROGRESS.md`,
  `docs/IMPLEMENTATION_PLAN.md`, `.planning/feat/features/fase-8b-credenciais-pela-interface/plan.done.md`
- **Não commitado, de propósito:** `Prompt para Claude Code — Plataforma de Inscrições e
  Gestão de Eventos.md`, arquivo solto na raiz que não pertence a esta fase.
- **Commits anteriores da fase:** `4f7b94b`, `3d66377`, `b2fdccc`, `3cd18f3`, `31b9d17`.
