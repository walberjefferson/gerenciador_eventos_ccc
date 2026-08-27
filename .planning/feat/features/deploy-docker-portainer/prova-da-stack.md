# Prova da stack — o que foi realmente verificado

> **Fase:** imagem Docker e stack de produção no Portainer (step 7 do plano)
> **Quando:** 27/08/2026
> **Onde:** máquina de desenvolvimento (macOS, Docker 29.4.0), com a stack subida
> localmente sob o nome de projeto `prova-eventos`, a partir do próprio
> `docker/compose.portainer.yaml`.

Este arquivo existe para que, daqui a seis meses, ninguém precise acreditar em
promessa. Cada afirmação abaixo veio de um comando que rodou de verdade, e o
comando está escrito junto para poder ser repetido.

---

## Como a prova foi montada

A stack de produção foi subida **na máquina de desenvolvimento**, ao lado do
ambiente de desenvolvimento que já estava rodando (Sail nas portas 8888, 55432,
6379 e 8025). Os dois não se atrapalham porque:

- a prova usa **nome de projeto próprio** (`-p prova-eventos`), portanto rede,
  volumes e containers são outros;
- a stack de produção **não publica nenhuma porta no host** (é o desenho dela),
  então não há como colidir com as portas do desenvolvimento.

Nenhum container de desenvolvimento foi parado ou removido em momento algum.

Os valores das variáveis vieram de um arquivo temporário fora do repositório
(`prova.env`, na pasta de rascunho da sessão), com senha de mentira e chave da
Resend de mentira. **Nada disso foi commitado.**

```bash
docker compose -p prova-eventos \
  --env-file <rascunho>/prova.env \
  -f docker/compose.portainer.yaml up -d
```

---

## A imagem

### Tamanho

```
gestao-eventos-ccc:local  956MB
```

O plano pedia para investigar qualquer coisa acima de ~600 MB antes de aceitar.
Investigado:

| Camada | Tamanho |
|---|---|
| Imagem base `dunglas/frankenphp:1-php8.4` | **825 MB** |
| `install-php-extensions` (pdo_pgsql, pgsql, redis, pcntl, opcache, intl, zip, bcmath) | 51,6 MB |
| `vendor` de produção | 46,2 MB |
| Código da aplicação | 4,63 MB |
| `public/build` (assets do Vite) | 0,94 MB |

Ou seja: **o que a nossa construção acrescenta são ~104 MB**. Os 825 MB restantes
são a imagem oficial do FrankenPHP sobre Debian, que é exatamente a mesma que o
`controle_tempo_ccc` usa em produção neste mesmo servidor. Não entrou nada
supérfluo. Encolher isso significaria trocar a base pela variante Alpine — o que
mudaria a libc debaixo de uma aplicação já provada, e não é decisão para se tomar
no fim de uma fase de empacotamento. Fica registrado como possível melhoria.

### O que não entrou

```bash
docker run --rm --entrypoint sh gestao-eventos-ccc:local -c '...'
```

```
ausente   node_modules
ausente   vendor/pestphp
ausente   vendor/laravel/pint
ausente   vendor/laravel/sail
ausente   vendor/laravel/pail
ausente   .env
ausente   .git
ausente   tests
ausente   .planning
```

E o `vendor` da imagem se declara como instalação sem desenvolvimento:

```
installed.json marcado como dev: false
pacotes instalados: 85
```

### Extensões PHP

```bash
docker run --rm --entrypoint php gestao-eventos-ccc:local -m
```

```
bcmath
curl
intl
openssl
pcntl
pdo_pgsql
pgsql
redis
Zend OPcache
zip
```

As cinco exigidas pelo plano (`pdo_pgsql`, `redis`, `opcache`, `intl`, `bcmath`)
estão lá, mais `pcntl` (para o `queue:work` parar limpo), `zip`, e `openssl`/
`curl`, de que o SDK da Efí depende para o mTLS com a instituição financeira.

### Assets do Vite

```
/app/public/build/assets
/app/public/build/manifest.json
--- total de arquivos: 50
```

---

## Os três processos

```bash
docker compose -p prova-eventos ps
```

```
app        Up (healthy)
pgsql      Up (healthy)
redis      Up (healthy)
scheduler  Up
worker     Up
```

### Migrations rodam só no papel `web`

Contagem da linha `[entrypoint][papel] aplicando migrations...` no log de cada
container, com os três subindo ao mesmo tempo:

