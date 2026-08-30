import type { Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { escolherAtividade, escolherNaLista, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * A varredura que nenhuma tela pode reprovar.
 *
 * Sao quatro exigencias que valem para o site publico inteiro: dar para
 * percorrer no teclado com o foco sempre visivel, avisar o leitor de tela
 * quando a etapa muda, ter alvo de toque grande o bastante para o dedo, e
 * caber numa tela de 320 px sem obrigar ninguem a arrastar a pagina para o
 * lado.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Benedita Oliveira Rocha',
    email: 'benedita.rocha@example.com',
    telefone: '(11) 90000-1212',
    cpf: '32165498791',
    nascimento: '1970-05-18',
};

/** A largura util da pagina nao pode passar da largura da janela. */
async function rolagemHorizontal(page: Page): Promise<number> {
    return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

/**
 * Alvos de toque menores que 44x44.
 *
 * Botoes e cartoes de escolha entram na conta. Link escrito no meio de uma
 * frase fica de fora: aumenta-lo quebraria o proprio paragrafo, e a WCAG abre
 * essa excecao justamente para ele.
 */
async function alvosPequenos(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const problemas: string[] = [];
        const alvos = document.querySelectorAll<HTMLElement>('button, a[href], label:has(input[type="checkbox"]), [role="button"]');

        /**
         * A area que responde ao dedo. Numa caixa de marcar, o rotulo ao lado
         * marca junto: os dois formam um alvo so, e e esse conjunto que precisa
         * caber no dedo — nao o quadradinho sozinho.
         */
        const areaDoAlvo = (alvo: HTMLElement): DOMRect => {
            const propria = alvo.getBoundingClientRect();
            const rotulo = alvo.id !== '' ? document.querySelector<HTMLElement>(`label[for="${alvo.id}"]`) : null;

            if (rotulo === null) {
                return propria;
            }

            const dele = rotulo.getBoundingClientRect();
            const esquerda = Math.min(propria.left, dele.left);
            const topo = Math.min(propria.top, dele.top);

            return new DOMRect(esquerda, topo, Math.max(propria.right, dele.right) - esquerda, Math.max(propria.bottom, dele.bottom) - topo);
        };

        alvos.forEach((alvo) => {
            const caixa = areaDoAlvo(alvo);

            if (caixa.width === 0 || caixa.height === 0) {
                return;
            }

            // Atalho escondido para leitor de tela (o "Ir direto para o
            // conteudo"): ocupa 1x1 ate receber o foco, e nao e alvo de dedo.
            if (alvo.classList.contains('sr-only') || alvo.closest('.sr-only') !== null) {
                return;
            }

            // Link dentro de um texto corrido: excecao de elemento em linha.
            const emLinha = alvo.tagName === 'A' && getComputedStyle(alvo).display === 'inline';

            if (emLinha) {
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

test('a vitrine e o formulario cabem numa tela de 320 px e tem alvos de toque grandes', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 720 });

    await page.goto(`/eventos/${EVENTO_DEMO.slug}`);
    expect(await rolagemHorizontal(page), 'a vitrine escapa da largura da tela').toBeLessThanOrEqual(0);
    expect(await alvosPequenos(page)).toEqual([]);

    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);
    expect(await rolagemHorizontal(page), 'o passo 1 escapa da largura da tela').toBeLessThanOrEqual(0);
    expect(await alvosPequenos(page)).toEqual([]);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await expect(page.getByRole('group', { name: /Modalidades esportivas/ })).toBeVisible();
    expect(await rolagemHorizontal(page), 'o passo 2 escapa da largura da tela').toBeLessThanOrEqual(0);
    expect(await alvosPequenos(page)).toEqual([]);

    await escolherAtividade(page, 'Futebol');
    await page.getByRole('button', { name: 'Continuar' }).click();

    await expect(page.getByLabel(/Li e aceito o regulamento/)).toBeVisible();
    expect(await rolagemHorizontal(page), 'o passo 3 escapa da largura da tela').toBeLessThanOrEqual(0);
    expect(await alvosPequenos(page)).toEqual([]);

    // A tela da cobranca e a que sera usada de pe, com o banco aberto ao lado:
    // e a que menos pode escapar da largura do aparelho.
    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);
    await expect(page.getByTestId('qr-code-pix')).toBeVisible();
    expect(await rolagemHorizontal(page), 'a tela da cobranca escapa da largura da tela').toBeLessThanOrEqual(0);
    expect(await alvosPequenos(page)).toEqual([]);
});

test('da para percorrer o formulario so com o teclado, e o foco fica sempre visivel', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    // Uma volta de Tab pela pagina: nada engole o foco, e todo elemento que o
    // recebe mostra que o recebeu.
    const visitados = new Set<string>();
    let semAnelDeFoco: string[] = [];

    for (let passo = 0; passo < 25; passo++) {
        await page.keyboard.press('Tab');

        const atual = await page.evaluate(() => {
            const elemento = document.activeElement as HTMLElement | null;

            if (elemento === null || elemento === document.body) {
                return null;
            }

            const estilo = getComputedStyle(elemento);
            const anel = estilo.outlineStyle !== 'none' || estilo.boxShadow !== 'none';
            const identidade = elemento.id !== '' ? `#${elemento.id}` : `${elemento.tagName}.${(elemento.textContent ?? '').trim().slice(0, 20)}`;

            return { identidade, anel };
        });

        if (atual === null) {
            continue;
        }

        visitados.add(atual.identidade);

        if (!atual.anel) {
            semAnelDeFoco.push(atual.identidade);
        }
    }

    // Os campos do passo 1 sao alcancados pelo teclado.
    for (const campo of ['#nome_completo', '#email', '#telefone', '#documento', '#data_nascimento', '#cidade_id']) {
        expect(visitados, `o teclado nunca chegou em ${campo}`).toContain(campo);
    }

    // O primeiro elemento da pagina e o atalho para pular o cabecalho.
    expect([...visitados][0]).toContain('Ir direto para o con');

    // A lista de grupos so entra na ordem de tabulacao depois que ha setor
    // escolhido — antes disso ela esta vazia, e um campo vazio que recebe foco
    // e so um obstaculo a mais.
    await expect(page.locator('#grupo_participante_id')).toBeDisabled();
    // O campo continua se chamando `cidade_id`; so o rotulo virou "Setor".
    await escolherNaLista(page, 'Setor', 'Setor Batalha');
    await page.locator('#grupo_participante_id').focus();
    await expect(page.locator('#grupo_participante_id')).toBeFocused();

    // Nenhuma armadilha: o foco andou por varios elementos, nao ficou preso.
    expect(visitados.size).toBeGreaterThan(6);

    semAnelDeFoco = semAnelDeFoco.filter((identidade) => identidade !== '');
    expect(semAnelDeFoco, 'estes elementos recebem o foco sem mostrar').toEqual([]);
});

test('a troca de etapa e anunciada para quem usa leitor de tela', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    const anuncio = page.locator('[aria-live="polite"][role="status"].sr-only');

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await expect(anuncio).toHaveText('Etapa Sua participação.');
    // E o foco vai para o titulo da etapa nova, para o leitor comecar dali.
    await expect(page.getByRole('heading', { name: 'Sua participação', exact: true })).toBeFocused();

    await escolherAtividade(page, 'Futebol');
    await page.getByRole('button', { name: 'Continuar' }).click();

    await expect(anuncio).toHaveText('Etapa Revisão.');

    await page.getByRole('button', { name: 'Voltar' }).click();
    await expect(anuncio).toHaveText('Etapa Sua participação.');
});

test('a atividade pode ser marcada e desmarcada so com o teclado', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    const futebol = page.locator('label').filter({ hasText: 'Futebol' }).first().locator('input[type="checkbox"]');

    await futebol.focus();
    await expect(futebol).toBeFocused();

    await page.keyboard.press('Space');
    await expect(futebol).toBeChecked();

    await page.keyboard.press('Space');
    await expect(futebol).not.toBeChecked();
});
