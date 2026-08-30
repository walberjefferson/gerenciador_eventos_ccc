# Action Plan — Fase 8b: credenciais e certificado cadastrados pela interface

> **Type:** feature
> **Created:** 2026-08-27
> **Status:** pending
> **Ordem:** segundo dos dois planos da Fase 8. **Depende da Fase 8a**
> (`fase-8a-provedor-pagamento-efi`), que precisa estar concluída e com a integração
> provada. Sem ela, não há o que configurar.

---

## 1. Persona & Scope

**Persona:** Senior Full-stack Engineer **Laravel 12 + PHP 8.4 + Vue 3.5 + Inertia**, com
prática em guarda de segredo em repouso, upload de arquivo sensível e controle de acesso.
Entende que uma tela que guarda credencial de instituição financeira é a tela **mais
perigosa do sistema**, e a trata assim.

**Scope — Fase 8b:** tirar a configuração do provedor de pagamento do `.env` e colocá-la
numa tela do painel, para **homologação e produção**, sem que o gateway da Fase 8a precise
saber que a fonte mudou.

| Entrega | Nesta fase |
|---------|:----------:|
| Tabela `credenciais_pagamento` com os segredos **cifrados em repouso** | ✅ |
| Cadastro por ambiente: **homologação** e **produção**, independentes | ✅ |
| Upload do certificado `.p12`, cifrado no banco e materializado em arquivo no uso | ✅ |
| Troca de ambiente ativo pela tela | ✅ |
| Permissão própria, restrita ao **administrador** | ✅ |
| Toda alteração registrada em `logs_auditoria` | ✅ |
| Teste de conexão pela tela (reaproveita o diagnóstico da 8a) | ✅ |
| `ConfiguracaoEfi` passa a ler do banco, com o `.env` como reserva | ✅ |
| Cenários Playwright da tela nova | ✅ |
| Qualquer mudança no `EfiPaymentGateway`, `EfiClient` ou no domínio | ❌ **proibido** (§7) |
| Cadastro de outros provedores além da Efí | ❌ fora do escopo |
| Rotação automática de credencial, alerta de expiração de certificado | ❌ fora do escopo |

**Stack:** PHP 8.4 · Laravel 12 · PostgreSQL 18 · Vue 3.5 + Inertia + TypeScript ·
Tailwind · Pest 4 · Playwright.

---

## 2. Direct Objective

Permitir que quem administra cadastre, pela tela, as credenciais e o certificado da Efí
para homologação e para produção, com os segredos cifrados em repouso e cada alteração
deixando rastro — e fazer o sistema passar a usar esse cadastro **sem alterar uma linha do
gateway**, porque a Fase 8a já deixou toda a leitura atrás de `ConfiguracaoEfi` (DA-24).

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas — **NÃO reabrir**

| # | Tema | Decisão | Origem |
|---|------|---------|--------|
| **DA-25** | Fonte da verdade | **Banco, cifrado**, inclusive o conteúdo do certificado. Materializado em arquivo temporário com permissão restrita quando o SDK precisar. Motivo: sobrevive a redeploy e a container efêmero, e o backup do banco leva tudo junto | entrevista |
| **DA-26** | Precedência | **Banco tem precedência; o `.env` continua valendo como reserva.** Sem isso, o CI e a máquina de desenvolvimento precisariam de banco semeado com segredo para rodar qualquer coisa | derivada da DA-20 |
| **DA-27** | Ambientes | **Dois registros independentes**, `homologacao` e `producao`, e um indicador de qual está ativo. Trocar de ambiente é ação de tela, auditada | entrevista |
| **DA-24** (da 8a) | Ponto único | Toda leitura de configuração já passa por `ConfiguracaoEfi`. **Esta fase troca o corpo dessa classe e mais nada do lado do provedor** | Fase 8a |

**Decisões anteriores que esta fase deve preservar:**

| # | O que diz | Por que importa aqui |
|---|---|---|
| **D-08** | CPF cifrado no banco | Usar **o mesmo mecanismo** de cifragem para os segredos — não inventar outro |
| **D-51** | Conta administrativa só por comando | A tela **não** cria conta nem eleva permissão |
| **D-55** | Ação sensível exige permissão própria | Credencial de pagamento é o caso mais sensível que existe neste sistema |
| **D-77/D-78** | `logs_auditoria` é append-only de verdade | O registro desta tela não pode ser apagável pela aplicação |
| **Fase 9** | Falha de auditoria **não** desfaz a ação | Continua valendo |

### 3.2 O que a Fase 8a deixou pronto

Antes de escrever qualquer linha, **ler**:

