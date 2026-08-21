import type { Page } from '@playwright/test';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A porta do lado de dentro.
 *
 * Tres perguntas, uma de cada vez: quem nao entrou fica de fora, quem entrou
 * mas nao foi convidado tambem, e quem tem o papel de administrador ve os
 * numeros do evento. No fim, a quarta: o cadastro publico realmente sumiu.
 *
 * As contas nascem por linha de comando, do lado de fora do navegador, porque
 * e assim que elas nascem no sistema de verdade — o cadastro publico foi
 * fechado de proposito (DA-11).
 */

const SENHA = 'senha-de-teste-do-painel';

const ADMINISTRADOR = 'painel.administrador@example.com';
const SEM_PAPEL = 'painel.curioso@example.com';

/**
 * Cria (ou reaproveita) uma conta com o papel pedido.
 *
 * Papel nulo e o caso mais interessante do arquivo: alguem que tem senha e
 * consegue entrar, mas nao foi convidado para nada.
 */
function prepararConta(email: string, nome: string, papel: string | null): void {
    const papeis = papel === null ? '[]' : `['${papel}']`;

    artisan([
        'tinker',
        '--execute',
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
            `['email' => '${email}'],` +
            `['name' => '${nome}', 'password' => '${SENHA}', 'email_verified_at' => now()]` +
            `);` +
            `$usuario->syncRoles(${papeis});`,
    ]);
}

/** Entra pela tela de login, com os mesmos gestos de quem administra o evento. */
async function entrar(page: Page, email: string): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(email);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test.beforeAll(() => {
    prepararConta(ADMINISTRADOR, 'Administradora do painel', 'administrador');
    prepararConta(SEM_PAPEL, 'Pessoa sem papel', null);
});

test('visitante que tenta o painel e mandado para o login', async ({ page }) => {
    await page.goto('/admin/painel');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('button', { name: /log in/i })).toBeVisible();

    // E nenhum numero do painel vazou junto com o desvio.
    await expect(page.getByTestId('painel-inscricoes')).toHaveCount(0);
});

test('quem entrou mas nao tem papel nenhum ve a recusa, nao o painel', async ({ page }) => {
    await entrar(page, SEM_PAPEL);

    const resposta = await page.goto('/admin/painel');

    expect(resposta?.status()).toBe(403);
    await expect(page.getByTestId('painel-inscricoes')).toHaveCount(0);
});

test('o administrador ve os tres blocos de numeros do evento', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);

    await page.goto('/admin/painel');

    await expect(page.getByRole('heading', { name: 'Painel', level: 1 })).toBeVisible();

    // Bloco 1: quantas inscricoes existem e em que situacao estao.
    await expect(page.getByTestId('painel-inscricoes')).toBeVisible();
    await expect(page.getByText('Total de inscrições')).toBeVisible();

    // Bloco 2: as vagas de cada atividade, lidas dos contadores do dominio.
    await expect(page.getByTestId('painel-vagas')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Restantes' })).toBeVisible();

    // Bloco 3: o dinheiro que entrou e o que ainda pode entrar.
    await expect(page.getByTestId('painel-dinheiro')).toBeVisible();
    await expect(page.getByText('Recebido', { exact: true })).toBeVisible();

    // O evento aparece no seletor, que e o que torna a tela util com mais de um.
    await expect(page.getByLabel('Evento')).toBeVisible();
});

test('o cadastro publico nao existe mais', async ({ page }) => {
    const tela = await page.request.get('/register');
    expect(tela.status()).toBe(404);

    const envio = await page.request.post('/register', {
        form: { name: 'Alguem', email: 'alguem@example.com', password: 'senha-qualquer', password_confirmation: 'senha-qualquer' },
        failOnStatusCode: false,
    });
    expect(envio.status()).toBe(404);

    // E a tela de login nao oferece mais o caminho para se cadastrar.
    await page.goto('/login');
    await expect(page.getByRole('link', { name: /sign up|cadastr/i })).toHaveCount(0);
});