```
app:        aplicando migrations = 1   sincronizando papeis = 1
worker:     aplicando migrations = 0   sincronizando papeis = 0
scheduler:  aplicando migrations = 0   sincronizando papeis = 0
```

E o banco confirma que cada migration foi aplicada uma vez só — todas no mesmo
lote, sem nenhum segundo lote de reaplicação:

```sql
select batch, count(*) from migrations group by batch;
-- 1 | 18
```

18 é exatamente o número de arquivos em `database/migrations/`.

### O `PapeisSeeder` é idempotente — provado em dois boots

**Boot 1** (volume vazio, banco criado do zero — o log diz
`Creating migration table`):

```
 papeis | permissoes | vinculos
--------+------------+----------
      2 |         10 |       16
```

Então a stack foi derrubada **sem `-v`** (os três volumes nomeados continuaram
existentes) e subida de novo.

**Boot 2:**

```
[entrypoint][web] aplicando migrations...
   INFO  Nothing to migrate.
[entrypoint][web] sincronizando papeis e permissoes...
   INFO  Seeding database.
```

```sql
select batch, count(*) from migrations group by batch;
-- 1 | 18          (nenhum lote novo)
```

```
 papeis | permissoes | vinculos
--------+------------+----------
      2 |         10 |       16
```

Números idênticos aos do boot 1. A busca por nome repetido em `roles` e
`permissions` (agrupando por `name, guard_name` e filtrando `count(*) > 1`) não
devolveu nenhuma linha. **Nada duplicou.**

Isso é o que autoriza o seeder a rodar a cada boot — que é o ponto todo: uma
permissão nova nasce no código e só existe de verdade depois de gravada no banco.
Sem este passo, uma tela nova simplesmente não aparece para ninguém, sem erro
nenhum no log. Já aconteceu em desenvolvimento na Fase 8b.

### O worker consome a fila `emails`, e só ela

Dois trabalhos foram enfileirados de dentro do container `app`: um na fila
`emails`, outro na fila `default`.

```
enfileirados -> emails=1 default=2
```

Segundos depois, o log do worker:

```
2026-08-27 12:27:28 Closure (prova-fila.php:5) ..... RUNNING
2026-08-27 12:27:28 Closure (prova-fila.php:5) ..... 5.09ms DONE
```

E o efeito colateral do trabalho apareceu no log da aplicação **dentro do
container do worker** (1 ocorrência de `PROVA-WORKER: trabalho da fila emails
executado`, 0 ocorrências da variante `default`).

Estado final das filas, lido de dentro da aplicação:

```
prefixo das chaves: gestao_de_eventos_database_
queues:emails  = 0
queues:default = 2
```

Duas conclusões: o worker **esvaziou** a fila `emails` e **não tocou** na
`default` — que é exatamente o que `queue:work redis --queue=emails` promete.

> Nota para quem for repetir isto: `redis-cli llen queues:emails` responde `0`
> mesmo com a fila cheia, porque o Laravel prefixa as chaves
> (`gestao_de_eventos_database_`). A contagem tem de ser lida de dentro da
> aplicação, ou com o prefixo escrito à mão.

### O scheduler roda as três rotinas

```bash
docker exec prova-eventos-scheduler-1 php artisan schedule:list
```

```
*    * * * *  php artisan inscricoes:expirar-vencidas   Next Due: em 50 segundos
*/5  * * * *  php artisan pagamentos:reconciliar        Next Due: em 2 minutos
*/15 * * * *  php artisan inscricoes:lembrar-prazo      Next Due: em 2 minutos
```

E não é lista teórica: o log do container mostra a rotina executando sozinha.

```
2026-08-27 12:26:00 Running ['artisan' inscricoes:expirar-vencidas] in background  1.71ms DONE
  ⇂ Expira inscricoes com prazo de pagamento vencido e devolve as vagas
```

### Healthchecks

```
app:        tem healthcheck: healthy
worker:     sem healthcheck (disable: true)
scheduler:  sem healthcheck (disable: true)
```

---

## Atrás do proxy

Requisição a `/up` de dentro do container, com os cabeçalhos que o Traefik
acrescenta:

```
status: HTTP/1.1 200 OK
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-...'; ...
Referrer-Policy: strict-origin-when-cross-origin
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
corpo contem "Application up": sim
```

O contraprova, na mesma requisição sem `X-Forwarded-Proto`:

```
HTTP/1.1 200 OK | HSTS presente: nao
```

