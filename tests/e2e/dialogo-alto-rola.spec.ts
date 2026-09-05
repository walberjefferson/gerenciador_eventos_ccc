import type { Page } from '@playwright/test';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * Um dialogo alto demais para a tela precisa rolar por dentro.
 *
 * O cadastro de responsavel e o mais alto do painel — quatro campos, cada um
 * com a sua frase de ajuda, mais a lista de setores atendidos. No celular ele
 * passava da altura da janela, e o que sobrava (a lista de setores e os botoes
 * Cancelar/Cadastrar) simplesmente nao existia para quem estava usando: a
 * moldura do dialogo nao tinha teto de altura nem rolagem propria.
 *
 * O cenario mede tres coisas no navegador, e nao no codigo: a moldura cabe na
 * janela, o conteudo de dentro e maior que ela (ou seja, ha rolagem de verdade
 * a fazer) e o botao de gravar — o ultimo elemento de todos — e alcancavel.
 *
 * Roda no celular, que e o viewport padrao da suite e o caso mais apertado.
 */

const SENHA = 'senha-de-teste-do-painel';
const ADMINISTRADORA = 'dialogo.administradora@example.com';

/** Cria a conta pela linha de comando: nao ha tela de cadastro (D-51). */
function prepararConta(): void {
    artisan([
        'tinker',
        '--execute',
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
            `['email' => '${ADMINISTRADORA}'],` +
            `['name' => 'Administradora do dialogo', 'password' => '${SENHA}', 'email_verified_at' => now(), 'ativo' => true]` +
            `);` +
            `$usuario->syncRoles(['administrador']);`,
    ]);
}

async function entrar(page: Page): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(ADMINISTRADORA);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test.beforeAll(() => {
    prepararConta();
});

test('o cadastro de responsavel cabe na tela do celular e rola ate o botao de gravar', async ({ page }) => {
    await entrar(page);
    await page.goto('/admin/catalogo/responsaveis');

    await page.getByRole('button', { name: 'Novo responsável' }).click();

    const dialogo = page.getByRole('dialog');
    await expect(dialogo).toBeVisible();

    // A moldura entra deslizando e crescendo (`slide-in`, `zoom-in`). Medir no
    // meio disso devolve a geometria da animacao, e nao a da tela parada.
    await dialogo.evaluate(async (elemento) => {
        await Promise.all(elemento.getAnimations().map((animacao) => animacao.finished.catch(() => undefined)));
    });

    const medida = await dialogo.evaluate((elemento) => {
        const caixa = elemento.getBoundingClientRect();

        return {
            topo: caixa.top,
            base: caixa.bottom,
            altura: caixa.height,
            janela: window.innerHeight,
            conteudo: elemento.scrollHeight,
            visivel: elemento.clientHeight,
        };
    });

    // Cabe na janela, e por inteiro: nem estoura embaixo nem sobe para fora.
    expect(medida.altura).toBeLessThanOrEqual(medida.janela);
    expect(medida.topo).toBeGreaterThanOrEqual(0);
    expect(medida.base).toBeLessThanOrEqual(medida.janela + 1);

    // O conteudo e maior que a moldura — ha rolagem de verdade a fazer, e e
    // exatamente esse o caso que antes escondia o fim do formulario.
    expect(medida.conteudo).toBeGreaterThan(medida.visivel);

    // A lista de setores e os botoes existem para quem esta usando.
    const setores = dialogo.getByTestId('setores-do-responsavel');
    await setores.scrollIntoViewIfNeeded();
    await expect(setores).toBeInViewport();

    const gravar = dialogo.getByRole('button', { name: 'Cadastrar' });
    await gravar.scrollIntoViewIfNeeded();
    await expect(gravar).toBeInViewport();
});
