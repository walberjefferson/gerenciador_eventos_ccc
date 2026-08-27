import { expect, test } from './base';

/**
 * Movimento reduzido.
 *
 * Quem tem sensibilidade vestibular pede ao sistema operacional para reduzir
 * animacao. O navegador repassa esse pedido em "prefers-reduced-motion", e
 * ate a Etapa 23 o sistema o ignorava: as 33 telas com animacao ou transicao
 * se moviam igual.
 *
 * O que se prova aqui: com o pedido ligado, nada se move de forma perceptivel
 * — e, com ele desligado, a animacao continua existindo, porque o objetivo
 * nunca foi remover movimento de todo mundo.
 */

/** A maior duracao de animacao ou transicao encontrada na pagina, em segundos. */
async function maiorDuracao(page: import('@playwright/test').Page): Promise<number> {
    return page.evaluate(() => {
        let maior = 0;

        for (const elemento of Array.from(document.querySelectorAll('*'))) {
            const estilo = getComputedStyle(elemento);

            for (const valor of [estilo.animationDuration, estilo.transitionDuration]) {
                for (const parte of valor.split(',')) {
                    const texto = parte.trim();
                    const numero = parseFloat(texto);

                    if (Number.isNaN(numero)) {
                        continue;
                    }

                    const segundos = texto.endsWith('ms') ? numero / 1000 : numero;
                    maior = Math.max(maior, segundos);
                }
            }
        }

        return maior;
    });
}

test.describe('com o pedido de reduzir movimento ligado', () => {
    // A emulacao e feita aqui, e nao por `test.use`: naquele caminho a opcao
    // nao chega ao contexto deste projeto, e o cenario passaria a medir a
    // pagina sem o pedido ligado — ou seja, provaria nada.
    test.beforeEach(async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
    });

    test('nada na pagina publica se move de forma perceptivel', async ({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('networkidle');

        // 0.01ms = 0.00001s. O teto de 0.05s da folga para arredondamento sem
        // deixar passar nenhuma animacao que a pessoa consiga perceber.
        expect(await maiorDuracao(page)).toBeLessThan(0.05);
    });

    test('a vitrine do evento tambem respeita o pedido', async ({ page }) => {
        await page.goto('/eventos/copa-ccc-2026');
        await page.waitForLoadState('networkidle');

        expect(await maiorDuracao(page)).toBeLessThan(0.05);
    });

    test('o formulario de inscricao continua navegavel sem movimento', async ({ page }) => {
        await page.goto('/eventos/copa-ccc-2026/inscricao');
        await page.waitForLoadState('networkidle');

        expect(await maiorDuracao(page)).toBeLessThan(0.05);

        // Sem movimento, mas ainda funcionando. Nao existe elemento <form> aqui:
        // as quatro etapas sao navegacao na propria tela (decisao D-36), entao o
        // que se confere e que os campos estao la e alcancaveis.
        await expect(page.locator('input').first()).toBeVisible();
    });
});

test.describe('sem o pedido', () => {
    test.beforeEach(async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
    });

    test('a animacao continua existindo para quem nao pediu para reduzi-la', async ({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('networkidle');

        // Se este teste falhar, o bloco de movimento reduzido esta valendo para
        // todo mundo — e ai o problema deixou de ser acessibilidade e passou a
        // ser um sistema sem nenhuma transicao.
        expect(await maiorDuracao(page)).toBeGreaterThan(0.05);
    });
});
