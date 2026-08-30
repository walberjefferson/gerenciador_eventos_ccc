#!/bin/sh
set -e

# ===========================================================================
# Entrypoint compartilhado pelos tres processos da aplicacao.
#
# A imagem e uma so. O que muda entre os conteineres e CONTAINER_ROLE (web,
# worker, scheduler) e o "command" do compose. Este arquivo e o unico lugar
# onde essa diferenca vira comportamento.
# ===========================================================================
ROLE="${CONTAINER_ROLE:-web}"

log() { echo "[entrypoint][$ROLE] $*"; }

# ---------------------------------------------------------------------------
# 1. Espera o banco. Sem ele nada funciona — nem o web, nem a fila, nem o
#    agendador. O conteiner do Postgres e o da aplicacao sobem juntos e o banco
#    demora alguns segundos a mais para aceitar conexao.
# ---------------------------------------------------------------------------
if [ -n "${DB_HOST:-}" ]; then
	log "aguardando o banco em ${DB_HOST}:${DB_PORT:-5432}..."
	i=0
	until php -r '
		$dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s",
			getenv("DB_HOST"), getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE"));
		new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
	' >/dev/null 2>&1; do
		i=$((i + 1))
		if [ "$i" -ge 60 ]; then
			log "ERRO: banco nao respondeu apos 60 tentativas."
			exit 1
		fi
		sleep 2
	done
	log "banco disponivel."
fi

# ---------------------------------------------------------------------------
# 2. Espera o Redis. Aqui ele nao e enfeite: a fila dos e-mails e o cache moram
#    nele. Se a aplicacao subir antes, a primeira requisicao que tentar cachear
#    ou enfileirar quebra — e o sintoma (erro 500 no formulario de inscricao)
#    nao lembra em nada a causa.
# ---------------------------------------------------------------------------
if [ -n "${REDIS_HOST:-}" ]; then
	log "aguardando o redis em ${REDIS_HOST}:${REDIS_PORT:-6379}..."
	i=0
	until php -r '
		$socket = @fsockopen(getenv("REDIS_HOST"), (int) (getenv("REDIS_PORT") ?: 6379), $erro, $mensagem, 2);
		exit($socket ? 0 : 1);
	' >/dev/null 2>&1; do
		i=$((i + 1))
		if [ "$i" -ge 60 ]; then
			log "ERRO: redis nao respondeu apos 60 tentativas."
			exit 1
		fi
		sleep 2
	done
	log "redis disponivel."
fi

# ---------------------------------------------------------------------------
# 3. Otimizacoes de runtime. Todas idempotentes, e todas depois das variaveis
#    ja estarem injetadas — e por isso que rodam aqui e nao no Dockerfile: o
#    cache de configuracao precisa gravar os valores DESTE ambiente.
# ---------------------------------------------------------------------------
php artisan storage:link 2>/dev/null || true
# Refaz o manifesto de pacotes a partir do vendor real (sem os de desenvolvimento).
php artisan package:discover --ansi
php artisan optimize

# ---------------------------------------------------------------------------
# 4. Migrations e papeis: SO no papel web.
#
#    Os tres conteineres sobem ao mesmo tempo. Se os tres rodassem "migrate",
#    disputariam a mesma tabela de controle e um deles falharia — ou pior,
#    aplicariam a mesma migracao duas vezes. O web e o eleito por ser o unico
#    que precisa do banco pronto para responder a primeira requisicao.
#
#    O seeder de papeis roda logo depois, e roda a CADA boot de proposito.
#    Permissao nova nasce no codigo e so existe de verdade depois de gravada no
#    banco: sem este passo, uma tela nova simplesmente nao aparece para ninguem
#    — o menu some e o acesso direto responde 403, sem erro nenhum no log. Ja
#    aconteceu em desenvolvimento; em producao seria muito pior de diagnosticar.
#    O seeder e idempotente por desenho (decisao D-50), entao repetir nao
#    duplica papel nem permissao.
# ---------------------------------------------------------------------------
if [ "$ROLE" = "web" ]; then
	log "aplicando migrations..."
	php artisan migrate --force

	log "sincronizando papeis e permissoes..."
	php artisan db:seed --class=PapeisSeeder --force
fi

log "iniciando: $*"
exec "$@"
