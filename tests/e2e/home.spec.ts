import type { Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A porta da rua, no navegador.
 *
 * Quem chega na raiz do dominio veio de um link no WhatsApp e esta no celular.
 * Estes cenarios percorrem o que essa pessoa faz: entender qual e o evento,
 * chegar ao formulario, descobrir que nao ha inscricao aberta agora, ou voltar
 * para a inscricao que ja fez.
 */

/** Troca a situacao do evento de demonstracao direto no banco. */
function definirSituacaoDoEvento(slug: string, situacao: string): void {
    artisan(['tinker', '--execute', `\\App\\Models\\Evento::query()->where('slug', '${slug}')->update(['situacao' => '${situacao}']);`]);
}

/** A largura util da pagina nao pode passar da largura da janela. */
async function rolagemHorizontal(page: Page): Promise<number> {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

/**
 * A razao de contraste entre o texto de um elemento e o fundo atras dele,
 * pela formula da WCAG. Abaixo de 4,5 o texto comum reprova.
 */
async function contraste(page: Page, seletor: string): Promise<number> {
    return page.evaluate((alvo) => {
        const elemento = document.querySelector<HTMLElement>(alvo);

        if (elemento === null) {
            return 0;
        }

        const canais = (cor: string): number[] => (cor.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);

        const luminancia = (cor: string): number => {
            const [r, g, b] = canais(cor).map((canal) => {
                const parte = canal / 255;

                return parte <= 0.03928 ? parte / 12.92 : ((parte + 0.055) / 1.055) ** 2.4;
            });

            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };

        /** O fundo pintado mais proximo: cor transparente herda de quem esta atras. */
        const fundo = (de: HTMLElement): string => {
            let atual: HTMLElement | null = de;

            while (atual !== null) {
                const cor = getComputedStyle(atual).backgroundColor;
                const alfa = canais(cor).length === 3 ? (cor.match(/[\d.]+/g) ?? [])[3] : undefined;

                if (cor !== 'rgba(0, 0, 0, 0)' && alfa !== '0') {
                    return cor;
                }

                atual = atual.parentElement;
            }

            return 'rgb(255, 255, 255)';
        };

        const claro = Math.max(luminancia(getComputedStyle(elemento).color), luminancia(fundo(elemento)));
        const escuro = Math.min(luminancia(getComputedStyle(elemento).color), luminancia(fundo(elemento)));

        return (claro + 0.05) / (escuro + 0.05);
    }, seletor);
}

test('a home apresenta o evento aberto e o botao leva a vitrine', async ({ page }) => {
    // O publico se inscreve pelo celular, e ha aparelho de 360 px em uso.
    await page.setViewportSize({ width: 360, height: 740 });

    await page.goto('/');

    // Qual e o evento e quando ele acontece, sem precisar rolar.
    await expect(page.getByRole('heading', { name: EVENTO_DEMO.nome, level: 1 })).toBeVisible();
    await expect(page.getByText('Inscrições abertas').first()).toBeVisible();

    // Um h1 so, e a pagina cabe na largura do aparelho.
    expect(await page.getByRole('heading', { level: 1 }).count()).toBe(1);
    expect(await rolagemHorizontal(page), 'a home escapa da largura da tela').toBeLessThanOrEqual(0);

    // A moldura semantica que o leitor de tela usa para se localizar.
    await expect(page.locator('main#conteudo')).toBeVisible();
    await expect(page.locator('header').first()).toBeVisible();

    const botao = page.getByTestId('botao-fazer-inscricao');

    // O botao principal e alcancavel pelo teclado, mostra o foco e tem contraste.
    await botao.focus();
    await expect(botao).toBeFocused();

    const anelDeFoco = await botao.evaluate((elemento) => {
        const estilo = getComputedStyle(elemento);

        return estilo.outlineStyle !== 'none' || estilo.boxShadow !== 'none';
    });

    expect(anelDeFoco, 'o botao principal recebe o foco sem mostrar').toBe(true);
    expect(await contraste(page, '[data-testid="botao-fazer-inscricao"]')).toBeGreaterThanOrEqual(4.5);

    // Alvo de dedo: nada menor que 44 px de altura.
    const caixa = await botao.boundingBox();
    expect(caixa?.height ?? 0).toBeGreaterThanOrEqual(44);

    await botao.click();

    await page.waitForURL(new RegExp(`/eventos/${EVENTO_DEMO.slug}$`));
    await expect(page.getByRole('heading', { name: EVENTO_DEMO.nome, level: 1 })).toBeVisible();
    await expect(page.getByText(EVENTO_DEMO.valor).first()).toBeVisible();
});

test('da home ate o formulario de inscricao em dois cliques', async ({ page }) => {
    await page.goto('/');

    await page.getByTestId('botao-fazer-inscricao').click();
    await page
        .getByRole('link', { name: 'Quero me inscrever' })
        .first()
        .click();

    await page.waitForURL(/\/inscricao$/);
    await expect(page.getByRole('heading', { name: 'Seus dados' })).toBeVisible();
    await expect(page.getByLabel('Nome completo')).toBeVisible();
});

test('sem evento aberto, a home avisa e nao oferece botao de inscricao', async ({ page }) => {
    definirSituacaoDoEvento(EVENTO_DEMO.slug, 'inscricoes_encerradas');

    try {
        await page.goto('/');

        await expect(page.getByTestId('aviso-sem-inscricoes')).toContainText('No momento não há inscrições abertas');

        // Nenhum caminho para se inscrever: nem o botao principal, nem link
        // algum para a vitrine ou para o formulario.
        await expect(page.getByTestId('botao-fazer-inscricao')).toHaveCount(0);
        await expect(page.locator('a[href*="/eventos/"]')).toHaveCount(0);

        // O caminho de volta de quem ja se inscreveu continua valendo (DA-36).
        await expect(page.getByTestId('link-ja-fiz-minha-inscricao')).toBeVisible();
    } finally {
        // O proximo cenario encontra o banco como o encontrou este.
        definirSituacaoDoEvento(EVENTO_DEMO.slug, 'inscricoes_abertas');
    }
});

test('o link de quem ja se inscreveu chega na recuperacao de acesso', async ({ page }) => {
    await page.goto('/');

    await page.getByTestId('link-ja-fiz-minha-inscricao').click();

    await page.waitForURL(/\/acesso/);
    await expect(page.getByLabel('E-mail da inscrição')).toBeVisible();
});
