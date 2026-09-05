import { idExternoDaCobranca, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O ingresso na pagina do participante.
 *
 * Duas historias da mesma tela: quem pagou ve o QR, o codigo e baixa o papel;
 * quem ainda deve nao ve nada disso — nem na tela, nem baixando o arquivo por
 * fora. O dinheiro e simulado, mas o caminho e o de verdade: o provedor
 * simulado emite o mesmo aviso assinado que uma instituicao financeira
 * emitiria, e so o dominio confirma a inscricao.
 *
 * CPFs proprios deste arquivo: o dominio recusa duas inscricoes ativas com o
 * mesmo documento no mesmo evento, e dois cenarios com o mesmo numero se
 * matariam.
 */
const pagante: PessoaDeTeste = {
    nome: 'Rosangela Nunes Aparicio',
    email: 'rosangela.aparicio@example.com',
    telefone: '(11) 96543-4040',
    cpf: '33144287075',
    nascimento: '1979-02-11',
};

const devendo: PessoaDeTeste = {
    nome: 'Anselmo Ribeiro Caldas',
    email: 'anselmo.caldas@example.com',
    telefone: '(11) 96543-5050',
    cpf: '47281935005',
    nascimento: '1986-10-24',
};

test('quem pagou ve o QR, o codigo e baixa o ingresso em PDF', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, pagante, 'Vôlei');

    // O provedor simulado recebe o pagamento e avisa o sistema pelo webhook
    // assinado, exatamente como faria um banco de verdade.
    const idExterno = idExternoDaCobranca(inscricao.codigo);
    const aviso = await page.request.post(`/dev/pagamentos/${idExterno}/pagar`);
    expect(aviso.ok(), await aviso.text()).toBeTruthy();

    await page.goto(inscricao.urlDoAcompanhamento);

    const ingresso = page.getByTestId('ingresso-da-inscricao');

    await expect(ingresso).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Seu ingresso' })).toBeVisible();

    // O desenho chega pronto do servidor: um <svg> de verdade dentro do HTML,
    // e nao uma imagem que o navegador teria de ir buscar.
    const qr = page.getByTestId('qr-code-ingresso');
    await expect(qr).toBeVisible();
    await expect(qr.locator('svg')).toHaveCount(1);
    await expect(qr.locator('svg')).toHaveAttribute('aria-label', 'QR Code do ingresso');

    // O codigo escrito por extenso, em grupos de quatro. E o caminho de quem
    // esta com a camera suja na fila do portao.
    const codigo = await page.getByTestId('codigo-do-ingresso').innerText();
    expect(codigo.trim()).toMatch(/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/);

    // E ele nao e o codigo publico da inscricao, que ja circulou por e-mail.
    expect(codigo.replace(/-/g, '')).not.toBe(inscricao.codigo.toUpperCase());

    // O papel para imprimir: um PDF de verdade, entregue como anexo.
    const endereco = await page.getByTestId('botao-ingresso-pdf').getAttribute('href');
    expect(endereco, 'a tela precisa oferecer o link do PDF').toBeTruthy();
    expect(endereco).toContain('signature=');

    const pdf = await page.request.get(endereco as string);

    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect(pdf.headers()['content-disposition']).toContain('attachment');

    const bytes = await pdf.body();
    expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-');
    expect(bytes.length).toBeGreaterThan(2000);
});

test('quem ainda nao pagou nao ve ingresso nenhum, nem baixando por fora', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, devendo, 'Vôlei');

    await page.goto(inscricao.urlDoAcompanhamento);

    await expect(page.getByTestId('acao-de-pagamento')).toBeVisible();
    await expect(page.getByTestId('ingresso-da-inscricao')).toHaveCount(0);
    await expect(page.getByTestId('qr-code-ingresso')).toHaveCount(0);
    await expect(page.getByTestId('botao-ingresso-pdf')).toHaveCount(0);

    // Sem assinatura na URL, a porta responde 403 antes de qualquer regra.
    const semAssinatura = await page.request.get(`/inscricoes/${inscricao.codigo}/ingresso`);
    expect(semAssinatura.status()).toBe(403);
});
