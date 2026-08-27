# Execution Report — Imagem Docker e stack de produção no Portainer

> **Plan:** deploy-docker-portainer
> **Executed:** 2026-08-27 (três rodadas; esta é a de fechamento)
> **Status:** ✅ COMPLETE

---

## Antes de tudo: como esta fase foi executada

A execução morreu **duas vezes** por limite de chamadas do agente e foi retomada
do zero de contexto nas duas. **Nada se perdeu**, e o motivo é o processo, não a
sorte: o plano mandava commitar ao fim de cada passo, então cada retomada
encontrou a árvore num estado provado e só precisou descobrir onde recomeçar.

- **Rodada 1** — steps 1 a 5 (`TrustProxies`, Resend, Dockerfile, entrypoint, stack).
- **Rodada 2** — steps 6 e 7 (workflow do GHCR e a prova da stack subida), mais o
  rascunho de `docs/DEPLOY.md` e `env.docker.example`, deixados **não commitados**.
- **Rodada 3** (esta) — **nenhuma linha de código**. Terminou o
  `docs/ARCHITECTURE.md`, escreveu o `docs/PROGRESS.md`, revisou o `DEPLOY.md` e
  escreveu este relatório.

Consequência que fica no histórico e não vale esconder: o `docs/DEPLOY.md` e o
`env.docker.example` foram **escritos na rodada 2 e commitados na rodada 3**, no
commit de fechamento — não no passo em que nasceram.

---

## What Was Done

### Steps 1 a 7 — já commitados (lidos de `git show --stat`)

| Commit | Step | O que entrou de verdade |
|---|---|---|
| `53016ab` | 1 | `bootstrap/app.php` (+28) e `tests/Feature/Producao/AtrasDeProxyTest.php` (+146). **A única alteração em código de aplicação da fase inteira** |
| `beb2639` | 2 | `resend/resend-laravel` no `composer.json`/`composer.lock`, transporte em `config/mail.php` (+5) e `config/services.php` (+19), `.env.example` (+9) e `tests/Feature/Comunicacao/TransporteResendTest.php` (+45) |
| `b8cbb06` | 3 | `Dockerfile` (+139, três estágios) e `.dockerignore` (+81) |
| `8b726e4` | 4 | `docker/Caddyfile` (+26) e `docker/entrypoint.sh` (+96) |
| `e435181` | 5 | `docker/compose.portainer.yaml` (+301) — cinco serviços, labels do Traefik, router do webhook com prioridade e lista de IP |
| `17e8ac0` | 6 | `.github/workflows/docker-publish.yml` (+69) |
| `4a40054` | 7 | `.planning/feat/features/deploy-docker-portainer/prova-da-stack.md` (+392) — a stack subida de verdade, com o registro comando a comando |

Detalhamento do que cada um faz:

- **Step 1 — `TrustProxies`.** `bootstrap/app.php` passou a configurar
  `trustProxies` com os quatro cabeçalhos `X-Forwarded-*`. Sem isso, atrás do
  Traefik o framework lê toda requisição como `http`, **as URLs assinadas param de
  validar** e a pessoa recebe 403 no link que acabou de chegar por e-mail. O teste
  gera uma URL assinada em linha de comando e a confere numa requisição com
  `X-Forwarded-Proto: https`, exigindo também o HSTS.
- **Step 2 — Resend.** Transporte de e-mail por API HTTPS (DA-29). O teste prova
  que o transporte é **resolvido sem chave real**, para a suíte não depender de
  segredo.
- **Step 3 — imagem.** Três estágios: assets do Vite → `vendor` com
  `--no-dev` → runtime FrankenPHP PHP 8.4 com as extensões, **incluindo `redis`**.
- **Step 4 — entrypoint.** Papéis (`web`/`worker`/`scheduler`), espera do banco
  **e do Redis**, `optimize`, e `migrate --force` + `PapeisSeeder` **só no `web`**.
- **Step 5 — stack.** `app`, `worker`, `scheduler`, `pgsql`, `redis`; volumes
  nomeados; **nenhuma porta publicada**; `APP_ENV`/`APP_DEBUG` fixos; router do
  webhook com `priority=100` e middleware `ipallowlist`.
