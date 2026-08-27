# Action Plan — Imagem Docker e stack de produção no Portainer

> **Type:** feature
> **Created:** 2026-08-27
> **Status:** pending
> **Ordem:** depende da Fase 8 (8a e 8b) concluída. É o que falta para o sistema
> **existir fora da máquina de desenvolvimento** e para a Efí conseguir avisar sobre
> pagamento.

---

## 1. Persona & Scope

**Persona:** Senior DevOps/Backend Engineer **Laravel 12 + PHP 8.4 + Docker**, com prática
em imagem multi-estágio, FrankenPHP, Traefik e stack no Portainer. Sabe que aplicação em
produção não é a mesma coisa que aplicação rodando: sabe que sessão, fila, agendamento e
proxy reverso mudam o comportamento do framework, e confere cada um.

**Scope:** empacotar a aplicação numa imagem publicada no GHCR e entregar a stack que o
Portainer sobe, com os processos separados.

| Entrega | Nesta fase |
|---------|:----------:|
| `Dockerfile` multi-estágio (assets → vendor → runtime FrankenPHP) | ✅ |
| `.dockerignore` | ✅ |
| `docker/Caddyfile` e `docker/entrypoint.sh` com papéis | ✅ |
| **`TrustProxies`** — sem isso a área do participante quebra (§3.3) | ✅ |
| **Containers separados: `app`, `worker`, `scheduler`** | ✅ |
| Redis e PostgreSQL 18 no stack | ✅ |
| `docker/compose.portainer.yaml` com labels do Traefik | ✅ |
| Lista de IP da Efí na rota do webhook | ✅ |
| Workflow do GitHub publicando no GHCR | ✅ |
| E-mail real por **Resend** | ✅ |
| `env.docker.example` e `docs/DEPLOY.md` | ✅ |
| mTLS de verdade no webhook | ❌ **fora do escopo** (DA-28) |
| Backup automatizado, monitoramento, réplica do banco | ❌ fora do escopo |
| Alterar qualquer regra de domínio | ❌ **proibido** (§7) |

**Stack:** PHP 8.4 · Laravel 12 · FrankenPHP · PostgreSQL 18 · Redis · Traefik ·
Portainer · GitHub Actions · GHCR.

**Referência viva:** o projeto `../controle_tempo_ccc` já faz exatamente isto e está em
produção. **Leia os arquivos dele antes de escrever os nossos** (§3.5). O que muda aqui
está na §3.4 — e não é pouco.

---

## 2. Direct Objective

Um `git push` na `main` publica a imagem no GHCR; o Portainer sobe a stack a partir de
`docker/compose.portainer.yaml`; e `https://inscricoes.cccista.com.br` responde com a
aplicação inteira funcionando — inclusive **os e-mails saindo de verdade** (o worker que
nunca subiu desde a Fase 7) e **o webhook da Efí sendo recebido**.

---

## 3. Minimum Inputs

### 3.1 Decisões já tomadas — **NÃO reabrir**

| # | Tema | Decisão | Origem |
|---|------|---------|--------|
| **DA-28** | mTLS do webhook | **Não** será exigido no Traefik. A defesa é dupla: o **HMAC** que a aplicação já confere (Fase 8a) e a **lista de IP** da Efí (`34.193.116.226`) num middleware do Traefik, por label. É desvio do que a Efí recomenda, e vai documentado como tal | entrevista |
| **DA-29** | E-mail | **Resend**, com `resend/resend-laravel`. Dependência nova justificada: é o que faz os cinco e-mails do sistema chegarem a alguém | entrevista |
| **DA-30** | Banco e Redis | **Ambos no stack**, com volume nomeado, em rede interna não exposta | entrevista |
| **DA-31** | Imagem | **GHCR** (`ghcr.io/walberjefferson/gerenciador_eventos_ccc`), publicada por GitHub Actions a cada push na `main`. O repositório é `walberjefferson/gerenciador_eventos_ccc` | entrevista |
| **DA-32** | Processos | **`app`, `worker` e `scheduler` em containers separados**, todos da mesma imagem, distinguidos por `CONTAINER_ROLE` e pelo `command` | pedido explícito |
| **DA-33** | Domínio | `inscricoes.cccista.com.br`; Traefik na rede `proxy` com certresolver `le` (mesmos do `controle_tempo_ccc`, sobrescrevíveis por variável) | entrevista |

**Decisões anteriores que esta fase deve respeitar:**

