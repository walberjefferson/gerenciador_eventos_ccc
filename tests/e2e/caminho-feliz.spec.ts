import { EVENTO_DEMO } from './ambiente';
import { escolherAtividade, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O caminho que a maioria das pessoas vai percorrer: encontra o evento,
 * entende a programacao, se inscreve em quatro etapas e termina diante do QR
 * Code do Pix.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Ana Clara Souza',
    email: 'ana.clara@example.com',
    telefone: '(11) 98888-1111',
    cpf: '11122233396',
    nascimento: '1992-04-15',
};

test('da pagina do evento ate o QR Code do Pix', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}`);

    // A vitrine se apresenta: nome, valor e programacao.
    await expect(page.getByRole('heading', { name: EVENTO_DEMO.nome, level: 1 })).toBeVisible();
    await expect(page.getByText(EVENTO_DEMO.valor).first()).toBeVisible();
    // O titulo desta secao virou "Como funciona o fim de semana" na Etapa 27,
    // seguindo o prototipo: quem chega pela primeira vez entende melhor uma
    // frase do que a palavra "Programação" sozinha. A ancora da home continua
    // se chamando "titulo-programacao", e e ela que o link usa — o endereco nao
    // mudou, so o texto que a pessoa le.
    await expect(page.getByRole('heading', { name: 'Como funciona o fim de semana' })).toBeVisible();
    await expect(page.getByText('Futebol').first()).toBeVisible();

    // O botao da pagina do evento passou a se chamar "Fazer inscrição" na Etapa
    // 27, seguindo o prototipo — e e o mesmo texto que a porta da rua ja usava.
    // A mesma acao com dois nomes obriga quem chega a conferir se sao a mesma
    // coisa.
    await page.getByRole('link', { name: 'Fazer inscrição' }).first().click();

    // Passo 1 — dados pessoais.
    await expect(page.getByRole('heading', { name: 'Seus dados' })).toBeVisible();
    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    // Passo 2 — participacao. Uma modalidade ja basta para o bloco obrigatorio.
    await expect(page.getByRole('group', { name: /Modalidades esportivas/ })).toBeVisible();
    await escolherAtividade(page, 'Futebol');
    await page.getByRole('button', { name: 'Continuar' }).click();

    // Passo 3 — revisao: o resumo repete o que foi escolhido antes de gravar.
    await expect(page.getByText(pessoa.nome).first()).toBeVisible();
    await expect(page.getByText('Futebol').first()).toBeVisible();
    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    // Passo 4 — a cobranca, alcancada por um link assinado.
    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);
    expect(page.url()).toContain('signature=');

    await expect(page.getByRole('heading', { name: 'Pague com Pix para garantir sua vaga' })).toBeVisible();
    await expect(page.getByTestId('valor-da-cobranca')).toHaveText(EVENTO_DEMO.valor);

    // O QR Code chega desenhado do servidor, dentro do proprio HTML.
    const qrCode = page.getByTestId('qr-code-pix');
    await expect(qrCode).toBeVisible();
    await expect(qrCode.locator('svg')).toHaveAttribute('role', 'img');

    // O copia e cola e o contador acompanham o QR Code.
    await expect(page.getByTestId('codigo-copia-e-cola')).toHaveValue(/^000201/);
    await expect(page.getByTestId('contador-regressivo')).toContainText('para pagar');
    await expect(page.getByRole('button', { name: 'Copiar código Pix' })).toBeVisible();

    // Instrucoes para quem nunca pagou com Pix.
    await expect(page.getByText('Como pagar, passo a passo')).toBeVisible();
});
