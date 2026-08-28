import type { Locator, Page } from '@playwright/test';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A barra lateral do painel, vista de um computador.
 *
 * Este arquivo existe por causa de um defeito que ficou semanas invisivel: a
 * barra lateral perdeu a largura declarada, virou uma faixa flutuante por cima
 * do conteudo e cobriu o proprio botao de recolher. Ninguem viu porque toda a
 * suite de navegador roda num Pixel 5 — e abaixo de 768px a barra nem chega a
 * ser barra: vira uma gaveta. O caminho quebrado so existe em tela grande, e
 * tela grande nunca era aberta.
 *
 * Por isso este e o unico arquivo da suite que declara o proprio tamanho de
 * tela. Ele nao vira um projeto novo no `playwright.config.ts` de proposito:
 * um projeto extra faria os outros quarenta cenarios de celular rodarem duas
 * vezes so para provar uma coisa.
 */

const SENHA = 'senha-de-teste-do-painel';
const ADMINISTRADOR = 'barra.administradora@example.com';

/** O que o `SIDEBAR_WIDTH` de `components/ui/sidebar/utils.ts` promete: 16rem. */
const LARGURA_ABERTA = 256;

/**
 * Cria a conta pela linha de comando, como nascem as contas de verdade.
 *
 * O cadastro publico foi fechado de proposito (DA-11), entao nao existe tela
 * por onde o teste pudesse passar para chegar aqui.
 */
function prepararAdministradora(): void {
    artisan([
        'tinker',
        '--execute',
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
            `['email' => '${ADMINISTRADOR}'],` +
            `['name' => 'Administradora da barra', 'password' => '${SENHA}', 'email_verified_at' => now()]` +
            `);` +
            `$usuario->syncRoles(['administrador']);`,
    ]);
}

/** Entra pela tela de login e para no painel, com os gestos de quem administra. */
async function abrirOPainel(page: Page): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(ADMINISTRADOR);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));

    await page.goto('/admin/painel');
    await expect(page.getByRole('heading', { name: 'Painel', level: 1 })).toBeVisible();
}

/** A caixa do elemento na tela, com a falha dizendo qual elemento sumiu. */
async function caixaDe(elemento: Locator, nome: string): Promise<{ x: number; y: number; width: number; height: number }> {
    const caixa = await elemento.boundingBox();

    expect(caixa, `${nome} precisa estar desenhado na tela`).not.toBeNull();

    return caixa!;
}

/**
 * As tres pecas que interessam.
 *
 * - `painel` e a barra de verdade: fica em `position: fixed`, e e ela que
 *   cobria o conteudo quando ficou sem largura;
 * - `reserva` e o irmao invisivel que existe so para segurar a coluna da
 *   esquerda — sem largura nele, o conteudo escorre para debaixo da barra;
 * - `conteudo` e o `<main>` com o resto da tela.
 */
function pecas(page: Page) {
    return {
        involucro: page.locator('div.peer[data-state]'),
        painel: page.locator('div.peer > div.fixed'),
        reserva: page.locator('div.peer > div.relative'),
        conteudo: page.locator('main').first(),
        botao: page.getByRole('button', { name: 'Toggle Sidebar' }),
    };
}

/** Espera a animacao de 200ms terminar e devolve a largura ja estavel. */
async function larguraEstavel(elemento: Locator, nome: string): Promise<number> {
    let ultima = -1;

    await expect
        .poll(
            async () => {
                const atual = Math.round((await caixaDe(elemento, nome)).width);
                const estavel = atual === ultima;
                ultima = atual;

                return estavel;
            },
            { message: `${nome} nao parou de mudar de largura` },
        )
        .toBe(true);

    return ultima;
}

test.beforeAll(() => {
    prepararAdministradora();
});