| Arquivo | Por quê |
|---|---|
| `app/Services/Payments/Efi/ConfiguracaoEfi.php` | **é o único arquivo do provedor que esta fase altera** |
| `app/Services/Payments/Efi/EfiClient.php` | para confirmar que **não** precisa mudar |
| `app/Console/Commands/DiagnosticoEfi.php` | o teste de conexão da tela reaproveita esta lógica |
| `.planning/feat/features/fase-8a-provedor-pagamento-efi/plan.done.md` | o relatório do que foi feito e dos desvios |
| `config/payments.php` | o bloco `efi` que vira reserva |
| `app/Models/LogAuditoria.php` · `app/Services/Auditoria/RegistrarAcao.php` | como registrar |
| `app/Enums/AcaoAuditada.php` | ganha um caso novo (§3.4) |
| `database/seeders/` (o seeder de papéis e permissões da 6a) | onde a permissão nova entra |
| `app/Http/Controllers/Admin/` + `resources/js/pages/Admin/` | o padrão de tela a seguir |
| `resources/js/pages/Admin/Auditoria/Index.vue` | a tela mais recente, boa referência de estilo |

### 3.3 A tabela

`credenciais_pagamento` — uma linha por ambiente:

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | id | |
| `gateway` | string(40) | `efi` por enquanto; a coluna existe para o dia em que houver outro |
| `ambiente` | string(20) | `homologacao` ou `producao`, com `CHECK` no banco |
| `client_id` | text | **cifrado** |
| `client_secret` | text | **cifrado** |
| `certificado` | text | **cifrado** — o conteúdo do arquivo, não o caminho |
| `certificado_nome` | string(190) | nome original, só para a tela mostrar |
| `certificado_expira_em` | timestampTz nullable | lido do certificado no upload, se possível |
| `chave_pix` | text | **cifrado** |
| `webhook_hmac` | text | **cifrado** |
| `ativo` | boolean | qual ambiente o sistema usa |
| `atualizado_por_id` | FK usuarios nullable | `restrict`, como as demais |
| `timestampsTz` | | |

Restrições:

- **Unicidade** de `(gateway, ambiente)` — não existem dois cadastros do mesmo ambiente.
- **No máximo um `ativo` por gateway**, garantido por índice único parcial no banco
  (`WHERE ativo = true`) — e **não** por verificação em PHP. É a mesma lição da D-66:
  quem impede o segundo é o banco.
- `CHECK` em `ambiente`.

### 3.4 Auditoria e permissão

- **Permissão nova:** `pagamentos.credenciais` — **só do papel `administrador`**, nunca do
  organizador. Entra no seeder idempotente da 6a (D-50).
- **`AcaoAuditada` ganha `AlterouCredencialPagamento`** com rótulo em português. É acréscimo
  de caso, não mudança de comportamento.
- **O registro de auditoria NUNCA pode conter o segredo.** Grava-se *que* mudou e *quais
  campos* mudaram — jamais o valor, nem antes nem depois. Isso é critério de qualidade
  verificável (§5) e repete o que o `AuditoriaTest` da Fase 9 já prova para CPF e Pix.
- Trocar o ambiente ativo é ação auditada, com o ambiente anterior e o novo no registro
  (o nome do ambiente não é segredo).

### 3.5 A tela

`Admin/Pagamentos/Credenciais/Index.vue` — uma página, dois blocos (homologação e produção),
seguindo o padrão visual das telas da 6a/6b.

Cada bloco mostra e permite alterar:

- Credenciais da aplicação (dois campos)
- Chave Pix da conta recebedora
- Valor do HMAC do webhook — com um botão de **gerar um valor aleatório forte**, porque
  ninguém deve inventar esse valor à mão
- Upload do certificado, com o nome e a validade do atual quando houver
- Indicador de **ambiente ativo**, com troca
- Botão **testar conexão**

Regras da tela:

- **Segredo já gravado nunca volta para a tela.** O campo aparece vazio, com a indicação de
  que existe um valor guardado. Campo vazio no envio significa "manter o que está lá", não
  "apagar".
- A URL do webhook a registrar na Efí é **mostrada pronta para copiar**, já com o HMAC e o
  `?ignorar=` (C-2 e C-6 da Fase 8a). É o que a pessoa vai colar no painel da Efí.
