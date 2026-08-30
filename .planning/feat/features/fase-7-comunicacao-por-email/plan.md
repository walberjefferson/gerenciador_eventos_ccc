# Action Plan — Fase 7: comunicação por e-mail, em fila

> **Type:** feature
> **Created:** 2026-08-20
> **Status:** pending
> **Ordem:** terceiro plano do lote. **Depende da 6b** — o anúncio `InscricaoCancelada` nasce lá.

---

## 1. Persona & Scope

**Persona:** Senior Backend Engineer **Laravel 12 + PHP 8.4**, com prática em eventos de domínio, filas Redis, Mailables e — o que decide o resultado aqui — **idempotência de envio**. Sabe que o pior e-mail é o que chega duas vezes, e o segundo pior é o que não chega.

**Scope — Fase 7:** ligar os anúncios que o domínio já dispara às mensagens que a pessoa recebe.

| Entrega | Nesta fase |
|---------|:----------:|
| Ouvintes de `InscricaoCriada`, `InscricaoConfirmada`, `InscricaoExpirada`, `InscricaoCancelada` | ✅ |
| Cinco e-mails (§3.3) | ✅ |
| Lembrete antes do prazo, momento configurável | ✅ |
| Tudo em fila Redis, com o worker documentado | ✅ |
| Registro de envio, para nunca mandar a mesma mensagem duas vezes | ✅ |
| Estrutura pronta para WhatsApp depois | ✅ (só o desenho, sem implementar) |
| Qualquer mudança em regra de inscrição ou pagamento | ❌ **proibido** |

**Stack:** PHP 8.4 · Laravel 12 · Redis (fila) · Mailpit em desenvolvimento · Pest 4 · Blade para os corpos de e-mail.

---

## 2. Direct Objective

O participante para de depender de ter deixado a aba aberta: recebe a cobrança por e-mail assim que se inscreve, um lembrete antes do prazo acabar, o comprovante quando o pagamento é reconhecido, e um aviso quando a inscrição expira ou é cancelada.

**Nenhuma regra de inscrição ou de pagamento muda.** Se para entregar esta fase for preciso tocar em `CriarInscricao`, `ConfirmarPagamento`, `ExpirarInscricoesVencidas` ou em qualquer Action de domínio, o desenho de eventos falhou — **pare e pergunte**.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas (NÃO reabrir)

| Tema | Decisão |
|------|---------|
| Escopo (**DA-14**) | **Os cinco e-mails** do plano original: inscrição criada, lembrete de prazo, pagamento confirmado, inscrição expirada, cancelamento |
| Entrega | **Em fila (Redis)**, sempre. Lentidão de servidor de e-mail nunca pode atrasar uma inscrição |
| Lembrete | Momento **configurável**, padrão **24 horas** antes do prazo |
| E-mail de acesso já existente | `LinkDeAcessoInscricao` (Fase 5b) é **síncrono** de propósito (D-44), porque responde a um pedido humano imediato. Esta fase **pode** migrá-lo para fila — mas só se o teste de resposta neutra de `POST /acesso` continuar passando, inclusive o piso de tempo de resposta (D-48) |

### 3.2 O que já existe (verificado — não reimplementar)

- Anúncios de domínio disparados e **sem nenhum ouvinte**: `InscricaoCriada`, `InscricaoConfirmada`, `InscricaoExpirada` (D-12) e `InscricaoCancelada` (criado na 6b).
- **Todos são disparados depois que a transação fecha, e só na chamada que de fato mudou a situação** (D-32). Isso é o que torna esta fase possível sem tocar em domínio.
- `App\Services\Inscricoes\GeradorLinkDeAcesso` — URL assinada da página de acompanhamento, validade configurável (Fase 5b). **É ele que gera o link de todo e-mail.** Não escreva outro.
- `App\Mail\LinkDeAcessoInscricao` + `resources/views/emails/link-de-acesso.blade.php` — o padrão visual e de linguagem a seguir.
- `config/inscricoes.php` — onde já moram validade de link e limites; o momento do lembrete entra aqui.
- `QUEUE_CONNECTION=redis` no `.env.example`; Redis de pé no `docker-compose.yml`. **Nenhum worker roda hoje** — nada no projeto usa fila ainda.
- `routes/console.php` — o padrão de comando agendado (`inscricoes:expirar-vencidas` a cada minuto, `pagamentos:reconciliar` a cada cinco).
- Mailpit no Sail: SMTP em 1025, interface em 8025.