| # | O que diz | Consequência aqui |
|---|---|---|
| **D-10** | Aplicação em `America/Sao_Paulo`, banco em UTC | `APP_TIMEZONE` tem de ir para o ambiente do container. Prazo de pagamento errado é vaga perdida |
| **D-19** | O PostgreSQL de desenvolvimento vive na porta 55432 | **Só vale para desenvolvimento.** Dentro do stack a porta é 5432 pela rede interna e **nada é publicado no host** |
| **§11.4 do ARCHITECTURE** | `APP_DEBUG=false` e `APP_ENV=production` obrigatórios | Com `true`, qualquer erro devolve pilha de chamadas, caminhos e variáveis de ambiente — inclusive as credenciais da Efí |
| **D-50** | O seeder de papéis é **idempotente** | Por isso ele **pode** rodar no boot (§3.6) |
| **D-51** | Conta administrativa só por comando | O primeiro administrador **não** é criado automaticamente: vira passo manual documentado |

### 3.2 O que já existe e não pode ser refeito

A aplicação está **pronta e provada**: 522 testes Pest (3661 asserções), 36 cenários
Playwright, integração com a Efí verificada contra homologação real. **Esta fase não toca
em código de domínio.** As únicas alterações em `app/` são as da §3.3.

### 3.3 `TrustProxies` — a correção que não é opcional

`bootstrap/app.php` **não configura proxies confiáveis**. Atrás do Traefik, o Laravel
recebe a requisição em HTTP na rede interna e passa a acreditar que o acesso é HTTP.

Três consequências, em ordem de gravidade:

1. **As URLs assinadas param de validar.** É assim que o participante acessa a inscrição,
   vê a linha do tempo e pede a segunda via do Pix (Fases 5a e 5b). O link é gerado com
   `https` (o e-mail sai do worker, que usa `APP_URL`) e conferido numa requisição que o
   framework julga `http` — **a assinatura não bate, e a pessoa recebe 403 no link que
   acabou de chegar por e-mail**.
2. **O HSTS não é emitido.** `CabecalhosDeSeguranca` só o manda em HTTPS (Fase 9), e o
   framework diria que não é.
3. Qualquer `url()` ou `route()` gerado numa requisição sai com esquema errado.

**O que fazer:** configurar `trustProxies` em `bootstrap/app.php`. Como o Traefik está na
mesma rede Docker e o IP dele não é fixo, confiar na faixa privada — ou em `'*'`, aceitável
porque **nada além do Traefik alcança o container**: a porta 80 não é publicada no host.
Os cabeçalhos a confiar incluem `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Port`,
`X-Forwarded-Proto`.

**Prova exigida:** um teste que simule requisição com `X-Forwarded-Proto: https` e verifique
que uma URL assinada gerada em CLI valida — e que sem a configuração ela falharia.

### 3.4 O que muda em relação ao `controle_tempo_ccc`

Copiar sem ler quebra. As diferenças reais:

| Tema | No `controle_tempo_ccc` | **Aqui** |
|---|---|---|
| Fila / cache | `database` | **Redis** (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`) → o stack ganha um serviço `redis` e a imagem precisa da **extensão `redis`** |
| Sessão | `database` | `database` também — não muda |
| Reverb / WebSocket | tem | **não existe** — remover os dois serviços e todas as variáveis `REVERB_*` |
| Worker | `queue:work` genérico | **`queue:work redis --queue=emails`** — a fila tem nome (Fase 7) |
| Agendamento | `schedule:work` | igual, mas aqui ele **importa de verdade**: `routes/console.php` tem tarefas a cada minuto e a cada cinco minutos (expiração de inscrição, lembrete de prazo, reconciliação de pagamento). Sem o scheduler, **vaga não volta para a fila e pagamento pago não é reconhecido** |
| E-mail | — | **Resend** (DA-29) |
| Certificado da Efí | — | materializado em `storage/certificados` a partir do banco (Fase 8b) → o `storage` precisa ser **gravável** em todos os containers |
| Webhook de terceiro | — | rota `/webhooks/pagamentos` **e** `/webhooks/pagamentos/pix` (colisão C-6 da Fase 8a) com lista de IP |
| Extensões PHP | pdo_pgsql, pgsql, pcntl, opcache, intl, zip, bcmath | as mesmas **mais `redis`**; conferir se o SDK da Efí exige `openssl`/`curl` (quase certo) |

### 3.5 Arquivos a ler antes de escrever

**Do projeto de referência** (`../controle_tempo_ccc`) — a estrutura vem daqui:

`Dockerfile` · `.dockerignore` · `docker/Caddyfile` · `docker/entrypoint.sh` ·
`docker/compose.portainer.yaml` · `env.docker.example` · `.github/workflows/docker-publish.yml` ·
`docs/DEPLOY.md`

**Deste projeto** — o que precisa caber lá dentro:

`bootstrap/app.php` (§3.3) · `routes/console.php` (o que o scheduler roda) ·
`config/payments.php` · `app/Services/Payments/Efi/ConfiguracaoEfi.php` ·
`app/Models/CredencialPagamento.php` (o `materializarCertificado()` e a pasta que ele usa) ·
`app/Http/Middleware/CabecalhosDeSeguranca.php` (HSTS e CSP atrás de proxy) ·
`docker-compose.yml` (o de desenvolvimento — **não** é o de produção) ·
`docs/ARCHITECTURE.md` §9.1 e §11 · `.env.example` · `composer.json`

### 3.6 O entrypoint — e uma lição aprendida hoje

Mesmo desenho do projeto de referência: espera o banco, roda otimizações idempotentes,
aplica migrations **só no papel `web`** (para não haver corrida entre três containers
subindo juntos) e então executa o comando do papel.

**Duas diferenças obrigatórias:**

1. **Esperar o Redis também**, não só o banco. Fila e cache dependem dele.
2. **Rodar `php artisan db:seed --class=PapeisSeeder --force` no papel `web`, depois das
   migrations.** Isso não é zelo: **aconteceu hoje**. A tela de credenciais da Fase 8b não
   apareceu no ambiente de desenvolvimento porque a permissão `pagamentos.credenciais`
   existia no código e não no banco. Em produção, o sintoma seria idêntico e muito pior de
   diagnosticar. O seeder é idempotente por desenho (D-50), então rodar a cada boot é
   seguro.

`php artisan optimize` é seguro aqui: **verifiquei que não há nenhuma chamada a `env()`
fora de `config/`**, que é o que costuma quebrar com `config:cache`.

### 3.7 Variáveis do stack

Todas em `env.docker.example`, para colar na aba do Portainer. Sem valor real commitado.

| Grupo | Variáveis |
|---|---|
| Imagem | `APP_IMAGE` |
| Aplicação | `APP_NAME`, `APP_KEY`, `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE` |
| Domínio | `APP_DOMAIN`, `TRAEFIK_NETWORK`, `TRAEFIK_CERTRESOLVER` |
| Banco | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| E-mail | `RESEND_API_KEY`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Efí | **nenhuma obrigatória** — a Fase 8b move a credencial para o banco, cadastrada pela tela. As `EFI_*` continuam existindo como reserva (DA-26) |

> `APP_DEBUG` e `APP_ENV` **não são variáveis**: vão fixos como `false` e `production` no
> compose. Ninguém pode ligar depuração em produção por engano de digitação.

---

## 4. Output Format

| File | Action | Description |
|------|--------|-------------|
| `Dockerfile` | create | três estágios: assets (node), vendor (composer, `--no-dev`), runtime (FrankenPHP 8.4 + extensões, **incluindo `redis`**) |
| `.dockerignore` | create | espelha o do projeto de referência, adaptado (aqui `tests/e2e` está dentro de `tests/`) |
| `docker/Caddyfile` | create | FrankenPHP na `:80`, TLS terminado no Traefik |
| `docker/entrypoint.sh` | create | §3.6 — papéis, espera de banco **e Redis**, migrations e seeder de papéis só no `web` |
| `docker/compose.portainer.yaml` | create | `app`, `worker`, `scheduler`, `pgsql`, `redis` + labels do Traefik + lista de IP no webhook |
| `bootstrap/app.php` | **modify** | `trustProxies` (§3.3) — **a única alteração em código desta fase** |
| `config/mail.php` · `config/services.php` | modify | transporte Resend |
| `composer.json` / `composer.lock` | modify | `resend/resend-laravel` (DA-29) |
| `.env.example` | modify | `RESEND_API_KEY` e `MAIL_MAILER=resend` comentados, sem valor |
| `env.docker.example` | create | §3.7 |
| `.github/workflows/docker-publish.yml` | create | build e push para o GHCR |
| `tests/Feature/Producao/AtrasDeProxyTest.php` | create | prova da §3.3: URL assinada valida com `X-Forwarded-Proto: https`; HSTS presente |
| `docs/DEPLOY.md` | create | o roteiro inteiro: publicar imagem, criar stack, variáveis, primeiro administrador, cadastrar credencial da Efí, registrar o webhook, e como conferir que os e-mails saíram |
| `docs/ARCHITECTURE.md` | modify | seção de implantação apontando para o `DEPLOY.md`; §9.1 deixa de dizer que o worker não roda |
| `docs/PROGRESS.md` | modify | Etapa 19; decisões DA-28 a DA-33; **a pendência do worker de fila é fechada** |
| `.planning/feat/features/deploy-docker-portainer/plan.done.md` | create | relatório |

---

## 5. Quality Criteria

### A imagem

- [ ] `docker build` conclui e a imagem final **não contém** `node_modules`, `vendor` de
      desenvolvimento, `.env`, `.git`, `tests/` nem `.planning/`
- [ ] `php -m` dentro da imagem lista `pdo_pgsql`, `redis`, `opcache`, `intl`, `bcmath`
- [ ] `composer install --no-dev` — **nenhum pacote de desenvolvimento** na imagem
- [ ] Os assets do Vite estão em `public/build` **dentro** da imagem
- [ ] `APP_DEBUG` não é `true` em nenhum lugar do compose

### Os três processos

- [ ] `app`, `worker` e `scheduler` sobem da **mesma imagem**, distinguidos por
      `CONTAINER_ROLE` e `command` (DA-32)
- [ ] O `worker` roda **`queue:work redis --queue=emails`** — a fila com nome da Fase 7
- [ ] O `scheduler` roda `schedule:work`, e `php artisan schedule:list` dentro do container
      mostra as tarefas de `routes/console.php`
- [ ] **Migrations rodam só no `web`** — provar subindo os três juntos e conferindo o log
- [ ] O `PapeisSeeder` roda no boot e é idempotente: subir duas vezes **não** duplica papel
      nem permissão (§3.6)
- [ ] `worker` e `scheduler` têm `healthcheck: disable: true` — não têm porta HTTP

### Atrás do proxy

- [ ] **Uma URL assinada gerada em CLI valida numa requisição com `X-Forwarded-Proto:
      https`** — teste automatizado, é o critério mais importante desta fase (§3.3)
- [ ] O HSTS aparece na resposta quando a requisição chega marcada como HTTPS
- [ ] A CSP da Fase 9 continua íntegra atrás do proxy
- [ ] `/up` responde e serve de healthcheck do container

### O webhook

- [ ] A rota do webhook responde **nos dois caminhos** — `/webhooks/pagamentos` e
      `/webhooks/pagamentos/pix` (colisão C-6 da Fase 8a)
- [ ] O middleware de lista de IP cobre **os dois** e deixa passar apenas `34.193.116.226`
- [ ] O router do webhook tem **prioridade maior** que o router principal do domínio, senão
      a regra genérica captura primeiro — conferir com `PathPrefix` e `priority` explícito
- [ ] O resto do site **não** exige nada disso: a inscrição pública continua aberta

### Segurança

- [ ] **Nenhum segredo commitado.** `env.docker.example` só tem nome de variável
- [ ] Banco e Redis **não publicam porta no host** — só a rede interna do stack
- [ ] O `storage` é gravável pelo usuário do container, senão o certificado da Efí não
      materializa e toda cobrança falha (§3.4)
- [ ] `composer audit` e `npm audit` limpos

### O que não pode ter mudado

- [ ] `git diff --stat` sobre `app/`, exceto `bootstrap/app.php`, é **vazio**
- [ ] **522 testes Pest e 36 cenários Playwright continuam verdes**, sem edição

---

## 6. Ambiguity Handling

**Assumptions made:**

- **`trustProxies` confia em `'*'`.** O container só é alcançável pelo Traefik, porque a
  porta não é publicada no host. Se o executor achar melhor restringir à faixa da rede
  Docker, pode — desde que prove com o teste da §3.3.
- **`APP_DEBUG` e `APP_ENV` são fixos no compose**, não variáveis. Deliberado.
- **O primeiro administrador não é criado automaticamente** (D-51): é passo manual no
  `DEPLOY.md`, com `php artisan usuario:criar-administrador` dentro do container.
- **As credenciais da Efí não vão para variável de ambiente.** A Fase 8b as move para o
  banco, cadastradas pela tela. O `DEPLOY.md` descreve esse passo na ordem certa: subir,
  criar administrador, cadastrar credencial, registrar webhook.
- **Uma réplica de cada processo.** Escalar o `app` exigiria pensar em sessão compartilhada
  (está em banco, então funcionaria), mas **o `scheduler` nunca pode ter duas réplicas** —
  duas rodadas do mesmo agendamento significam dois lembretes para a mesma pessoa.

**If unsure during execution:**

- **Se algo exigir mudar regra de domínio, PARE.** Esta fase empacota; não altera
  comportamento.
- **Se o build ficar acima de ~600 MB**, investigue o que entrou sem precisar antes de
  aceitar.
- **Se o `optimize` falhar no boot**, não desligue o cache: descubra o motivo. Já verifiquei
  que não há `env()` fora de `config/`.
- **Não invente label do Traefik.** O `controle_tempo_ccc` está em produção com labels que
  funcionam — siga a forma dele e mude só o que a §3.4 manda.
- **Commite ao fim de cada step.**

---

## 7. Prohibitions

- ❌ **NUNCA** commitar segredo, chave, `.env` real ou certificado
- ❌ **NUNCA** publicar porta de banco ou de Redis no host
- ❌ **NUNCA** deixar `APP_DEBUG=true` alcançável em produção
- ❌ **NUNCA** rodar migrations em mais de um papel de container
- ❌ **NUNCA** dar duas réplicas ao `scheduler`
- ❌ **NUNCA** alterar Action, Model, Enum ou regra de inscrição — só `bootstrap/app.php`
- ❌ **NUNCA** editar cenário Playwright existente
- ❌ **NUNCA** incluir `tests/`, `.planning/` ou `node_modules` na imagem
- ❌ **NUNCA** exigir mTLS ou lista de IP em rota que não seja a do webhook — a inscrição
  pública tem de continuar aberta a qualquer pessoa
- ❌ **NUNCA** adicionar dependência além do `resend/resend-laravel` aprovado

---

## Execution Steps

1. **`TrustProxies` e a prova.** `bootstrap/app.php` mais
   `tests/Feature/Producao/AtrasDeProxyTest.php`. **Primeiro**, porque é o único código de
   aplicação da fase e porque é o defeito que mais estragaria em produção.
   → commit `fix(producao): confiar no proxy reverso para gerar e validar urls`

2. **E-mail por Resend.** Pacote, `config/mail.php`, `config/services.php`, `.env.example`.
   Provar que o transporte é resolvido sem chave real na suíte.
   → commit `feat(comunicacao): add resend transport`

3. **Dockerfile e `.dockerignore`.** Três estágios, extensões (com `redis`), OPcache de
   produção. `docker build` tem de concluir.
   → commit `build(docker): add production image`

4. **Caddyfile e entrypoint.** Papéis, espera de banco **e Redis**, migrations e
   `PapeisSeeder` só no `web` (§3.6).
   → commit `build(docker): add caddy config and entrypoint`

5. **A stack do Portainer.** `docker/compose.portainer.yaml` com os cinco serviços, labels
   do Traefik, lista de IP no webhook com prioridade correta, volumes e redes.
   → commit `build(docker): add portainer stack`

6. **Publicação da imagem.** `.github/workflows/docker-publish.yml` para o GHCR.
   → commit `ci(docker): publish image to ghcr`

7. **A prova.** Subir a stack **localmente** com a imagem construída: os três processos de
   pé, migrations aplicadas uma vez só, seeder idempotente em dois boots, `/up`
   respondendo, `schedule:list` correto, worker consumindo a fila `emails`. Mais a suíte
   inteira, pint, lint, vue-tsc, `composer audit`.
   → commit `test(docker): prove the stack boots`

8. **Documentação e fechamento.** `docs/DEPLOY.md` (o roteiro completo, escrito para ser
   seguido por quem não acompanhou nada disto), `env.docker.example`,
   `docs/ARCHITECTURE.md`, `docs/PROGRESS.md` (Etapa 19, DA-28 a DA-33, **worker de fila
   deixa de ser pendência**) e o relatório.
   → commit `docs(producao): add deploy guide`

---

## Done

Um push na `main` publica a imagem no GHCR; a stack sobe no Portainer com `app`, `worker` e
`scheduler` separados; `https://inscricoes.cccista.com.br` serve a aplicação atrás do
Traefik com as URLs assinadas validando; os e-mails saem de verdade pela primeira vez desde
a Fase 7; a Efí consegue entregar o aviso de pagamento; e o `docs/DEPLOY.md` descreve o
caminho inteiro, do primeiro administrador ao registro do webhook.

## Commit

`build(docker): add production image and portainer stack`