- **Step 6 — GHCR.** Push na `main`, tags `vX.Y.Z` e disparo manual.
- **Step 7 — a prova.** Stack subida na máquina de desenvolvimento, com nome de
  projeto próprio, e cada critério conferido com comando.

### Step 8 — esta rodada

| File | Action | Description |
|---|---|---|
| `docs/DEPLOY.md` | create | Roteiro completo, 13 seções, na ordem real de execução: publicar a imagem → criar a stack → variáveis → primeiro administrador → credencial da Efí pela tela (com os **cinco escopos**) → registrar o webhook → conferir que os e-mails saíram. Mais lista de conferência, tabela de sintomas e "o que este roteiro não resolve" |
| `env.docker.example` | create | Todas as variáveis do stack, **sem nenhum valor real**, cada uma com o motivo de existir e o comando para gerar o valor |
| `docs/ARCHITECTURE.md` | modify | Seção **13 nova** (13.1 a 13.5: uma imagem/três processos, o que muda atrás do proxy, o que o container faz sozinho, o webhook atrás do Traefik, e-mail em produção); **§9.1 deixou de dizer que o trabalhador não roda**; §11.4 atualizada; §8.3 ganhou ponteiro para o `DEPLOY.md` e para os escopos da Efí; cabeçalho para **versão 1.4** |
| `docs/PROGRESS.md` | modify | Etapa 19; decisões **DA-28 a DA-33**; seção "Implantação ✅ concluída"; **a pendência do trabalhador de fila fechada**; "Em andamento" reescrito com as cinco ações humanas; `resend/resend-laravel` na tabela de dependências |
| `.planning/.../plan.done.md` | create | Este relatório |

**Correções feitas na revisão desta rodada** (o rascunho da rodada 2 tinha dois
erros): `env.docker.example` apontava para a "seção 6 de `docs/DEPLOY.md`" onde a
credencial da Efí é a **seção 7**; e o `DEPLOY.md` dizia "por volta de 950 MB… cerca
de 100 MB acrescentados", número que não fechava com a conta real (956 − 825 =
**131 MB**). Os dois foram corrigidos.

**O `docs/DEPLOY.md` foi conferido item a item contra o que o step 8 exige:**

| Exigido | Onde está |
|---|---|
| Publicar a imagem | §3, com as duas armadilhas do GHCR (permissão de escrita do workflow, pacote privado) |
| Criar a stack e preencher variáveis | §4, com a tabela variável-a-variável e o aviso sobre `APP_KEY` e `DOCUMENTO_HASH_PEPPER` |
| **Primeiro administrador (D-51)** | §6 — `php artisan usuario:criar-administrador` dentro do container, com a nota de que a senha não vai por argumento. Assinatura conferida contra `app/Console/Commands/CriarAdministrador.php` |
| **Credencial da Efí pela tela (Fase 8b)** | §7.2, com as três coisas que costumam causar estranheza (nada volta para a tela, salvar descarta o token, produção exige a palavra escrita) |
| **Os cinco escopos da Efí** | §7.1 — `cob.write`, `cob.read`, `pix.read`, `webhook.write`, `webhook.read`, com o aviso em destaque sobre `cob.write` e o sintoma exato ("testar conexão passa, a cobrança falha"); repetido na tabela de sintomas da §12 e no ponteiro da §8.3 do `ARCHITECTURE.md` |
| **Registrar o webhook** | §8, com o endereço copiado da tela, o porquê do `?hmac=` e do `&ignorar=`, e os dois caminhos aceitos |
| **Conferir que os e-mails saíram** | §9, com os cinco comandos (log do worker, `queue:monitor`, `queue:failed`, `schedule:list`, painel da Resend) |

---

## Quality Criteria

### A imagem