- **Trocar para `producao` pede confirmação explícita**, porque a partir dali é dinheiro de
  verdade.

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*_create_credenciais_pagamento_table.php` | create | §3.3, com `CHECK`, unicidade e o índice parcial de "um ativo só" |
| `app/Models/CredencialPagamento.php` | create | conversões `encrypted` nos cinco campos sigilosos; `materializarCertificado()` escrevendo com permissão restrita |
| `app/Enums/AmbientePagamento.php` | create | `Homologacao` / `Producao`, com `rotulo()` |
| `app/Enums/AcaoAuditada.php` | modify | caso novo `AlterouCredencialPagamento` |
| `app/Services/Payments/Efi/ConfiguracaoEfi.php` | **modify** | **o único arquivo do provedor tocado:** passa a ler do banco, com o `.env` como reserva (DA-26) |
| `app/Http/Controllers/Admin/CredenciaisPagamentoController.php` | create | exibir, salvar, ativar ambiente, testar conexão |
| `app/Http/Requests/Admin/SalvarCredencialPagamentoRequest.php` | create | validação, inclusive do arquivo enviado (extensão, tamanho, e que abre de verdade) |
| `app/Actions/Pagamentos/SalvarCredencialPagamento.php` | create | grava, registra auditoria **sem segredo**, invalida o cache de token da 8a |
| `app/Actions/Pagamentos/AtivarAmbientePagamento.php` | create | troca o ativo em transação, registra auditoria |
| `resources/js/pages/Admin/Pagamentos/Credenciais/Index.vue` | create | §3.5 |
| `routes/web.php` | modify | rotas atrás de `permission:pagamentos.credenciais` |
| `database/seeders/*` (papéis e permissões) | modify | permissão nova, só para `administrador`, de forma idempotente |
| `.gitignore` | modify | a pasta dos certificados materializados |
| `tests/Feature/Pagamentos/CredenciaisPagamentoTest.php` | create | §5 |
| `tests/Feature/Auditoria/AuditoriaCredenciaisTest.php` | create | registra sem vazar segredo |
| `tests/e2e/credenciais-pagamento.spec.ts` | create | cenários da tela |
| `docs/ARCHITECTURE.md` | modify | a seção da 8a passa a descrever o cadastro pela tela; o `.env` vira reserva |
| `docs/PAYMENTS.md` | modify | como configurar pela tela |
| `docs/PROGRESS.md` | modify | Etapa 18, decisões DA-25 a DA-27, **Fase 8 concluída (8a + 8b)** |
| `docs/IMPLEMENTATION_PLAN.md` | modify | Fase 8 concluída |
| `.planning/feat/features/fase-8b-credenciais-pela-interface/plan.done.md` | create | relatório |

---

## 5. Quality Criteria

### Guarda do segredo

- [ ] Os cinco campos sigilosos estão **cifrados em repouso** — provar lendo a linha com
      `DB::table()` cru e verificando que **nada** é legível
- [ ] **Nenhum segredo volta para a tela**, em nenhuma resposta do Inertia. Provar
      inspecionando as props da página
- [ ] **Nenhum segredo em `logs_auditoria`** — teste dedicado, no espírito do
      `AuditoriaTest` da Fase 9
- [ ] Nenhum segredo em log de aplicação, em mensagem de exceção ou em resposta de erro
- [ ] O arquivo materializado do certificado nasce com **permissão restrita**, fora do
      repositório, e o `.gitignore` o cobre
- [ ] Campo enviado vazio **mantém** o valor guardado; nunca apaga

### Controle de acesso e rastro

- [ ] A rota exige `permission:pagamentos.credenciais`; **o organizador recebe 403** —
      teste explícito, como a tela de auditoria da Fase 9
- [ ] Toda alteração e toda troca de ambiente geram registro em `logs_auditoria`
- [ ] Falha de auditoria **não** desfaz a gravação (Fase 9)
- [ ] Trocar para `producao` exige confirmação explícita na tela

### Correção

- [ ] **Um `ativo` por gateway garantido pelo banco**, não por PHP — provar tentando
      ativar dois e esperando a exceção do PostgreSQL
- [ ] Alterar credencial **invalida o cache do token** da Fase 8a — senão o sistema segue
      usando a credencial antiga por até uma hora
- [ ] Com o cadastro vazio, o sistema **cai para o `.env`** e continua funcionando (DA-26)
- [ ] O teste de conexão da tela usa a mesma lógica do `efi:diagnostico` e informa erro em
      português, **sem expor credencial**
- [ ] A URL do webhook exibida traz o HMAC e o `?ignorar=`, pronta para colar

### O que não pode ter mudado

- [ ] `git diff --stat` sobre `app/Services/Payments/Efi/` mostra **apenas
      `ConfiguracaoEfi.php`**
- [ ] **Nenhuma alteração** em `EfiPaymentGateway`, `EfiClient`, Actions de pagamento,
      Models de inscrição ou regra de domínio
- [ ] A suíte Pest da Fase 8a continua verde **sem edição**
- [ ] Os cenários Playwright anteriores continuam verdes **sem edição**

### Testes

- [ ] Playwright: administrador cadastra homologação e salva · organizador recebe 403 ·
      segredo não aparece na tela após salvar · troca para produção pede confirmação
- [ ] `vendor/bin/pint --test` · `npm run lint` · `npx vue-tsc --noEmit` · `composer audit`

---

## 6. Ambiguity Handling

**Assumptions made:**

- **O certificado vai cifrado para o banco e é materializado em arquivo no uso** (DA-25).
  O arquivo materializado é cache, não fonte da verdade: pode ser apagado a qualquer
  momento e é reescrito na próxima chamada.
- **A validade do certificado é lida no upload quando o formato permitir.** Se não for
  possível ler, a coluna fica nula e a tela não mostra validade — não é motivo para
  recusar o upload.
- **A permissão é só do administrador.** Organizador não vê a tela nem o item de menu.
- **Nenhum outro provedor entra.** A coluna `gateway` existe para o futuro, mas a tela
  fala de Efí.

**If unsure during execution:**

- **Se algo exigir mudar `EfiPaymentGateway` ou `EfiClient`, PARE.** Significa que a DA-24
  não foi cumprida na Fase 8a — é problema a corrigir lá, não a contornar aqui.
- **Na dúvida sobre exibir um valor na tela, não exiba.** O custo de esconder demais é um
  clique; o de mostrar de menos é uma credencial vazada.
- **Se a Fase 8a não estiver concluída e provada, PARE.** Este plano não faz sentido sozinho.
- **Commite ao fim de cada step.**

---

## 7. Prohibitions

- ❌ **NUNCA** devolver segredo para o navegador, nem mascarado parcialmente
- ❌ **NUNCA** gravar segredo em `logs_auditoria`, em log ou em mensagem de erro
- ❌ **NUNCA** guardar segredo em claro no banco
- ❌ **NUNCA** commitar certificado, em nenhum formato
- ❌ **NUNCA** dar esta permissão ao papel `organizador`
- ❌ **NUNCA** alterar `EfiPaymentGateway`, `EfiClient`, Action, Model ou regra de domínio
- ❌ **NUNCA** garantir "um ativo só" apenas em PHP — é restrição de banco
- ❌ **NUNCA** deixar a troca para produção acontecer sem confirmação explícita
- ❌ **NUNCA** editar cenário Playwright existente
- ❌ **NUNCA** adicionar dependência nova sem justificativa escrita

---

## Execution Steps

1. **A tabela e o modelo.** Migration com `CHECK`, unicidade `(gateway, ambiente)` e o
   índice parcial de "um ativo só"; `CredencialPagamento` com as conversões cifradas e a
   materialização do certificado; `AmbientePagamento`. Testes provando que o banco recusa
   dois ativos e que nada é legível na linha crua.
   → commit `feat(pagamentos): add encrypted credential storage`

2. **A troca da fonte.** `ConfiguracaoEfi` passa a ler do banco com o `.env` como reserva
   (DA-26), e a invalidação do cache de token entra junto. **A suíte da Fase 8a tem de
   continuar verde sem edição** — é o que prova que a DA-24 valeu a pena.
   → commit `feat(pagamentos): read credentials from database`

3. **Permissão e auditoria.** `pagamentos.credenciais` no seeder idempotente, só para
   administrador; `AcaoAuditada` ganha o caso novo; as duas Actions gravando rastro **sem
   segredo**, com teste dedicado.
   → commit `feat(pagamentos): add credential permission and audit trail`

4. **Backend da tela.** Controller, FormRequest com validação do arquivo enviado, rotas
   atrás da permissão. Teste de 403 para o organizador e de "campo vazio mantém o valor".
   → commit `feat(pagamentos): add credential management endpoints`

5. **A tela.** `Index.vue` com os dois blocos, o gerador de HMAC, a URL do webhook pronta
   para copiar, o upload, a troca de ambiente com confirmação e o teste de conexão.
   → commit `feat(pagamentos): add credential management screen`

6. **A prova e o fechamento.** Cenários Playwright; suíte inteira; pint, lint, vue-tsc,
   `composer audit`; `ARCHITECTURE.md`, `PAYMENTS.md`, `PROGRESS.md` (Etapa 18, **Fase 8
   concluída**), `IMPLEMENTATION_PLAN.md` e o `plan.done.md`.
   → commit `docs(pagamentos): close phase 8b`

---

## Done

Quem administra cadastra credenciais e certificado da Efí pela tela, para homologação e
produção, com tudo cifrado em repouso, nenhum segredo voltando para o navegador, cada
alteração deixando rastro que ninguém apaga pela aplicação, e o organizador recebendo 403 —
e o gateway da Fase 8a passou a usar esse cadastro **com um único arquivo alterado**.

## Commit

`feat(pagamentos): manage efi credentials from the admin panel`
