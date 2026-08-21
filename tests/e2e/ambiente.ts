/**
 * O ambiente em que a suite de ponta a ponta roda.
 *
 * Tudo aqui e explicito de proposito: o servidor de teste nao pode depender do
 * .env de quem esta executando, senao o resultado muda de maquina para maquina.
 * As variaveis definidas neste arquivo sao passadas para o processo do
 * `php artisan serve` e para os comandos de semeadura — e, como o Dotenv do
 * Laravel nao sobrescreve variavel que ja existe no processo, elas vencem o
 * .env. Usuario e senha do banco continuam vindo do .env: sao de cada maquina.
 *
 * ATENCAO: a suite usa o banco `testing`, o mesmo do Pest, e o recria do zero
 * antes de comecar. Nao rode `npm run test:e2e` junto com `php artisan test`.
 */

export const PORTA_DO_SERVIDOR = Number(process.env.E2E_PORT ?? 8123);

export const URL_BASE = process.env.E2E_URL ?? `http://127.0.0.1:${PORTA_DO_SERVIDOR}`;

export const ambienteDeTeste: Record<string, string> = {
    // "testing" e um dos dois ambientes em que as rotas de simulacao de
    // pagamento existem (o outro e "local"). Sem elas nao da para percorrer o
    // ciclo do dinheiro sem instituicao financeira.
    APP_ENV: 'testing',
    APP_DEBUG: 'true',
    APP_URL: URL_BASE,
    APP_TIMEZONE: 'America/Sao_Paulo',

    DB_CONNECTION: 'pgsql',
    DB_HOST: '127.0.0.1',
    // O Postgres do Sail responde nesta porta do host (decisao D-19).
    DB_PORT: '55432',
    DB_DATABASE: 'testing',

    // Arquivo em vez de banco: a semeadura derruba as tabelas a cada execucao,
    // e sessao perdida no meio do caminho quebraria o formulario.
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log',

    PAYMENT_GATEWAY: 'fake',
    PAYMENT_FAKE_SIMULATION_ENABLED: 'true',
    PAYMENT_FAKE_WEBHOOK_SECRET: 'pepper-de-teste-do-webhook',
    DOCUMENTO_HASH_PEPPER: 'pepper-de-teste-do-documento',

    // A suite inteira sai de um endereco so e envia dezenas de inscricoes em
    // poucos minutos — ou seja, ela se parece com um script, que e exatamente
    // o que o limite existe para conter. Aqui os tetos sao declarados bem
    // acima do padrao para que o navegador teste as telas, e nao o limite.
    // Quem prova o limite, com o numero real de producao, e o Pest
    // (tests/Feature/Seguranca/LimitesTest.php).
    INSCRICOES_LIMITE_CRIAR_MINUTO: '1000',
    INSCRICOES_LIMITE_CRIAR_HORA: '5000',

    // O servidor embutido do PHP atende uma requisicao por vez. A tela da
    // cobranca consulta a situacao enquanto a pessoa navega: sem estes
    // trabalhadores extras, uma consulta seguraria a proxima pagina.
    PHP_CLI_SERVER_WORKERS: '4',
};

/** O evento de demonstracao criado por database/seeders/EventoDemoSeeder.php. */
export const EVENTO_DEMO = {
    slug: 'copa-ccc-2026',
    nome: 'Copa CCC 2026',
    valor: 'R$ 120,00',
};
