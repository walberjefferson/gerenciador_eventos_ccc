# Implantação — Docker, Portainer e Traefik

> Escrito para ser seguido por quem **não** acompanhou a construção do sistema.
> Cada passo diz o que fazer, por que aquilo existe e como saber que deu certo.
> Siga na ordem: os passos 5, 6 e 7 dependem do sistema já estar de pé, e o
> passo 7 depende do 6.
>
> Se você só quer conferir se está tudo funcionando, pule para a seção **9. A
> lista de conferência**.

---

## 1. O que sobe, e por quê são cinco containers

A mesma imagem roda **três processos diferentes**. Não é exagero de arquitetura:
cada um resolve um problema que os outros não resolvem, e o sistema fica quieto
quando um deles falta — que é o pior tipo de falha.

| Serviço | O que é | Alcançável de fora? | Se faltar |
|---|---|:---:|---|
| `app` | O site (FrankenPHP na porta 80) | ✅ pelo Traefik | Ninguém acessa nada |
| `worker` | O trabalhador da fila (`queue:work redis --queue=emails`) | ❌ | **Nenhum e-mail chega a ninguém.** A fila cresce em silêncio, sem erro na tela |
| `scheduler` | O agendador (`schedule:work`) | ❌ | **Vaga vencida não volta para a fila** e pagamento pago não é reconhecido quando o aviso da Efí se perde |
| `pgsql` | PostgreSQL 18 | ❌ | Nada funciona |
| `redis` | Redis (fila e cache) | ❌ | O site sobe e quebra na primeira inscrição |

Os três primeiros usam **exatamente a mesma imagem**. O que muda entre eles é a
variável `CONTAINER_ROLE` e o comando — quem lê isso é `docker/entrypoint.sh`.

**Nenhum dos quatro internos publica porta no host.** O único caminho de fora
para dentro passa pelo Traefik, e só chega até o `app`.

**O `scheduler` nunca pode ter duas réplicas.** Duas rodadas do mesmo agendamento
significam dois lembretes de prazo para a mesma pessoa.

Diagrama mental, se ajudar:

```
   internet ──► Traefik (TLS) ──► app ──┐
                                        ├──► pgsql
                        worker ─────────┤
                     scheduler ─────────┴──► redis
```

---

## 2. Antes de começar

Três coisas precisam existir **antes**, e nenhuma delas é criada por este
roteiro:

1. **Um Traefik rodando no servidor**, com um resolvedor de certificado
   (Let's Encrypt) configurado. Descubra o nome da rede dele e do resolvedor:

   ```bash
   docker network ls                       # a rede costuma se chamar "proxy"
   docker inspect $(docker ps -qf name=traefik) | grep -i certificatesresolvers
   ```

2. **O DNS do domínio apontando para o servidor do Traefik.** Confira antes de
   subir o stack: certificado do Let's Encrypt só nasce se o domínio já resolver.

   ```bash
   dig +short inscricoes.cccista.com.br
   ```

3. **Portainer com acesso a esse Docker.** É por ele que o stack é criado e
   atualizado.

Tenha à mão, também, uma conta na **Resend** com o domínio do remetente
verificado (passo 4) e o acesso ao **painel da Efí** (passos 7 e 8).

---

## 3. Publicar a imagem no GHCR

O workflow `.github/workflows/docker-publish.yml` constrói a imagem e a publica
em `ghcr.io/walberjefferson/gerenciador_eventos_ccc`.

**Quando ele roda:**

- a cada **push na `main`** → reescreve a tag `:latest`, que é a que o stack puxa;
- a cada **tag `vX.Y.Z`** → publica tags de versão, para conseguir voltar atrás;
- **manualmente**, em *Actions → Publicar imagem Docker (GHCR) → Run workflow* —
  é assim que se constrói a imagem de um ramo antes do merge.

### 3.1 Nenhum segredo precisa ser cadastrado no GitHub

Isso costuma causar dúvida, então vai escrito: **o build não recebe segredo
nenhum.** Domínio, senha do banco, chave da Resend e credencial da Efí entram só
em tempo de execução, pelo Portainer. O `GITHUB_TOKEN` que o workflow usa para
publicar é criado pelo próprio GitHub a cada execução e some depois.

O que **precisa** estar certo é uma permissão, conferida uma vez:

> **Settings → Actions → General → Workflow permissions** → *Read and write
> permissions* marcado.

Se a organização bloquear o `GITHUB_TOKEN` de escrever pacotes, a saída é criar
um *Personal access token* clássico com escopo `write:packages`, guardá-lo como
segredo do repositório e trocar a senha do passo de login no workflow.

### 3.2 Deixar o pacote acessível ao Portainer

O pacote nasce **privado** no primeiro build. Escolha uma das duas:

- **Público** (mais simples): GitHub → seu perfil → *Packages* →
  `gerenciador_eventos_ccc` → *Package settings* → *Change visibility* →
  **Public**. O Portainer puxa sem login.
- **Privado**: crie um PAT com escopo `read:packages` e cadastre um registry no
  Portainer (*Registries → Add registry → Custom*), com URL `ghcr.io`, seu
  usuário do GitHub e o PAT como senha.

### 3.3 Construir na sua máquina, para testar

Não é obrigatório, mas serve para conferir a imagem antes de publicar:

```bash
docker build -t gestao-eventos-ccc:local .
docker run --rm --entrypoint php gestao-eventos-ccc:local -m | grep -E 'pdo_pgsql|redis|intl|bcmath'
```

A imagem final tem **956 MB**, dos quais **825 MB são a imagem oficial do
FrankenPHP** — a mesma base que o `controle_tempo_ccc` já roda em produção neste
servidor. O que este projeto acrescenta são os **131 MB** restantes: extensões do
PHP (51,6 MB), `vendor` de produção (46,2 MB), o código (4,6 MB) e os assets do
Vite (0,9 MB). Não há nada supérfluo dentro dela; a conferência camada a camada
está em `.planning/feat/features/deploy-docker-portainer/prova-da-stack.md`.

---

## 4. Criar o stack no Portainer

1. **Stacks → Add stack**, nome `gestao-eventos`.
2. Método **Web editor**: cole o conteúdo de `docker/compose.portainer.yaml`.
   (O compose não usa `env_file` nem contexto de build, então funciona inteiro
   no editor. A opção *Repository* também serve.)
3. Em **Environment variables**, preencha o que está em `env.docker.example`:

   | Variável | Exemplo | Como obter |
   |---|---|---|
   | `APP_IMAGE` | `ghcr.io/walberjefferson/gerenciador_eventos_ccc:latest` | a tag publicada no passo 3 |
   | `APP_NAME` | `Gestão de Eventos` | você escolhe |
   | `APP_KEY` | `base64:...` | `php artisan key:generate --show` ou `echo "base64:$(openssl rand -base64 32)"` |
   | `APP_DOMAIN` | `inscricoes.cccista.com.br` | o domínio já apontado no DNS |
   | `APP_TIMEZONE` | `America/Sao_Paulo` | não mude |
   | `TRAEFIK_NETWORK` | `proxy` | a rede do Traefik (passo 2) |
   | `TRAEFIK_CERTRESOLVER` | `le` | o resolvedor do Traefik (passo 2) |
   | `EFI_IP_PERMITIDO` | `34.193.116.226/32` | o IP de onde a Efí notifica |
   | `DB_DATABASE` | `gestao_eventos` | você escolhe |
   | `DB_USERNAME` | `eventos` | você escolhe |
   | `DB_PASSWORD` | senha forte | `openssl rand -hex 24` |
   | `RESEND_API_KEY` | `re_...` | https://resend.com/api-keys |
   | `MAIL_FROM_ADDRESS` | `eventos@cccista.com.br` | **domínio verificado na Resend** |
   | `MAIL_FROM_NAME` | `Inscrições CCC` | você escolhe |
   | `DOCUMENTO_HASH_PEPPER` | valor aleatório | `openssl rand -hex 32` |
   | `PAYMENT_GATEWAY` | `efi` | `fake` só enquanto ninguém for cobrar de verdade |

   Para gerar os três segredos de uma vez:

   ```bash
   echo "APP_KEY=base64:$(openssl rand -base64 32)"
   echo "DB_PASSWORD=$(openssl rand -hex 24)"
   echo "DOCUMENTO_HASH_PEPPER=$(openssl rand -hex 32)"
   ```

4. **Deploy the stack.**

### ⚠️ Duas variáveis que não se trocam depois

Guarde as duas no gerenciador de senhas antes de continuar:

- **`APP_KEY`** — é ela que cifra o CPF de cada inscrição e a credencial da Efí
  no banco. Trocar depois do sistema em uso torna esse conteúdo **ilegível para
  sempre**. Não há comando de recuperação.
- **`DOCUMENTO_HASH_PEPPER`** — é o tempero da impressão digital do CPF, que é
  como o sistema detecta inscrição duplicada. Trocar faz o sistema **deixar de
  reconhecer** as inscrições já gravadas: a mesma pessoa passaria a conseguir se
  inscrever duas vezes.

### O que você **não** vai encontrar nessa lista

`APP_ENV` e `APP_DEBUG` **não são variáveis do stack**: estão fixos no compose
como `production` e `false`. Isso é deliberado. Com `APP_DEBUG=true`, qualquer
erro devolveria ao visitante a pilha de chamadas, os caminhos do servidor e as
variáveis de ambiente — inclusive a chave da Resend e a credencial da Efí. Não
pode depender de alguém digitar certo num formulário.

E as **credenciais da Efí** também não estão lá, de propósito: desde a fase 8b
elas são cadastradas pela tela do painel (passo 7).

---

## 5. O primeiro boot — o que acontece sozinho

Quando o stack sobe, o `entrypoint` do container `app` faz, nesta ordem:

1. espera o **PostgreSQL** aceitar conexão;
2. espera o **Redis** responder;
3. roda `storage:link`, `package:discover` e `php artisan optimize`;
4. aplica **`migrate --force`**;
5. roda **`db:seed --class=PapeisSeeder --force`**.

Os passos 4 e 5 acontecem **só no `app`**. O `worker` e o `scheduler` sobem em
paralelo e não tocam no banco — três containers migrando o mesmo banco ao mesmo
tempo seria corrida garantida.

O passo 5 roda **a cada boot**, e isso é intencional: o seeder é idempotente e é
ele que grava no banco as permissões novas que nasceram no código. Sem ele, uma
tela nova simplesmente não aparece para ninguém — o item some do menu e o acesso
direto responde 403, **sem nenhum erro no log**. Já aconteceu em desenvolvimento.

### Como saber que deu certo

No Portainer, os cinco containers em *running*, com o `app` marcado *healthy*.
Pela linha de comando:

```bash
docker ps --filter name=gestao-eventos
docker logs <container do app> | head -40      # deve terminar em "iniciando: frankenphp ..."
curl -I https://inscricoes.cccista.com.br/up   # 200, com Strict-Transport-Security na resposta
```

O `/up` é o endereço de saúde da aplicação, e é o mesmo que o healthcheck do
container usa.

---

## 6. Criar o primeiro administrador — **não é automático**

O cadastro público do lado administrativo **não existe** (decisão D-51):
ninguém vira usuário de dentro sozinho. A primeira conta nasce por comando, feita
por quem tem acesso ao servidor. **Este passo não acontece sozinho e o sistema
não avisa que faltou** — você simplesmente não consegue entrar em lugar nenhum.

No Portainer, abra o **Console** do container `app` (*Containers → gestao-eventos_app → Console*,
comando `/bin/sh`), ou use o terminal do servidor:

```bash
docker exec -it <container do app> php artisan usuario:criar-administrador fulano@cccista.com.br --nome="Fulano de Tal"
```

A senha é pedida de forma escondida, digitada duas vezes. Ela **não** vai por
argumento de propósito: senha em argumento fica gravada no histórico do terminal.

Depois disso, entre em `https://inscricoes.cccista.com.br/login` com esse e-mail.

> Para criar quem apenas organiza o evento (sem acesso a dinheiro nem a
> credenciais), use `--papel=organizador`.

---

## 7. Cadastrar a credencial da Efí — pela tela, não por variável

Desde a fase 8b, a credencial da instituição financeira **mora cifrada no banco**
e é cadastrada pelo painel. Duas razões: trocar uma chave deixa de exigir alguém
com acesso ao servidor, e a configuração passa a viajar no **backup do banco** —
arquivo de ambiente não viaja, e um container recriado levaria tudo junto.

### 7.1 Na Efí: criar a aplicação e **marcar os escopos**

No painel da Efí (*API → Minhas Aplicações*), crie uma aplicação e **marque os
cinco escopos abaixo**, nos dois ambientes (homologação e produção):

| Escopo | Para quê |
|---|---|
| `cob.write` | **Criar a cobrança.** Sem ele nenhuma inscrição gera Pix |
| `cob.read` | Consultar a cobrança — é o que a reconciliação usa |
| `pix.read` | Ler o Pix recebido, para confirmar o pagamento |
| `webhook.write` | Registrar o endereço do aviso (passo 8) |
| `webhook.read` | Conferir qual endereço está registrado |

> **Leia esta linha duas vezes: a falta de `cob.write` já custou uma sessão
> inteira de diagnóstico neste projeto.** O sintoma não diz o que é: a
> autenticação funciona, o token é emitido, o "Testar conexão" passa — e a
> emissão da cobrança falha com um erro genérico de autorização. Vai acontecer de
> novo na conta de produção se ninguém conferir a lista. **Confira agora, antes
> de continuar.**

Baixe também o **certificado** da aplicação (arquivo `.p12` ou `.pem`), um por
ambiente. Ele é a segunda metade da identificação: a Efí não aceita só usuário e
senha, as duas pontas se identificam por certificado.

### 7.2 No painel do sistema: cadastrar

Entre como administrador e vá em **Credenciais de pagamento**, no menu lateral
(`/admin/pagamentos/credenciais`). A tela é exclusiva de quem administra; quem
apenas organiza recebe 403 e não vê o item no menu.

**Comece por homologação.** Preencha:

- identificação do cliente (*Client ID*) e chave secreta (*Client Secret*);
- a **chave Pix** que vai receber;
- o **segredo do aviso** (HMAC) — um valor que você inventa, e que vai junto na
  URL do passo 8. Sem ele, todo aviso é recusado;
- o **arquivo do certificado** daquele ambiente.

Salve e clique em **Testar conexão**. O teste percorre os mesmos passos do
`php artisan efi:diagnostico`: abre o certificado, confere se não venceu e pede
um token à Efí, dizendo qual dos três falhou.

**Três coisas que valem saber antes de estranhar:**

- **Nenhum valor salvo volta para a tela**, nem mascarado. A tela só informa que
  "existe um valor guardado". Por isso **campo em branco mantém o que está lá** e
  nunca apaga.
- **Salvar descarta o token guardado** dos dois ambientes. Sem isso, o sistema
  continuaria falando com a Efí usando a credencial antiga por até uma hora
  depois da troca — e ninguém desconfiaria.
- **Passar para produção exige digitar a palavra `PRODUCAO`**, e a exigência é
  cobrada no servidor. A partir dali toda cobrança é dinheiro de verdade.

### 7.3 A ordem de ligar

1. Cadastrar **homologação** e testar conexão.
2. Registrar o aviso na Efí de homologação (passo 8).
3. Fazer **uma inscrição inteira à mão**, do formulário ao e-mail de confirmação.
4. Só então repetir de 1 a 3 com as credenciais de **produção**.

Enquanto isso não estiver feito, deixe `PAYMENT_GATEWAY=fake`.

---

## 8. Registrar o endereço do aviso (webhook) na Efí

A Efí chama o nosso servidor quando o Pix cai. **Esse endereço precisa ser
registrado uma vez, à mão.** Não acontece quando o código sobe, e nada no sistema
avisa que faltou: as cobranças nascem normalmente e **nenhuma se confirma
sozinha**. O sinal de que faltou é a reconciliação confirmando *todos* os
pagamentos cinco minutos depois — ela é a rede de segurança, não o caminho
normal.

### 8.1 O endereço

A própria tela de credenciais **monta o endereço pronto**, no bloco "Endereço do
aviso automático". Ele tem esta forma:

```
https://inscricoes.cccista.com.br/webhooks/pagamentos?hmac=SEU_SEGREDO&ignorar=
```

**Copie da tela**, não digite. Duas partes do endereço parecem enfeite e não são:

- **`?hmac=`** carrega o segredo do aviso que você cadastrou no passo 7. Sem ele,
  todo aviso é recusado — a falha para o lado seguro.
- **`&ignorar=`** existe porque a Efí **acrescenta `/pix` ao fim** do endereço
  registrado na hora de notificar. Terminando em `ignorar=`, o sufixo cai numa
  parte descartável do endereço.

Como a documentação da Efí descreve esse comportamento das duas formas, **a
aplicação responde nos dois caminhos** — `/webhooks/pagamentos` e
`/webhooks/pagamentos/pix`. Custou uma linha; descobrir o engano custaria
pagamentos perdidos em silêncio.

Cole esse endereço no painel da Efí, em *API → Webhooks*, no ambiente
correspondente.

### 8.2 A defesa do endereço

O aviso da Efí tem **duas camadas**, e nenhuma delas é o mTLS que a Efí
recomenda (decisão **DA-28** — ver a seção 11):

1. **O HMAC**, conferido pela própria aplicação. Aviso sem assinatura válida é
   recusado e registrado.
2. **A lista de IP no Traefik**: um router com prioridade mais alta captura
   `/webhooks/pagamentos` e só deixa passar `34.193.116.226`. As labels já estão
   no compose; o valor é a variável `EFI_IP_PERMITIDO`.

A lista de IP vale **só nesse endereço**. A inscrição pública continua aberta a
qualquer pessoa, de qualquer lugar — e é bom que continue.

> **Traefik 2.9 ou anterior:** o middleware chamava-se `ipwhitelist`, não
> `ipallowlist`. Se o Traefik do servidor for antigo, o stack sobe mas o
> middleware não é reconhecido, e o Traefik registra o erro no próprio log.
> Trocar o nome na label resolve.

### 8.3 Como saber que o aviso chegou

Faça um Pix de valor mínimo em homologação e olhe o log do `app`:

```bash
docker logs <container do app> --tail 100 | grep -i webhook
```

Na tela, a inscrição vira **"inscrição confirmada"** sozinha, sem ninguém
apertar nada. É esse o sinal de que o caminho inteiro fechou.

---

## 9. Conferir que os e-mails saíram

Esta é a parte que mais tempo ficou pendente no projeto: **até esta implantação,
o trabalhador da fila nunca tinha sido subido**, e por isso nenhum e-mail jamais
chegou a ninguém. Agora ele é o container `worker`, e sobe junto com o resto.

Vale conferir mesmo assim, porque a falha aqui é silenciosa por natureza.

**1. O worker está de pé e consumindo?**

```bash
docker logs <container do worker> --tail 30
```

Cada e-mail entregue aparece como uma linha do tipo
`App\Mail\... DONE`. Se aparecer `FAIL`, o motivo vem junto.

**2. A fila está vazia?** Fila que só cresce é worker parado ou entrega falhando:

```bash
docker exec <container do app> php artisan queue:monitor redis:emails
```

**3. Alguma coisa falhou de vez?** O worker tenta 3 vezes, esperando 1, 5 e 15
minutos. Se as três falharem, o trabalho vai para `failed_jobs` com o erro
completo — e **nada acontece com a inscrição, a vaga ou o pagamento**. O prejuízo
máximo de uma falha de e-mail é um e-mail que não chegou.

```bash
docker exec <container do app> php artisan queue:failed      # o que falhou e por quê
docker exec <container do app> php artisan queue:retry all   # tentar de novo depois de resolver a causa
```

**4. O agendador está rodando?** Ele é quem expira inscrição vencida, lembra do
prazo e reconcilia pagamento:

```bash
docker exec <container do scheduler> php artisan schedule:list
```

Devem aparecer três rotinas: `inscricoes:expirar-vencidas` (a cada minuto),
`pagamentos:reconciliar` (a cada 5) e `inscricoes:lembrar-prazo` (a cada 15).

**5. A Resend aceitou?** No painel da Resend, em *Logs*, cada mensagem entregue
aparece com o destinatário e o resultado. Se o domínio do remetente não estiver
verificado, é aqui que se descobre.

---

## 10. A lista de conferência

Depois de tudo, percorra esta lista. Cada item falha em silêncio se estiver
errado — é por isso que ela existe.

- [ ] Os cinco containers em *running*, o `app` *healthy*
- [ ] `https://inscricoes.cccista.com.br/up` responde **200** com certificado válido
- [ ] A página do evento abre pelo celular
- [ ] Entra no painel com a conta criada no passo 6
- [ ] A tela **Credenciais de pagamento** mostra o ambiente ativo e o "Testar conexão" passa
- [ ] O endereço do aviso está registrado na Efí, com `?hmac=` e terminando em `&ignorar=`
- [ ] Uma inscrição de teste gera QR Code, e o pagamento a confirma **sozinha**
- [ ] O e-mail de cobrança chegou na caixa de entrada de verdade
- [ ] `schedule:list` mostra as três rotinas
- [ ] `queue:failed` está vazio

---

## 11. Atualizar o sistema depois

1. Push na `main` → o GitHub Actions publica a nova `:latest`.
2. No Portainer: stack `gestao-eventos` → **Update the stack**, com
   *Re-pull image* marcado.

No boot seguinte o `entrypoint` aplica as migrations novas e sincroniza os papéis
de novo, sozinho. **Não existe janela de manutenção neste desenho**: o container
antigo é derrubado e o novo sobe. Para um evento com inscrição aberta, prefira
atualizar em horário de pouco movimento.

Os dados sobrevivem: banco, Redis e os arquivos públicos enviados pela
administração vivem em volumes nomeados, que a atualização não toca.

---

## 12. Quando alguma coisa dá errado

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| **502 no Traefik** | `TRAEFIK_NETWORK` errado, ou o `app` não ficou *healthy* | Confira o nome exato da rede e o log do `app` |
| **Certificado não emite** | DNS ainda não aponta para o servidor, ou `TRAEFIK_CERTRESOLVER` errado | `dig +short` no domínio; confira o nome do resolvedor |
| **Erro sobre `APP_KEY`** | A variável ficou vazia | Gere e preencha; **se o sistema já estiver em uso, não troque** uma chave existente |
| **O link que chegou por e-mail devolve 403** | Proxy não confiado — não deveria acontecer, `bootstrap/app.php` já trata | Confira se `APP_URL` bate exatamente com o domínio, com `https` |
| **Nenhum e-mail chega, e nada dá erro** | `worker` parado, ou domínio não verificado na Resend | Seção 9 |
| **Cobranças nascem e nenhuma se confirma** | Endereço do aviso não registrado na Efí | Passo 8. Enquanto isso, a reconciliação confirma tudo em até 5 minutos |
| **"Testar conexão" passa, mas a cobrança falha** | **Falta o escopo `cob.write`** na aplicação da Efí | Passo 7.1 |
| **Vaga vencida não volta para a fila** | `scheduler` parado | `docker logs` do scheduler; confira `schedule:list` |
| **Uma tela nova não aparece para ninguém, sem erro** | O `PapeisSeeder` não rodou | Rode à mão: `php artisan db:seed --class=PapeisSeeder --force` |
| **O aviso da Efí não chega** | IP da Efí mudou, ou middleware `ipallowlist` não reconhecido | Log do Traefik; conferir `EFI_IP_PERMITIDO` e a seção 8.2 |

---

## 13. O que este roteiro **não** resolve

Escrito para ninguém supor o contrário:

- **Não há backup automatizado.** Os volumes do PostgreSQL e do Redis vivem no
  servidor e mais nada. Um `pg_dump` agendado para fora da máquina é a primeira
  coisa a montar depois desta implantação — inclusive porque, desde a fase 8b, é
  o backup do banco que carrega a credencial da Efí.
- **Não há monitoramento nem alerta.** Ninguém é avisado se o `worker` cair.
- **Não há réplica do banco** nem plano de recuperação.
- **O mTLS de verdade no aviso da Efí ficou fora** (decisão **DA-28**). A Efí
  recomenda exigir o certificado do cliente na borda; aqui a defesa é **HMAC mais
  lista de IP**. É um desvio consciente do que a Efí recomenda, e está escrito
  como tal — não é esquecimento.
- **Não há política de retenção nem descarte de dado pessoal.** A revisão de LGPD
  deste sistema **nunca foi feita**. É o maior buraco conhecido do projeto.
- **Não existe estorno.** Cancelar inscrição já paga não devolve dinheiro.

---

## Onde ler mais

| Assunto | Onde |
|---|---|
| Por que o sistema é assim | `docs/ARCHITECTURE.md` |
| O que a Efí exige do servidor | `docs/ARCHITECTURE.md`, seção 8.3 |
| Onde a credencial da Efí mora | `docs/ARCHITECTURE.md`, seção 8.4 |
| O trabalhador da fila | `docs/ARCHITECTURE.md`, seção 9.1 |
| Passo a passo da tela de credenciais, para quem não programa | `docs/PAYMENTS.md`, seção 10 |
| As regras de negócio | `docs/BUSINESS_RULES.md` |
| O que já foi feito e o que falta | `docs/PROGRESS.md` |