| Critério | Status | Evidência |
|---|---|---|
| `docker build` conclui; imagem sem `node_modules`, `vendor` de dev, `.env`, `.git`, `tests/`, `.planning/` | ✅ | `prova-da-stack.md` → conferência de dentro da imagem: `ausente` para os nove itens |
| `php -m` lista `pdo_pgsql`, `redis`, `opcache`, `intl`, `bcmath` | ✅ | `docker run --entrypoint php … -m` → os cinco presentes, mais `pcntl`, `zip`, `openssl`, `curl` |
| `composer install --no-dev` — nenhum pacote de dev | ✅ | `installed.json marcado como dev: false`, 85 pacotes |
| Assets do Vite em `public/build` dentro da imagem | ✅ | `/app/public/build/manifest.json` + 50 arquivos |
| `APP_DEBUG` não é `true` em nenhum lugar do compose | ✅ | `grep -n "APP_DEBUG" docker/compose.portainer.yaml` → `APP_DEBUG: "false"` (linha 35), fixo |
| **§6: build acima de ~600 MB → investigar** | ✅ | **956 MB, investigado camada a camada.** `dunglas/frankenphp:1-php8.4` = **825 MB**; os **131 MB** restantes são deste projeto: extensões PHP **51,6 MB**, `vendor` **46,2 MB**, código **4,63 MB**, assets do Vite **938 kB**. **Nada indevido entrou.** É a mesma base que o `controle_tempo_ccc` roda em produção. Encolher exigiria trocar para Alpine, mudando a libc debaixo de uma aplicação já provada — registrado como melhoria futura, não feito |

### Os três processos

| Critério | Status | Evidência |
|---|---|---|
| `app`, `worker`, `scheduler` da mesma imagem, por `CONTAINER_ROLE` + `command` | ✅ | `compose.portainer.yaml` linhas 118/197/228 (`CONTAINER_ROLE`) e 186/225 (`command`) |
| Worker roda `queue:work redis --queue=emails` | ✅ | `compose.portainer.yaml` L186; e a prova de comportamento: fila `emails` esvaziada, `default` intocada (`queues:emails = 0`, `queues:default = 2`) |
| `schedule:list` mostra as tarefas de `routes/console.php` | ✅ | `docker exec … schedule:list` → `inscricoes:expirar-vencidas` (1 min), `pagamentos:reconciliar` (5 min), `inscricoes:lembrar-prazo` (15 min). E o log mostra uma **executando sozinha** |
| Migrations só no `web` — provado com os três juntos | ✅ | `aplicando migrations = 1` no `app`, `0` no `worker`, `0` no `scheduler`; `select batch, count(*) from migrations` → `1 \| 18`, um lote só |
| `PapeisSeeder` idempotente em dois boots | ✅ | Boot 1 e boot 2: `2 papéis, 10 permissões, 16 vínculos` idênticos; busca por nome repetido em `roles`/`permissions` não devolveu linha |
| `worker` e `scheduler` com `healthcheck: disable: true` | ✅ | `compose.portainer.yaml` L208-209 e L238-239 |
| `scheduler` com uma réplica | ✅ | `replicas: 1` (L237) |

### Atrás do proxy

| Critério | Status | Evidência |
|---|---|---|
| **URL assinada gerada em CLI valida com `X-Forwarded-Proto: https`** (o mais importante da fase) | ✅ | `tests/Feature/Producao/AtrasDeProxyTest.php`, dentro dos **533 testes verdes** |
| HSTS aparece quando a requisição chega marcada como HTTPS | ✅ | No container: `Strict-Transport-Security: max-age=31536000; includeSubDomains`. **Com contraprova**: sem o cabeçalho do proxy, `HSTS presente: nao` |
| CSP da Fase 9 íntegra atrás do proxy | ✅ | Resposta do container traz `Content-Security-Policy` com `nonce-…`, sem `unsafe-inline` em `script-src` |
| `/up` responde e serve de healthcheck | ✅ | `HTTP/1.1 200 OK`, corpo com "Application up"; `app` marcado `Up (healthy)` |

### O webhook