test.describe('em tela grande', () => {
    // O unico lugar da suite que abre uma tela de computador. O caminho
    // defeituoso da barra lateral so existe acima de 768px.
    test.use({
        viewport: { width: 1280, height: 800 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('a barra lateral ocupa a coluna da esquerda, sem cobrir o conteudo', async ({ page }) => {
        await abrirOPainel(page);

        const { involucro, painel, reserva, conteudo } = pecas(page);

        await expect(involucro).toHaveAttribute('data-state', 'expanded');
        await expect(painel).toBeVisible();

        // A asserção que falhava antes do conserto. Com `w-[--sidebar-width]`, o
        // Tailwind 4 gerava `width:--sidebar-width` — valor que o navegador
        // descarta —, e esta medida vinha perto de zero.
        const barra = await caixaDe(painel, 'a barra lateral');
        expect(Math.round(barra.width), 'a barra precisa ter os 16rem do SIDEBAR_WIDTH').toBe(LARGURA_ABERTA);

        // E a coluna reservada precisa ter a mesma largura: e ela que empurra o
        // conteudo para o lado em vez de deixa-lo passar por baixo.
        const espaco = await caixaDe(reserva, 'a coluna reservada para a barra');
        expect(Math.round(espaco.width), 'a coluna reservada precisa acompanhar a barra').toBe(LARGURA_ABERTA);

        // O teste que da nome ao defeito: nada de barra por cima do conteudo.
        const texto = await caixaDe(conteudo, 'a coluna de conteudo');
        expect(barra.x + barra.width, 'a barra nao pode invadir a area do conteudo').toBeLessThanOrEqual(texto.x);
    });

    test('o botao de recolher esta ao alcance, encolhe a barra e devolve a largura ao conteudo', async ({ page }) => {
        await abrirOPainel(page);

        const { involucro, painel, conteudo, botao } = pecas(page);

        // Ele sempre esteve montado no cabecalho. O que faltava era nao estar
        // debaixo da barra: um botao coberto e um botao que nao existe.
        await expect(botao).toBeVisible();
        await expect(botao).toBeEnabled();

        const larguraAberta = await larguraEstavel(painel, 'a barra lateral');
        const conteudoAberto = await larguraEstavel(conteudo, 'a coluna de conteudo');

        expect(larguraAberta).toBe(LARGURA_ABERTA);

        await botao.click();

        await expect(involucro).toHaveAttribute('data-state', 'collapsed');

        const larguraRecolhida = await larguraEstavel(painel, 'a barra lateral recolhida');
        const conteudoRecolhido = await larguraEstavel(conteudo, 'a coluna de conteudo com a barra recolhida');

        // "collapsible=icon": ela encolhe para a faixa de icones (3rem mais a
        // folga da variante "inset"), nao some da tela.
        expect(larguraRecolhida, 'a barra recolhida precisa virar a faixa de icones').toBeLessThan(LARGURA_ABERTA / 2);
        expect(larguraRecolhida, 'a faixa de icones continua visivel').toBeGreaterThan(40);

        // O espaco que a barra devolveu tem que aparecer no conteudo.
        expect(conteudoRecolhido, 'o conteudo precisa ganhar a largura que a barra devolveu').toBeGreaterThan(conteudoAberto);

        await botao.click();

        await expect(involucro).toHaveAttribute('data-state', 'expanded');

        expect(await larguraEstavel(painel, 'a barra lateral de volta')).toBe(LARGURA_ABERTA);
        expect(await larguraEstavel(conteudo, 'a coluna de conteudo de volta')).toBe(conteudoAberto);
    });
});

test.describe('no celular', () => {
    // Sem `test.use`: fica valendo o Pixel 5 de 393px do playwright.config.ts,
    // o mesmo aparelho dos outros cenarios da suite.

    test('a barra continua sendo gaveta, e a gaveta obedece a largura que o componente declara', async ({ page }) => {
        await abrirOPainel(page);

        const { involucro, botao } = pecas(page);

        // Abaixo de 768px o trecho de tela grande nem chega a ser renderizado.
        await expect(involucro).toHaveCount(0);

        await botao.click();

        const gaveta = page.getByRole('dialog');
        await expect(gaveta).toBeVisible();
        // Os dois atributos ficam no proprio elemento do dialogo: o
        // `SheetContent` os repassa para a raiz que ele desenha.
        await expect(gaveta).toHaveAttribute('data-sidebar', 'sidebar');
        await expect(gaveta).toHaveAttribute('data-mobile', 'true');

        // 18rem: o SIDEBAR_WIDTH_MOBILE do proprio componente. Esta medida
        // existe porque a gaveta so obedece essa constante por causa do "!" na
        // classe de largura — o `tailwind-merge` 2.6.0 nao apaga o `w-3/4` que
        // o SheetContent traz de fabrica. Quem for cumprir a pendencia P-11
        // (subir o merge para a 3.x) pode tirar o "!"; se tirar antes da hora,
        // e esta linha que avisa, com a gaveta indo para 295px.
        expect(await larguraEstavel(gaveta, 'a gaveta do celular'), 'a gaveta precisa ter os 18rem do SIDEBAR_WIDTH_MOBILE').toBe(288);
    });
});
