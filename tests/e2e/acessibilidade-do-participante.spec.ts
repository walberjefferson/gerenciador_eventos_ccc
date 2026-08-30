import type { Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * A mesma varredura da vitrine, agora nas duas telas novas do participante.
 *
 * As exigencias nao mudam: percorrer no teclado com o foco sempre visivel,
 * alvo de toque que cabe no dedo, nada escapando da largura de uma tela de
 * 320 px, e estado que se le sem depender da cor.
 *
 * As duas medidas abaixo repetem, de proposito, as da varredura da vitrine:
 * aquele arquivo e um cenario fechado, e um cenario nao deve importar do
 * outro. Quando as duas varreduras virarem uma so, elas voltam a ser uma so.
 */
const noCelular: PessoaDeTeste = {
    nome: 'Adalberto Nogueira Reis',
    email: 'adalberto.reis@example.com',
    telefone: '(11) 96543-6060',
    cpf: '74185296355',
    nascimento: '1972-10-05',
};

const noTeclado: PessoaDeTeste = {
    nome: 'Iracema Duarte Sampaio',
    email: 'iracema.sampaio@example.com',
    telefone: '(11) 96543-7070',
    cpf: '15935745682',
    nascimento: '1986-01-30',
};

const semCores: PessoaDeTeste = {
    nome: 'Rubens Antunes Vasques',
    email: 'rubens.vasques@example.com',
    telefone: '(11) 96543-8080',
    cpf: '12345678909',
    nascimento: '1994-07-11',
};

/** A largura util da pagina nao pode passar da largura da janela. */
async function rolagemHorizontal(page: Page): Promise<number> {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

/** Alvos de toque menores que 44x44, com a mesma excecao de link em linha. */
async function alvosPequenos(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const problemas: string[] = [];
        const alvos = document.querySelectorAll<HTMLElement>('button, a[href], [role="button"]');

        alvos.forEach((alvo) => {
            const caixa = alvo.getBoundingClientRect();

            if (caixa.width === 0 || caixa.height === 0) {
                return;
            }

            if (alvo.classList.contains('sr-only') || alvo.closest('.sr-only') !== null) {
                return;
            }

            if (alvo.tagName === 'A' && getComputedStyle(alvo).display === 'inline') {
                return;
            }

            if (caixa.height < 44 || caixa.width < 44) {
                const texto = (alvo.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 40);

                problemas.push(`${alvo.tagName} "${texto}" ${Math.round(caixa.width)}x${Math.round(caixa.height)}`);
            }
        });

        return problemas;
    });
}

test('as telas do participante cabem numa tela de 320 px e tem alvos de toque grandes', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 720 });

    const inscricao = await inscreverPessoa(page, noCelular, 'Vôlei');

    await page.goto(inscricao.urlDoAcompanhamento);
    await expect(page.getByTestId('linha-do-tempo')).toBeVisible();
    expect(await rolagemHorizontal(page), 'o acompanhamento escapa da largura da tela').toBeLessThanOrEqual(0);
    expect(await alvosPequenos(page)).toEqual([]);

    await page.goto(`/acesso?evento=${EVENTO_DEMO.slug}`);
    await expect(page.getByTestId('campo-email')).toBeVisible();
    expect(await rolagemHorizontal(page), 'o pedido de acesso escapa da largura da tela').toBeLessThanOrEqual(0);
    expect(await alvosPequenos(page)).toEqual([]);
});

test('da para percorrer as telas do participante so com o teclado, com o foco sempre visivel', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, noTeclado, 'Vôlei');

    await page.goto(inscricao.urlDoAcompanhamento);

    const semAnelDeFoco: string[] = [];
    const visitados = new Set<string>();

    for (let passo = 0; passo < 20; passo++) {
        await page.keyboard.press('Tab');

        const atual = await page.evaluate(() => {
            const elemento = document.activeElement as HTMLElement | null;

            if (elemento === null || elemento === document.body) {
                return null;
            }

            const estilo = getComputedStyle(elemento);

            return {
                identidade: `${elemento.tagName}.${(elemento.textContent ?? '').trim().slice(0, 24)}`,
                anel: estilo.outlineStyle !== 'none' || estilo.boxShadow !== 'none',
            };
        });

        if (atual === null) {
            continue;
        }

        visitados.add(atual.identidade);

        if (!atual.anel) {
            semAnelDeFoco.push(atual.identidade);
        }
    }

    expect(visitados.size, 'o foco ficou preso em algum lugar').toBeGreaterThan(3);
    expect(semAnelDeFoco, 'estes elementos recebem o foco sem mostrar').toEqual([]);

    // O formulario de acesso vai do campo ao envio sem tocar na tela: o rotulo
    // esta ligado ao campo, e o Enter envia.
    await page.goto('/acesso');

    const campo = page.getByLabel('E-mail da inscrição');
    await campo.focus();
    await expect(campo).toBeFocused();

    await page.keyboard.type(noTeclado.email);
    await page.keyboard.press('Enter');

    const mensagem = page.getByTestId('mensagem-do-acesso');
    await expect(mensagem).toBeVisible();

    // A resposta e anunciada sem interromper quem estiver lendo.
    await expect(mensagem).toHaveAttribute('role', 'status');
});

test('a linha do tempo se le sem depender da cor', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, semCores, 'Vôlei');

    await page.goto(inscricao.urlDoAcompanhamento);

    const marcos = page.getByTestId('linha-do-tempo').locator('li');
    const total = await marcos.count();

    expect(total).toBeGreaterThan(0);

    for (let indice = 0; indice < total; indice++) {
        const marco = marcos.nth(indice);

        // Cada passo diz por escrito em que pe esta...
        await expect(marco).toContainText(/Concluído|Agora|A seguir|Encerrado/);

        // ...e o desenho ao lado e so enfeite para o leitor de tela.
        await expect(marco.locator('svg[aria-hidden="true"]').first()).toBeAttached();
    }

    // Os titulos da pagina descem um degrau de cada vez: h1, h2 e so entao os
    // h3 de cada passo.
    const niveis = await page.evaluate(() =>
        [...document.querySelectorAll('main h1, main h2, main h3')].map((titulo) => Number(titulo.tagName.slice(1))),
    );

    expect(niveis[0]).toBe(1);

    niveis.forEach((nivel, indice) => {
        if (indice > 0) {
            expect(nivel - niveis[indice - 1], `o titulo ${indice + 1} pula um nivel`).toBeLessThanOrEqual(1);
        }
    });
});