| Critério | Status | Evidência |
|---|---|---|
| Responde nos dois caminhos | ✅ | `route:list --path=webhooks` → `POST webhooks/pagamentos` e `POST webhooks/pagamentos/pix` |
| Router do webhook com prioridade maior | ✅ | `eventos-webhook.priority=100` contra `eventos-app.priority=1`, com `PathPrefix(/webhooks/pagamentos)` |
| Lista de IP cobre os dois e só deixa passar `34.193.116.226` | ⚠️ **provado como configuração, não como comportamento** | As labels existem e cobrem os dois caminhos pelo `PathPrefix` (`middlewares.eventos-efi-ips.ipallowlist.sourcerange=${EFI_IP_PERMITIDO:-34.193.116.226/32}`). **O middleware não foi exercitado**: exigiria um Traefik de pé com o certificado do domínio, que não existe na máquina de desenvolvimento. Está escrito assim em `prova-da-stack.md`. A conferência definitiva é o primeiro aviso da Efí chegar |
| O resto do site não exige nada disso | ✅ | O middleware está **só** no router `eventos-webhook`; o router do site não o referencia |

### Segurança

| Critério | Status | Evidência |
|---|---|---|
| Nenhum segredo commitado | ✅ | `env.docker.example` só tem nome de variável, com os campos sigilosos vazios. Varredura por `re_…`, `base64:…` e blocos de chave privada nos quatro documentos: nada |
| Banco e Redis sem porta no host | ✅ | `grep "ports:" docker/compose.portainer.yaml` → **nada**; os cinco containers com porta publicada `0` |
| `storage` gravável nos três papéis | ✅ | Escrita e remoção testadas em `/app/storage/certificados` nos três containers: `grava OK`, dono `www-data:www-data 700`. **Sinceridade registrada na prova**: o processo roda como `root` na imagem base, então a escrita funcionaria de qualquer jeito — a permissão é camada a mais, não a única |
| `composer audit` e `npm audit` limpos | ✅ | `composer audit` → `No security vulnerability advisories found.` (rodado nesta rodada); `npm audit` → `found 0 vulnerabilities` (rodado no step 7) |

### O que não pode ter mudado

| Critério | Status | Evidência |
|---|---|---|
| `git diff --stat` sobre `app/` vazio | ✅ | `git diff --stat ff12f7e..HEAD -- app/` → **saída vazia** (rodado nesta rodada) |
| Testes Pest e cenários Playwright verdes, sem edição | ✅ | `php artisan test` → **533 passed (3681 assertions)**. Base da fase: 522/3661 → **+11 testes, +20 asserções**, todos novos (7 do proxy, 4 do Resend). `git diff --stat ff12f7e..HEAD -- tests/e2e/` → **vazio**; `npm run test:e2e` → 36 passed (step 7) |

---

## Verification

| Command | Result |
|---|---|
| `php artisan test` | **533 passed (3681 assertions)** — 56,16s (rodado nesta rodada) |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` (rodado nesta rodada) |
| `composer audit` | `No security vulnerability advisories found.` (rodado nesta rodada) |
| `git diff --stat ff12f7e..HEAD -- app/` | vazio (rodado nesta rodada) |
| `docker images gestao-eventos-ccc:local` | `956MB` (rodado nesta rodada) |
| `npm run lint` | sem apontamento (step 7) |
| `npx vue-tsc --noEmit` | saída vazia, código `0` (step 7) |
| `npm run test:e2e` | 36 passed (48,6s) (step 7) |
| `npm audit` | 0 vulnerabilidades (step 7) |
| `docker compose -p prova-eventos … up -d` | cinco containers `Up`, `app` `healthy` (step 7) |

**Nesta rodada não foram reexecutados** `npm run lint`, `vue-tsc`, `npm audit` e a
suíte Playwright: a rodada não tocou em código nem em arquivo do navegador, só em
`.md`. Os números acima vêm do step 7 e estão registrados em `prova-da-stack.md`.

### O que **não** foi provado com comando — dito sem rodeio

1. **O middleware `ipallowlist` do Traefik nunca foi exercitado.** Provou-se a
   label e o desenho, não o efeito. Falta um Traefik com certificado do domínio.
2. **A imagem nunca foi publicada no GHCR.** O workflow existe e está commitado,
   mas ele só dispara em push na `main`, e a fase inteira vive no ramo
   `feat/deploy-docker`. **Nenhuma execução do GitHub Actions foi observada.**
3. **Nenhum e-mail real saiu pela Resend.** O que se provou foi o transporte sendo
   resolvido (teste) e o worker consumindo a fila `emails` (container). A entrega
   de verdade depende de chave da Resend e domínio verificado, que não existem.
4. **Nada foi ligado contra dinheiro de verdade.** Continua igual desde a Fase 8a.
5. **`https://inscricoes.cccista.com.br` nunca respondeu.** Não há servidor.

