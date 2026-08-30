import type { Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A identidade visual do lado publico, no navegador.
 *
 * O tema verde vale para as seis telas do visitante e para nenhuma outra. Isso
 * parece obvio olhando o CSS e nao e: o escopo mora no `<html>`, e um escopo
 * mal posto falha de dois jeitos opostos — ou nao alcanca o que devia (a lista
 * de setores, que e teleportada para fora da pagina), ou alcanca o que nao
 * devia (o painel administrativo, que continua azul).
 *
 * Os quatro cenarios abaixo cobrem os dois lados, e o terceiro e o mais
 * importante do arquivo: ele e o unico que denuncia escopo posto na subarvore
 * da pagina em vez de no `<html>`.
 */

const SENHA = 'senha-de-teste-da-identidade';
const ADMINISTRADOR = 'identidade.administradora@example.com';

/** O verde-mata da marca e o papel de fundo, como o navegador os devolve. */
const VERDE_MATA = 'rgb(15, 107, 78)';
const PAPEL = 'rgb(241, 243, 238)';
/** O fio de separacao do tema publico. No painel ele e `#E4E4E7`. */
const LINHA_PUBLICA = 'rgb(222, 227, 219)';

/**
 * O valor de um token do tema, lido do proprio `<html>`.
 *
 * O valor volta normalizado — maiuscula e seis digitos — porque o
 * `npm run build` minifica os hexadecimais: `#FFFFFF` vira `#fff`. Sem isto o
 * cenario passaria com `npm run dev` e falharia com o CSS construido, que e
 * justamente o que roda em producao.
 */
async function token(page: Page, nome: string): Promise<string> {
    const valor = await page.evaluate((alvo) => getComputedStyle(document.documentElement).getPropertyValue(alvo).trim(), nome);

    const curto = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(valor);

    return (curto === null ? valor : `#${curto[1]}${curto[1]}${curto[2]}${curto[2]}${curto[3]}${curto[3]}`).toUpperCase();
}

/** Cria (ou reaproveita) a conta que abre o painel. */
function prepararAdministradora(): void {
    artisan([
        'tinker',
        '--execute',
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
            `['email' => '${ADMINISTRADOR}'],` +
            `['name' => 'Administradora da identidade', 'password' => '${SENHA}', 'email_verified_at' => now()]` +
            `);` +
            `$usuario->syncRoles(['administrador']);`,
    ]);
}

async function entrarNoPainel(page: Page): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(ADMINISTRADOR);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test('a porta da rua sai com fundo papel e o botao principal verde-mata', async ({ page }) => {
    await page.goto('/');

    // O atributo veio do servidor: a primeira pintura ja e a certa, sem piscada.
    await expect(page.locator('html')).toHaveAttribute('data-tema', 'publico');
    expect(await token(page, '--primary')).toBe('#0F6B4E');

    // O fundo da pagina e o papel do prototipo, e nao o branco do painel.
    const fundo = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(fundo, 'a home nao esta no fundo papel').toBe(PAPEL);

    const botao = page.getByTestId('botao-fazer-inscricao');
    const forma = await botao.evaluate((elemento) => {
        const estilo = getComputedStyle(elemento);

        return {
            fundo: estilo.backgroundColor,
            raio: estilo.borderRadius,
            altura: elemento.getBoundingClientRect().height,
        };
    });

    expect(forma.fundo, 'o botao principal nao esta no verde-mata').toBe(VERDE_MATA);
    // Pilula: o raio e grande o bastante para virar semicirculo nas pontas.
    expect(Number.parseFloat(forma.raio)).toBeGreaterThanOrEqual(forma.altura / 2);
    expect(forma.altura).toBeGreaterThanOrEqual(48);
});

test('o painel administrativo continua no azul de hoje', async ({ page }) => {
    prepararAdministradora();
    await entrarNoPainel(page);

    for (const endereco of ['/admin/painel', '/admin/inscricoes']) {
        await page.goto(endereco);

        await expect(page.locator('html')).toHaveAttribute('data-tema', 'admin');

        // As ancoras do tema do studio, intactas.
        expect(await token(page, '--primary'), `${endereco} perdeu o azul do painel`).toBe('#155DFC');
        expect(await token(page, '--background'), `${endereco} mudou de fundo`).toBe('#FFFFFF');
        expect(await token(page, '--cor-acao')).toBe('#155DFC');

        const fundo = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        expect(fundo, `${endereco} saiu com o fundo papel do lado publico`).toBe('rgb(255, 255, 255)');
    }
});

test('a lista de setores, que e teleportada para fora da pagina, sai no tema publico', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await page.getByLabel('Setor', { exact: true }).click();
    await expect(page.getByRole('option', { name: 'Setor Batalha', exact: true })).toBeVisible();

    const painel = page.getByRole('listbox').first();

    const retrato = await painel.evaluate((elemento) => ({
        // O portal do reka-ui pendura o painel no `document.body`: ele NAO esta
        // mais dentro do `<main>` da pagina. E justamente por isso que o escopo
        // do tema precisa morar no `<html>` — daqui de dentro, um escopo posto
        // no PublicoLayout nao alcancaria nada.
        saiuDaPagina: elemento.closest('main') === null,
        // ...e, mesmo tendo saido, ele continua sob o tema publico.
        tema: elemento.closest('[data-tema]')?.getAttribute('data-tema') ?? null,
        borda: getComputedStyle(elemento).borderTopColor,
        raio: getComputedStyle(elemento).borderRadius,
    }));

    expect(retrato.saiuDaPagina, 'o painel deixou de ser um portal — o cenario perdeu o sentido').toBe(true);
    expect(retrato.tema, 'a lista de setores saiu fora do tema publico').toBe('publico');
    expect(retrato.borda, 'a lista de setores esta com o fio do painel administrativo').toBe(LINHA_PUBLICA);
    // O raio do tema publico e 14px, e `rounded-md` tira 2px: 12px. No painel
    // seriam 8px.
    expect(Number.parseFloat(retrato.raio)).toBeCloseTo(12, 1);
});

test('em 320px nada escapa da tela e todo alvo de dedo tem 44px', async ({ page }) => {
    // 320px e a largura do aparelho mais estreito ainda em uso.
    await page.setViewportSize({ width: 320, height: 640 });

    for (const endereco of ['/', `/eventos/${EVENTO_DEMO.slug}`, `/eventos/${EVENTO_DEMO.slug}/inscricao`, '/acesso']) {
        await page.goto(endereco);

        const escapou = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

        expect(escapou, `${endereco} escapa da largura da tela em 320px`).toBeLessThanOrEqual(0);

        const pequenos = await page.evaluate(() => {
            const problemas: string[] = [];

            document.querySelectorAll<HTMLElement>('a[href], button, input, select, [role="button"]').forEach((alvo) => {
                const caixa = alvo.getBoundingClientRect();

                if (caixa.width === 0 || caixa.height === 0) {
                    return;
                }

                // O atalho do leitor de tela ocupa 1x1 ate receber o foco, e um
                // link dentro de texto corrido nao e alvo de dedo.
                if (alvo.classList.contains('sr-only') || alvo.closest('.sr-only') !== null) {
                    return;
                }

                if (alvo.tagName === 'A' && getComputedStyle(alvo).display === 'inline') {
                    return;
                }

                if (caixa.height < 44) {
                    problemas.push(`${alvo.tagName} "${(alvo.textContent ?? '').trim().slice(0, 30)}" ${Math.round(caixa.height)}px`);
                }
            });

            return problemas;
        });

        expect(pequenos, `alvos pequenos demais em ${endereco}`).toEqual([]);
    }
});
