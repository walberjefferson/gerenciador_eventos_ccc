# syntax=docker/dockerfile:1

# ===========================================================================
# Imagem de producao — Gestao de Eventos
#
# Uma imagem so, tres processos. O conteiner web, o trabalhador da fila e o
# agendador saem todos daqui: mesma versao de codigo, mesmo vendor, mesmos
# assets. Quem decide o papel e a variavel CONTAINER_ROLE, lida pelo
# entrypoint, e o "command" do compose (decisao DA-32).
#
# Tres estagios, para que nada de construcao sobre na imagem final: o Node nao
# vai junto, o Composer nao vai junto, e as dependencias de desenvolvimento nao
# existem.
# ===========================================================================

# ---------------------------------------------------------------------------
# Estagio 1 — Dependencias PHP (Composer, sem as de desenvolvimento)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# --no-scripts porque o artisan ainda nao esta aqui; --no-dev porque Pest,
# Pint, Sail e Pail nao tem o que fazer em producao.
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction \
      --no-progress

# Com a arvore completa, o autoload e otimizado (mapa de classes fixo).
COPY . .
RUN composer dump-autoload --optimize --no-dev


# ---------------------------------------------------------------------------
# Estagio 2 — Assets do frontend (Vite)
# ---------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /app

# As dependencias entram primeiro, sozinhas: assim uma mudanca em .vue nao
# invalida o cache do npm ci, que e a parte lenta.
COPY package.json package-lock.json ./
RUN npm ci

# O restante do projeto e o build que gera public/build.
COPY . .

# O Ziggy e a unica peca PHP de que o build precisa: resources/js/app.ts importa
# a tabela de rotas direto de vendor/tightenco/ziggy. Sem isto o build quebra no
# rollup — e por isso o estagio do Composer vem antes deste.
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy

RUN npm run build


# ---------------------------------------------------------------------------
# Estagio 3 — Runtime (FrankenPHP + PHP 8.4)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS runtime

# O FrankenPHP serve HTTP simples na :80. Quem termina o TLS e o Traefik, e e
# por isso que bootstrap/app.php confia nos cabecalhos de proxy.
ENV SERVER_NAME=:80
ENV FRANKENPHP_CONFIG=""

WORKDIR /app

# Extensoes exigidas pela aplicacao:
# - pdo_pgsql/pgsql: o banco, que e PostgreSQL em todo ambiente.
# - redis: fila (emails) e cache. Sem ela o conteiner sobe e nenhum e-mail sai.
# - pcntl: o queue:work precisa dela para respeitar --max-time e parar limpo.
# - opcache: o codigo compilado uma vez so.
# - intl, bcmath: formatacao e dinheiro em centavos.
# - zip: leitura do certificado .p12 da Efi e afins.
# - gd: o QR Code do INGRESSO em PNG. Sem ela o ingresso nao aparece no e-mail
#   nem no PDF — Gmail e Outlook nao exibem SVG, e o dompdf nao e confiavel com
#   SVG. O QR do Pix continua em SVG puro-PHP e nao depende dela.
# openssl e curl ja vem no PHP da imagem base — o SDK da Efi depende dos dois
# para o mTLS com a instituicao financeira.
RUN install-php-extensions \
      pdo_pgsql \
      pgsql \
      redis \
      pcntl \
      opcache \
      intl \
      zip \
      gd \
      bcmath

# Configuracao de producao do PHP/OPcache.
# validate_timestamps=0: o codigo nunca muda dentro de um conteiner rodando;
# reler o disco a cada requisicao seria trabalho jogado fora.
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.memory_consumption=192"; \
      echo "opcache.max_accelerated_files=20000"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.jit=tracing"; \
      echo "opcache.jit_buffer_size=64M"; \
      echo "expose_php=0"; \
      echo "memory_limit=512M"; \
      echo "upload_max_filesize=25M"; \
      echo "post_max_size=25M"; \
    } > "$PHP_INI_DIR/conf.d/zz-app.ini"

# Codigo da aplicacao + vendor de producao + assets ja construidos.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Caddyfile do FrankenPHP e o entrypoint compartilhado pelos tres papeis.
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# storage precisa ser gravavel em TODOS os papeis, e nao so no web: o
# certificado da Efi e materializado do banco para storage/certificados na hora
# de cobrar, e quem cobra pode ser o agendador (reconciliacao) ou o trabalhador.
# Sem permissao de escrita ali, nenhuma cobranca nasce.
RUN chmod +x /usr/local/bin/entrypoint \
      && mkdir -p \
          /app/storage/app/private \
          /app/storage/app/public \
          /app/storage/framework/cache/data \
          /app/storage/framework/sessions \
          /app/storage/framework/views \
          /app/storage/logs \
          /app/storage/certificados \
      && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
      && chmod 700 /app/storage/certificados

ENTRYPOINT ["entrypoint"]

# Papel padrao: servidor web. O trabalhador e o agendador sobrescrevem o
# command no compose, usando esta mesma imagem.
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