Ou seja: o HSTS **só** aparece porque a aplicação acreditou no cabeçalho do
proxy. É a confirmação, em container de verdade, do que
`tests/Feature/Producao/AtrasDeProxyTest.php` prova em teste — inclusive o
critério mais importante da fase, o da URL assinada que valida atrás do proxy.

A CSP da Fase 9 continua íntegra, com o nonce por requisição.

---

## O webhook

```bash
docker exec prova-eventos-app-1 php artisan route:list --path=webhooks
```

```
POST  webhooks/pagamentos      webhooks.pagamentos     › Webhooks\PaymentWebhook…
POST  webhooks/pagamentos/pix  webhooks.pagamentos.pix › Webhooks\PaymentWebhook…
```

Os dois caminhos existem (a colisão C-6 da Fase 8a). No `compose.portainer.yaml`
o router do webhook cobre os dois de uma vez e tem prioridade sobre o router do
site:

```
routers.eventos-app.rule=Host(`${APP_DOMAIN}`)
routers.eventos-app.priority=1
routers.eventos-webhook.rule=Host(`${APP_DOMAIN}`) && PathPrefix(`/webhooks/pagamentos`)
routers.eventos-webhook.priority=100
routers.eventos-webhook.middlewares=eventos-efi-ips@docker
middlewares.eventos-efi-ips.ipallowlist.sourcerange=${EFI_IP_PERMITIDO:-34.193.116.226/32}
```

A lista de IP está **só** neste router. A inscrição pública continua aberta a
qualquer pessoa, de qualquer lugar.

> **Não verificado aqui:** o comportamento real do middleware `ipallowlist`
> exigiria um Traefik de pé com o certificado do domínio, o que não existe nesta
> máquina. O que se provou foram as labels e o desenho; o efeito prático é o
> mesmo arranjo que o `controle_tempo_ccc` já roda em produção. A conferência
> definitiva é o primeiro aviso da Efí chegar — e o roteiro em `docs/DEPLOY.md`
> diz como olhar o log para saber.

---

## Segurança

- **Nenhuma porta publicada no host.** `grep ports: docker/compose.portainer.yaml`
  não devolve nada, e o Docker confirma: os cinco containers mostram porta
  publicada `0` (só exposição interna).
- **`APP_DEBUG` é `"false"` fixo** e `APP_ENV` é `production` fixo, no bloco de
  ambiente compartilhado — não são variáveis do stack, ninguém liga depuração por
  engano de digitação.
- **`storage` gravável nos três papéis** — testado escrevendo e apagando um
  arquivo em `/app/storage/certificados` dentro de cada container:

  ```
  app:        grava OK | dono: www-data:www-data 700
  worker:     grava OK | dono: www-data:www-data 700
  scheduler:  grava OK | dono: www-data:www-data 700
  ```

  Sinceridade: o processo do FrankenPHP roda como `root` na imagem base, então a
  escrita funcionaria de qualquer jeito. A pasta é de `www-data` com modo `700`
  como camada a mais, não como a única. É o mesmo arranjo do projeto de
  referência em produção.

- `composer audit` → `No security vulnerability advisories found.`
- `npm audit` → `found 0 vulnerabilities`

---

## O que não pode ter mudado

```bash
git diff --stat ff12f7e..HEAD -- app/
# (vazio)

git diff --stat ff12f7e..HEAD -- tests/e2e/
# (vazio)
```

**Nenhum arquivo em `app/` foi tocado nesta fase**, e nenhum cenário do
Playwright foi editado. A única alteração em código de aplicação é
`bootstrap/app.php`.

---

## A suíte inteira

| Comando | Resultado |
|---|---|
| `php artisan test` | **533 passed (3681 assertions)** |
| `npm run test:e2e` | **36 passed (48,6s)** |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `npm run lint` | sem apontamento |
| `npx vue-tsc --noEmit` | saída vazia, código de saída `0` |
| `composer audit` | sem advisory |
| `npm audit` | 0 vulnerabilidades |

A base da fase anterior era **522 testes / 3661 asserções**. A fase acrescentou
**11 testes e 20 asserções** — `tests/Feature/Producao/AtrasDeProxyTest.php` (7) e
`tests/Feature/Comunicacao/TransporteResendTest.php` (4). As contas fecham nas
duas colunas: 522 + 11 = 533 e 3661 + 20 = 3681. **Nenhum teste antigo foi
alterado para caber.**
