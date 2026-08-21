import { inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * "Perdi o Pix de vista."
 *
 * Enquanto o prazo nao venceu, o participante pede a cobranca de novo pela
 * propria pagina e volta para a tela de pagamento com o QR Code na mao. Nada
 * e criado duas vezes: a Action que emite a cobranca e idempotente, entao o
 * historico continua com uma cobranca so.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Waldemar Pinheiro Costa',
    email: 'waldemar.costa@example.com',
    telefone: '(11) 96543-4040',
    cpf: '96325874137',
    nascimento: '1969-02-14',
};

test('a segunda via devolve a tela de pagamento com o QR Code, sem criar outra cobranca', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, pessoa, 'Futebol');

    await page.goto(inscricao.urlDoAcompanhamento);

    await expect(page.getByTestId('historico-da-cobranca').locator('li')).toHaveCount(1);

    await page.getByTestId('botao-segunda-via').click();

    // O servidor responde com o endereco assinado da cobranca.
    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);

    await expect(page.getByTestId('cobranca-aguardando')).toBeVisible();
    await expect(page.getByTestId('qr-code-pix')).toBeVisible();
    await expect(page.getByTestId('codigo-copia-e-cola')).toBeVisible();
    await expect(page.getByTestId('botao-copiar-pix')).toBeVisible();

    // E a mesma inscricao de antes, nao uma nova.
    expect(page.url()).toContain(`/inscricoes/${inscricao.codigo}/pagamento`);

    // De volta ao acompanhamento: continua havendo uma unica cobranca.
    await page.goto(inscricao.urlDoAcompanhamento);
    await expect(page.getByTestId('historico-da-cobranca').locator('li')).toHaveCount(1);
    await expect(page.getByTestId('erro-da-segunda-via')).toHaveCount(0);
});