### 3.3 Os cinco e-mails

Todos escritos para leigo, em português acolhedor (é uma comunidade católica — sem gíria, sem marketing agressivo), com o link assinado sempre gerado por `GeradorLinkDeAcesso`.

| E-mail | Dispara em | Contém |
|--------|-----------|--------|
| **Inscrição recebida** | `InscricaoCriada` | evento, valor, prazo, link para pagar |
| **Seu prazo está acabando** | comando agendado (§3.4) | quanto tempo falta, link para pagar |
| **Pagamento confirmado** | `InscricaoConfirmada` | comprovante: evento, valor, data do pagamento, atividades escolhidas, link do acompanhamento |
| **Prazo vencido** | `InscricaoExpirada` | o que aconteceu, que a vaga voltou para a fila, e o convite a se inscrever de novo se ainda houver vaga |
| **Inscrição cancelada** | `InscricaoCancelada` | que foi cancelada e por quem (organização), sem expor motivo interno cru se ele for anotação administrativa |

**Nunca no corpo do e-mail:** CPF, `documento_hash`, telefone, código Pix copia e cola completo ou qualquer contador interno. O e-mail leva **link**, não dado sensível — é a mesma regra da Fase 5b (D-44).

**Cancelamento e motivo:** o motivo registrado pelo organizador é anotação interna e pode conter observação que não se escreve para a pessoa. O e-mail diz que a inscrição foi cancelada pela organização e orienta o contato; **não** despeja o texto interno.

### 3.4 O lembrete de prazo

Comando `inscricoes:lembrar-prazo`, agendado. Busca inscrições em `aguardando_pagamento` cujo `prazo_pagamento` cai dentro da janela configurada (padrão: entre agora e 24 horas à frente) e que **ainda não receberam o lembrete**.

- Processa em lotes com `chunkById`, no mesmo molde de `ExpirarInscricoesVencidas` (D-35).
- Roda **a cada 15 minutos** — precisão suficiente para um aviso de 24 horas, e volume baixo.
- Opções `--janela=` (horas) e `--lote=`, no molde dos comandos existentes.
- **Rodar duas vezes não manda dois lembretes** — a garantia vem do registro de §3.5, não da sorte do agendador.

### 3.5 Não mandar a mesma mensagem duas vezes

Esta é a parte que separa uma fase de comunicação decente de uma que constrange.

Tabela nova **`comunicacoes_enviadas`** — e é a **única** tabela desta fase:

| Coluna | Papel |
|--------|-------|
| `id` | — |
| `inscricao_id` | FK `restrict` |
| `tipo` | qual mensagem (Enum `TipoComunicacao`) |
| `canal` | `email` por ora; a coluna existe para o WhatsApp entrar sem migração |
| `destino` | o endereço usado, para investigar entrega |
| `enviada_em` | quando |
| `timestamps` | — |

**Unicidade `(inscricao_id, tipo, canal)`** — é ela, no banco, que garante o "uma vez só". Não confie em `if` antes do envio: dois workers pegando o mesmo job ao mesmo tempo passariam os dois pelo `if`. O ouvinte grava o registro **primeiro**, dentro de transação; se a unicidade recusar, alguém já mandou e o job encerra em silêncio.

**Exceção deliberada:** o lembrete é único por inscrição, mas se o prazo for **estendido** algum dia (não existe hoje), a regra precisa mudar junto. Registre isso como comentário no Enum, não como código especulativo.

### 3.6 Fila, worker e o que acontece quando falha