### A stack completa chegou a subir localmente? **Sim.**

E vale registrar como, porque o ambiente de desenvolvimento estava rodando ao
mesmo tempo (Sail nas portas 8888, 55432, 6379 e 8025). Não houve colisão por
dois motivos: a prova usou **nome de projeto próprio** (`-p prova-eventos`), então
rede, volumes e containers são outros; e a stack de produção **não publica porta
nenhuma no host**, que é o desenho dela. **Nenhum container de desenvolvimento
foi parado ou removido.** As variáveis vieram de um arquivo temporário fora do
repositório, com senha e chave de mentira, que **não foi commitado**.

---

## Deviations from Plan

1. **O `docs/DEPLOY.md` e o `env.docker.example` foram escritos na rodada 2 e
   commitados na rodada 3**, junto com o resto da documentação, e não no passo em
   que nasceram. Consequência da retomada, não escolha.
2. **O plano previa `docker/compose.portainer.yaml` com "os cinco serviços" e
   nada mais**; a execução acrescentou também `deploy.replicas: 1` explícito no
   `scheduler`, para tornar a proibição do plano (§7) visível no arquivo em vez de
   depender de alguém lembrar dela.
3. **Dois erros do rascunho da rodada 2 foram corrigidos nesta rodada** — o
   ponteiro de seção no `env.docker.example` e o número do tamanho da imagem no
   `DEPLOY.md` (ver "What Was Done").
4. **Nada mais.** Nenhuma regra de domínio foi alterada, nenhuma dependência além
   do `resend/resend-laravel` aprovado entrou, nenhum cenário Playwright foi
   editado, nenhuma porta de banco ou Redis foi publicada.

---

## O que continua aberto depois desta fase

Registrado aqui e em `docs/PROGRESS.md`, com a mesma honestidade das etapas
anteriores:

- **P-02** (política de reembolso) e **P-06** (taxa efetiva da Efí) — as duas com
  o **dono do produto**. Sem a P-02 não existe estorno.
- **A revisão de LGPD nunca foi feita**: sem política de retenção, sem prazo de
  descarte, sem anonimização. É o maior buraco conhecido do projeto, depende da
  **P-04** e da **P-03**, e vira plano próprio no dia em que forem respondidas.
- **O mTLS de verdade no webhook ficou fora** (**DA-28**). A defesa hoje é o
  **HMAC** que a aplicação confere mais a **lista de IP** no Traefik. É desvio
  consciente do que a Efí recomenda, escrito como tal em três lugares.
- **Backup, monitoramento e réplica do banco não existem**, e importam em dobro
  porque desde a Fase 8b é o backup do banco que carrega a credencial da Efí.
- **Cinco ações humanas para o sistema entrar no ar**, nesta ordem: mesclar
  `feat/deploy-docker` em `main` para o workflow publicar a imagem → criar a stack
  no Portainer → criar o primeiro administrador por comando → cadastrar a
  credencial da Efí pela tela → registrar o webhook na Efí. Todas em
  `docs/DEPLOY.md`.

---

## Commit

- **Mensagem:** `docs(producao): add deploy guide`
- **Arquivos:** `docs/DEPLOY.md` (novo), `env.docker.example` (novo),
  `docs/ARCHITECTURE.md`, `docs/PROGRESS.md`,
  `.planning/feat/features/deploy-docker-portainer/plan.done.md`
- **Não commitado, de propósito:** o arquivo solto na raiz
  `Prompt para Claude Code — Plataforma de Inscrições e Gestão de Eventos.md`,
  que não pertence a esta fase.
