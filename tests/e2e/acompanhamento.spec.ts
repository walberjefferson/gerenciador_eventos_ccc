import { idExternoDaCobranca, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * A pagina do participante: o que ja aconteceu com a inscricao.
 *
 * Dois momentos da mesma historia — a inscricao esperando o pagamento e a
 * inscricao ja confirmada — mais a porta fechada para quem chega sem o link
 * assinado. O dinheiro e simulado, mas o caminho e o de verdade: o provedor
 * simulado emite o mesmo aviso assinado que uma instituicao financeira
 * emitiria, e so o dominio muda a situacao.
 */
const esperando: PessoaDeTeste = {
    nome: 'Marlene Souza Prado',
    email: 'marlene.prado@example.com',
    telefone: '(11) 96543-1010',
    cpf: '52998224725',
    nascimento: '1983-04-17',
};

const pagante: PessoaDeTeste = {
    nome: 'Osvaldo Teixeira Braga',
    email: 'osvaldo.braga@example.com',
    telefone: '(11) 96543-2020',
    cpf: '39053344705',
    nascimento: '1975-11-28',
};

const curioso: PessoaDeTeste = {
    nome: 'Célia Barbosa Freitas',
    email: 'celia.freitas@example.com',
    telefone: '(11) 96543-3030',
    cpf: '11144477735',
    nascimento: '1991-06-02',
};

test('a inscricao esperando pagamento mostra o prazo como o passo de agora', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, esperando, 'Handebol');

    await page.goto(inscricao.urlDoAcompanhamento);

    await expect(page.getByRole('heading', { name: 'Acompanhe sua inscrição' })).toBeVisible();
    await expect(page.getByTestId('resumo-da-inscricao')).toBeVisible();
    await expect(page.getByTestId('situacao-da-inscricao')).toHaveText('Aguardando pagamento');
    await expect(page.getByTestId('atividades-escolhidas')).toContainText('Handebol');
    await expect(page.getByText(inscricao.codigo).first()).toBeVisible();

    const linha = page.getByTestId('linha-do-tempo');

    // Lista ordenada de verdade: quem usa leitor de tela ouve "item 2 de 3".
    await expect(linha).toBeVisible();
    expect(await linha.evaluate((elemento) => elemento.tagName)).toBe('OL');

    await expect(linha.locator('[data-marco="inscricao_feita"]')).toHaveAttribute('data-estado', 'concluido');
    await expect(linha.locator('[data-marco="cobranca_emitida"]')).toHaveAttribute('data-estado', 'concluido');
    await expect(linha.locator('[data-marco="prazo_pagamento"]')).toHaveAttribute('data-estado', 'atual');

    // Um passo de cada vez: so um marco pode ser o de agora.
    await expect(linha.locator('[data-estado="atual"]')).toHaveCount(1);

    // E o estado esta escrito, nao apenas colorido.
    await expect(linha.locator('[data-marco="prazo_pagamento"]')).toContainText('Agora');
    await expect(linha.locator('[data-marco="inscricao_feita"]')).toContainText('Concluído');

    // Uma cobranca no historico e o caminho de volta ao Pix.
    await expect(page.getByTestId('historico-da-cobranca').locator('li')).toHaveCount(1);
    await expect(page.getByTestId('historico-da-cobranca')).toContainText('Aguardando pagamento');
    await expect(page.getByTestId('botao-ver-pix')).toBeVisible();
    await expect(page.getByTestId('aviso-confirmada')).toHaveCount(0);
});

test('depois do pagamento reconhecido, a linha do tempo conta a confirmacao', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, pagante, 'Handebol');

    // O provedor simulado recebe o pagamento e avisa o sistema pelo webhook
    // assinado, exatamente como faria um banco de verdade.
    const idExterno = idExternoDaCobranca(inscricao.codigo);
    const aviso = await page.request.post(`/dev/pagamentos/${idExterno}/pagar`);
    expect(aviso.ok(), await aviso.text()).toBeTruthy();

    // A pagina do participante e de leitura: abrir de novo basta.
    await page.goto(inscricao.urlDoAcompanhamento);

    await expect(page.getByTestId('situacao-da-inscricao')).toHaveText('Confirmada');

    const linha = page.getByTestId('linha-do-tempo');

    await expect(linha.locator('[data-marco="pagamento_recebido"]')).toHaveAttribute('data-estado', 'concluido');
    await expect(linha.locator('[data-marco="inscricao_confirmada"]')).toHaveAttribute('data-estado', 'concluido');
    await expect(linha).toContainText('Recebemos seu pagamento');

    // Inscricao confirmada nao tem proximo passo.
    await expect(linha.locator('[data-estado="atual"]')).toHaveCount(0);
    await expect(linha.locator('[data-marco="prazo_pagamento"]')).toHaveCount(0);

    await expect(page.getByTestId('aviso-confirmada')).toBeVisible();
    await expect(page.getByTestId('acao-de-pagamento')).toHaveCount(0);
    await expect(page.getByTestId('historico-da-cobranca')).toContainText('Pago');
});

test('o acompanhamento recusa quem chega sem a assinatura do link', async ({ page }) => {
    const inscricao = await inscreverPessoa(page, curioso, 'Handebol');

    // Com a assinatura, a pagina abre normalmente.
    await page.goto(inscricao.urlDoAcompanhamento);
    await expect(page.getByTestId('resumo-da-inscricao')).toBeVisible();

    // Sem a assinatura, o mesmo endereco e recusado: o codigo publico nunca
    // serve de senha.
    const semAssinatura = await page.request.get(`/inscricoes/${inscricao.codigo}/acompanhar`);
    expect(semAssinatura.status()).toBe(403);

    // Com a assinatura adulterada, tambem.
    const adulterado = new URL(inscricao.urlDoAcompanhamento);
    adulterado.searchParams.set('signature', 'a'.repeat(64));
    const assinaturaFalsa = await page.request.get(adulterado.toString());
    expect(assinaturaFalsa.status()).toBe(403);

    // Quem digita o endereco no navegador ve a recusa, nao a inscricao alheia.
    const resposta = await page.goto(`/inscricoes/${inscricao.codigo}/acompanhar`);
    expect(resposta?.status()).toBe(403);
    await expect(page.getByTestId('resumo-da-inscricao')).toHaveCount(0);
});