- Todos os Mailables/Jobs desta fase implementam `ShouldQueue`, na conexão `redis`, fila `emails`.
- **`tries = 3`** com `backoff` crescente (1 min, 5 min, 15 min). Servidor de e-mail fora do ar é problema temporário e merece retentativa.
- Falha definitiva vai para `failed_jobs` (tabela padrão do Laravel — **rode a migration se ela ainda não existir**) e **não** derruba nada do domínio.
- O worker (`php artisan queue:work redis --queue=emails`) precisa estar documentado em `docs/ARCHITECTURE.md` e no `PROGRESS.md`: **hoje ninguém roda worker neste projeto**, e uma fase inteira de e-mail que depende de um processo que ninguém sobe é uma fase que não funciona. Diga isso em português claro, com o comando pronto para copiar.
- Em `testing`, a fila é síncrona (o padrão do Laravel em teste) — os testes usam `Mail::fake()` e `Queue::fake()` conforme o que estiverem provando.

### 3.7 O gancho de WhatsApp — desenho, não implementação

A coluna `canal` e o Enum `TipoComunicacao` são o gancho. **Não** crie interface, contrato, adaptador nem configuração de WhatsApp: isso é código sem uso, que envelhece antes de nascer. O que esta fase entrega é apenas o que **não** precisará ser desfeito depois: uma tabela com `canal` e um vocabulário de tipos de mensagem.

### 3.8 Armadilhas conhecidas deste projeto

- **`php artisan test` roda no HOST** (`phpunit.xml` fixa `127.0.0.1:55432`); dentro do Sail a conexão é recusada.
- **Nunca** rodar Pest e Playwright ao mesmo tempo (D-42).
- **O anúncio já vem depois da transação** (D-32) — não "melhore" isso.
- **Não** enfileire dentro de transação de domínio: o job pode rodar antes do commit e não achar a linha.
- **Executores morrem em ~60 chamadas de ferramenta.** Commite ao fim de cada step.

### 3.9 Arquivos a ler antes de começar

