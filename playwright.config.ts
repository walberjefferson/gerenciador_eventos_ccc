import { defineConfig, devices } from '@playwright/test';
import { PORTA_DO_SERVIDOR, URL_BASE, ambienteDeTeste } from './tests/e2e/ambiente';

/**
 * Suite de ponta a ponta do site publico.
 *
 * O navegador padrao imita um celular: e nele que a inscricao vai acontecer,
 * com a pessoa segurando o aparelho ao lado do aplicativo do banco.
 *
 * O servidor sobe aqui mesmo (`php artisan serve`) com o ambiente todo
 * declarado em tests/e2e/ambiente.ts, e o banco e recriado antes do primeiro
 * cenario (tests/e2e/semear.ts). Assim o resultado nao depende do estado que
 * ficou da ultima execucao nem da configuracao de quem executa.
 */
export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.ts',
    globalSetup: './tests/e2e/semear.ts',

    // Um cenario por vez: todos compartilham o mesmo banco semeado.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,

    timeout: 60_000,
    expect: { timeout: 15_000 },
    reporter: [['list']],

    use: {
        baseURL: URL_BASE,
        locale: 'pt-BR',
        timezoneId: 'America/Sao_Paulo',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'celular',
            use: { ...devices['Pixel 5'] },
        },
    ],

    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORTA_DO_SERVIDOR}`,
        url: `${URL_BASE}/up`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: ambienteDeTeste,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
