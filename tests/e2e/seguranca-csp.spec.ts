import type { Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * "A politica de seguranca nao pode quebrar a inscricao."
 *
 * A Content-Security-Policy e a regra que diz ao navegador de onde ele pode
 * carregar cada coisa. Ela e a defesa contra script injetado — e e tambem o
 * tipo de mudanca que passa despercebida em desenvolvimento e quebra a tela em
 * producao, porque quem desenvolve raramente olha o console do navegador.
 *
 * Este cenario existe justamente para isso: percorre o caminho publico inteiro
 * com a politica ligada, do jeito que ela vai para o ar, e falha se o navegador
 * bloquear qualquer coisa. Os dois pontos mais delicados sao o QR Code do Pix,
 * que chega pronto do servidor e e desenhado dentro da propria pagina, e a
 * tabela de rotas que o sistema escreve na pagina — sem ela, nenhum link
 * funciona.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Perpetua Sales de Andrade',
    email: 'perpetua.andrade@example.com',
    telefone: '(21) 97878-1212',
    cpf: '60670780820',
    nascimento: '1978-11-03',
};

/**
 * Liga a escuta das violacoes antes de a pagina existir.
 *
 * O navegador anuncia cada bloqueio da politica num evento proprio
 * (`securitypolicyviolation`). Guardamos a lista na propria pagina; qualquer
 * item nela e uma tela quebrada em producao.
 */
async function escutarViolacoes(page: Page): Promise<string[]> {
    const violacoes: string[] = [];

    await page.addInitScript(() => {
        (window as unknown as { __violacoesCsp: string[] }).__violacoesCsp = [];

        document.addEventListener('securitypolicyviolation', (evento) => {
            (window as unknown as { __violacoesCsp: string[] }).__violacoesCsp.push(
                `${evento.violatedDirective} bloqueou ${evento.blockedURI || '(conteudo na propria pagina)'}`,
            );
        });
    });

    // O console tambem registra o bloqueio, e as vezes com mais detalhe.
    page.on('console', (mensagem) => {
        const texto = mensagem.text();

        if (texto.includes('Content Security Policy') || texto.includes('Refused to')) {
            violacoes.push(texto);
        }
    });

    return violacoes;
}

/** As violacoes que a propria pagina registrou, somadas as do console. */
async function violacoesDaPagina(page: Page, doConsole: string[]): Promise<string[]> {
    const daPagina = await page.evaluate(() => (window as unknown as { __violacoesCsp?: string[] }).__violacoesCsp ?? []);

    return [...daPagina, ...doConsole];
}

test('a politica de seguranca vai em toda pagina publica, sem liberar script escrito na pagina', async ({ page }) => {
    const resposta = await page.goto(`/eventos/${EVENTO_DEMO.slug}`);

    const politica = resposta?.headers()['content-security-policy'] ?? '';

    expect(politica, 'a pagina publica precisa sair com a politica de seguranca').not.toBe('');

    const script = politica
        .split(';')
        .map((pedaco) => pedaco.trim())
        .find((pedaco) => pedaco.startsWith('script-src'));

    // O ponto de toda a defesa: script escrito na pagina so roda com o numero
    // de uso unico sorteado na resposta. Se um dia isto virar 'unsafe-inline',
    // a politica deixa de proteger contra script injetado.
    expect(script).toContain("'nonce-");
    expect(script).not.toContain("'unsafe-inline'");

    expect(resposta?.headers()['x-content-type-options']).toBe('nosniff');
    expect(resposta?.headers()['x-frame-options']).toBe('DENY');
    expect(resposta?.headers()['referrer-policy']).toBe('strict-origin-when-cross-origin');
});

test('a pagina do evento e o formulario funcionam inteiros com a politica ligada', async ({ page }) => {
    const doConsole = await escutarViolacoes(page);

    await page.goto(`/eventos/${EVENTO_DEMO.slug}`);

    await expect(page.getByRole('heading', { name: EVENTO_DEMO.nome, level: 1 })).toBeVisible();

    // O botao so leva ao formulario se a tabela de rotas tiver sido aceita pelo
    // navegador: e ela que o sistema escreve na propria pagina.
    await page
        .getByRole('link', { name: /Fazer inscrição/ })
        .first()
        .click();
    await page.waitForURL(/\/inscricao$/);

    await expect(page.getByLabel('Nome completo')).toBeVisible();

    // A lista de setores e um painel desenhado pela interface, nao o `<select>`
    // do sistema: se a politica tivesse barrado o JavaScript, ela nem abriria.
    await page.getByLabel('Setor', { exact: true }).click();
    await expect(page.getByRole('option', { name: 'Setor Batalha', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');

    expect(await violacoesDaPagina(page, doConsole)).toEqual([]);
});

test('a tela de pagamento mostra o QR Code do Pix com a politica ligada', async ({ page }) => {
    const doConsole = await escutarViolacoes(page);

    await inscreverPessoa(page, pessoa, 'Futebol');

    // O QR Code chega pronto do servidor e e desenhado dentro da pagina. Era
    // este o risco: politica mal calibrada deixaria a pessoa diante de um
    // retangulo vazio na hora de pagar.
    const qrCode = page.getByTestId('qr-code-pix');

    await expect(qrCode).toBeVisible();
    await expect(qrCode.locator('svg')).toHaveAttribute('role', 'img');

    const desenhado = await qrCode.evaluate((elemento) => {
        const caixa = elemento.getBoundingClientRect();

        return { largura: caixa.width, altura: caixa.height };
    });

    expect(desenhado.largura).toBeGreaterThan(80);
    expect(desenhado.altura).toBeGreaterThan(80);

    await expect(page.getByTestId('codigo-copia-e-cola')).toBeVisible();

    expect(await violacoesDaPagina(page, doConsole)).toEqual([]);
});

test('a recuperacao de acesso continua respondendo com a politica ligada', async ({ page }) => {
    const doConsole = await escutarViolacoes(page);

    await page.goto(`/acesso?evento=${EVENTO_DEMO.slug}`);

    await page.getByLabel('E-mail da inscrição').fill(pessoa.email);
    await page.getByTestId('botao-enviar-acesso').click();

    // A mesma resposta neutra de sempre (D-48): o que se prova aqui e que ela
    // continua chegando na tela com a politica ligada.
    await expect(page.getByTestId('mensagem-do-acesso')).toContainText('enviamos o link de acesso');

    expect(await violacoesDaPagina(page, doConsole)).toEqual([]);
});