- `app/Mail/LinkDeAcessoInscricao.php` e `resources/views/emails/link-de-acesso.blade.php` — o padrão a seguir
- `app/Services/Inscricoes/GeradorLinkDeAcesso.php` — o gerador de link que todo e-mail usa
- `app/Actions/Inscricoes/ExpirarInscricoesVencidas.php` — o molde de comando em lote
- `routes/console.php` — o padrão de agendamento, com os comentários explicativos
- `app/Events/` — os quatro anúncios e o que cada um carrega
- `docs/PROGRESS.md` — decisões, com atenção a D-12, D-32, D-44 e D-48

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*_create_comunicacoes_enviadas_table.php` | create | §3.5, com a unicidade |
| `database/migrations/*_create_failed_jobs_table.php` | create | só se ainda não existir |
| `app/Enums/TipoComunicacao.php` | create | os cinco tipos, com `rotulo()` |
| `app/Models/ComunicacaoEnviada.php` | create | model do registro |
| `app/Mail/{InscricaoRecebidaMail,LembretePrazoMail,PagamentoConfirmadoMail,PrazoVencidoMail,InscricaoCanceladaMail}.php` | create | os cinco, todos `ShouldQueue` |
| `resources/views/emails/*.blade.php` | create | um corpo por mensagem |
| `app/Listeners/{EnviarEmailInscricaoRecebida,EnviarEmailPagamentoConfirmado,EnviarEmailPrazoVencido,EnviarEmailInscricaoCancelada}.php` | create | ouvintes, `ShouldQueue` |
| `app/Services/Comunicacao/RegistrarEnvio.php` | create | grava o registro e recusa repetição (§3.5) |
| `app/Console/Commands/LembrarPrazoPagamento.php` | create | `inscricoes:lembrar-prazo` |
| `routes/console.php` | modify | agendamento a cada 15 minutos |
| `config/inscricoes.php` | modify | janela do lembrete, fila e tentativas |
| `app/Providers/AppServiceProvider.php` (ou `EventServiceProvider`) | modify | registro dos ouvintes |
| `.env.example` | modify | `QUEUE_CONNECTION`, fila `emails`, remetente |
| `tests/Feature/Comunicacao/EmailsDoFluxoTest.php` | create | um e-mail por anúncio, com o conteúdo certo |
| `tests/Feature/Comunicacao/NaoDuplicaTest.php` | create | anúncio repetido não gera segundo e-mail |
| `tests/Feature/Comunicacao/LembretePrazoTest.php` | create | janela, lote, e rodar duas vezes não duplica |
| `tests/Feature/Comunicacao/SemDadoSensivelTest.php` | create | nenhum corpo contém CPF, telefone ou hash |
| `docs/ARCHITECTURE.md` | modify | como subir o worker |
| `docs/PROGRESS.md`, `docs/IMPLEMENTATION_PLAN.md` | modify | fechamento da fase |

---

## 5. Quality Criteria

### Funcional
- [ ] Cada um dos quatro anúncios de domínio produz **exatamente um** e-mail
- [ ] O mesmo anúncio disparado duas vezes produz **um** e-mail só — garantido pela unicidade no banco, provado por teste
- [ ] Dois workers processando o mesmo job em paralelo não produzem dois e-mails
- [ ] O lembrete sai uma vez por inscrição, dentro da janela configurada
- [ ] `inscricoes:lembrar-prazo` rodado duas vezes seguidas não manda nada na segunda
- [ ] Inscrição já confirmada, expirada ou cancelada **não** recebe lembrete
- [ ] Todo e-mail traz link assinado gerado por `GeradorLinkDeAcesso`, e o link abre a página certa
- [ ] **Nenhum** corpo de e-mail contém CPF, `documento_hash`, telefone ou Pix copia e cola completo
- [ ] O e-mail de cancelamento **não** despeja o motivo interno cru
- [ ] Falha de envio vai para `failed_jobs` e **não** afeta inscrição, vaga nem pagamento

### Qualidade
- [ ] `vendor/bin/pint --test` limpo · `npm run lint` limpo · `vue-tsc --noEmit` com zero erros
- [ ] Toda a suíte Pest continua verde, incluindo os testes de resposta neutra de `POST /acesso` (D-48)
- [ ] Os cenários Playwright continuam verdes, **sem serem editados**
- [ ] **Nenhuma** Action, Enum de domínio, Model de domínio ou migration existente alterada
- [ ] A única tabela nova é `comunicacoes_enviadas` (mais `failed_jobs`, se faltava)
- [ ] Nada é enfileirado de dentro de transação de domínio
- [ ] Nenhuma dependência nova
- [ ] O comando do worker está documentado em `ARCHITECTURE.md` e no `PROGRESS.md`

### Conteúdo dos e-mails
- [ ] Português acolhedor, sem jargão, legível por quem nunca usou o sistema
- [ ] Assunto que diz o que é sem precisar abrir
- [ ] Corpo legível em texto puro, não só em HTML
- [ ] Remetente configurável por `.env`, nunca fixo no código

---

## 6. Ambiguity Handling

**Assumptions made:**
- **A unicidade no banco é a trava anti-duplicidade**, não uma verificação em PHP. Dois workers passam pelo mesmo `if`; só o banco arbitra.
- **O lembrete é único por inscrição.** Se um dia existir prorrogação de prazo, esta regra muda junto — anotado no Enum, não implementado por antecipação.
- **O e-mail de cancelamento não repassa o motivo interno.** Anotação administrativa é escrita para a organização, não para a pessoa.
- **`LinkDeAcessoInscricao` pode migrar para fila**, desde que o teste do piso de tempo de resposta (D-48) continue passando. Se conflitar, **deixe síncrono** — a resposta neutra vale mais do que a uniformidade.
- **Fila `emails` separada da padrão**, para que um dia um worker dedicado não dispute com outro tipo de trabalho.
- **Sem preferência de recebimento (opt-out) nesta fase.** Todos os cinco e-mails são transacionais — são consequência de um ato da própria pessoa, não comunicação de marketing. Marketing exigiria opt-out, e não existe aqui.

**If unsure during execution:**
- Precisar tocar em Action de domínio → **PARE e pergunte**. É o sinal de que o desenho falhou.
- Dúvida de texto → escreva a frase mais simples possível e registre no PROGRESS.
- Dúvida sobre incluir um dado no e-mail → **não inclua**; ponha o link. Link é revogável, e-mail não.
- Anúncio que carrega pouca informação para montar a mensagem → carregue do banco dentro do job, pelo id; **não** engorde o evento de domínio.

---

## 7. Prohibitions

- ❌ **Nunca** alterar Action, Enum de domínio, Model de domínio ou migration existente
- ❌ **Nunca** mudar quando ou como um anúncio de domínio é disparado
- ❌ **Nunca** enfileirar de dentro de transação de domínio
- ❌ **Nunca** confiar em verificação em PHP para evitar e-mail duplicado
- ❌ **Nunca** colocar CPF, hash, telefone ou Pix copia e cola completo em corpo de e-mail
- ❌ **Nunca** repassar o motivo interno de cancelamento para o participante
- ❌ **Nunca** deixar falha de e-mail afetar inscrição, vaga ou pagamento
- ❌ **Nunca** implementar WhatsApp, adaptador ou contrato de canal (§3.7)
- ❌ **Nunca** criar tela nesta fase — é fase de backend
- ❌ **Nunca** editar os cenários Playwright existentes
- ❌ **Nunca** dar `git push` sem autorização explícita

---

## Execution Steps

1. **Base da comunicação.** Migration `comunicacoes_enviadas` com a unicidade `(inscricao_id, tipo, canal)`, `failed_jobs` se faltar, Enum `TipoComunicacao`, model `ComunicacaoEnviada`, serviço `RegistrarEnvio` e configuração em `config/inscricoes.php`. Teste da unicidade recusando repetição. → commit `feat(comunicacao): add sent-message registry`

2. **Inscrição recebida e pagamento confirmado.** Os dois Mailables com corpo Blade (HTML e texto), os dois ouvintes `ShouldQueue`, registro dos ouvintes e os testes de conteúdo com `Mail::fake()`. → commit `feat(comunicacao): add registration and payment emails`

3. **Prazo vencido e cancelamento.** Os dois Mailables e ouvintes restantes, com a regra de não repassar motivo interno. Teste cobrindo isso explicitamente. → commit `feat(comunicacao): add expiration and cancellation emails`

4. **Lembrete de prazo.** `LembrarPrazoPagamento` com `--janela` e `--lote`, `chunkById`, agendamento a cada 15 minutos em `routes/console.php` com o comentário explicativo no estilo do arquivo, e `LembretePrazoTest`: janela, situação errada não recebe, duas execuções não duplicam. → commit `feat(comunicacao): add payment deadline reminder`

5. **Idempotência e privacidade.** `NaoDuplicaTest` (anúncio repetido, dois workers) e `SemDadoSensivelTest` (nenhum corpo com CPF, hash, telefone ou Pix completo). Decidir e registrar o destino de `LinkDeAcessoInscricao` (fila ou síncrono), conforme §3.1. → commit `test(comunicacao): prove idempotency and privacy of emails`

6. **Fila, worker e fechamento.** Confirmar `ShouldQueue`, fila `emails`, `tries` e `backoff` em tudo; documentar o worker em `docs/ARCHITECTURE.md` com o comando pronto; `.env.example` atualizado. `pint`, `lint`, `vue-tsc`, Pest e Playwright completos. Atualizar `docs/PROGRESS.md` (Etapa 15, **Fase 7 concluída**, decisão DA-14 promovida, **D-12 encerrada** — os anúncios agora têm ouvinte) e `docs/IMPLEMENTATION_PLAN.md`. → commit `feat(comunicacao): queue emails and close phase 7`

## Done

Quem se inscreve recebe a cobrança por e-mail, é lembrado antes do prazo acabar, ganha comprovante quando o pagamento é reconhecido e é avisado se a inscrição expira ou é cancelada — tudo em fila, sem que um servidor de e-mail lento atrase uma única inscrição, e sem que a mesma mensagem chegue duas vezes, porque quem arbitra isso é a unicidade no banco. Nenhuma linha de regra de inscrição ou de pagamento foi tocada.

## Commit

```
feat(comunicacao): add sent-message registry
feat(comunicacao): add registration and payment emails
feat(comunicacao): add expiration and cancellation emails
feat(comunicacao): add payment deadline reminder
test(comunicacao): prove idempotency and privacy of emails
feat(comunicacao): queue emails and close phase 7
```
