import { EVENTO_DEMO } from './ambiente';
import { codigoDaInscricao, escolherAtividade, idExternoDaCobranca, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O pagamento chega enquanto a pessoa esta com a tela aberta.
 *
 * O dinheiro e simulado, mas o caminho e o de verdade: o provedor simulado
 * marca a cobranca como paga e emite o mesmo aviso assinado que uma
 * instituicao financeira emitiria. A tela nunca declara pagamento por conta
 * propria — ela apenas pergunta ao servidor e obedece a resposta.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Pedro Henrique Lima',
    email: 'pedro.lima@example.com',
    telefone: '(11) 97777-2222',
    cpf: '44455566619',
    nascimento: '1988-09-03',
};

test('a tela vira "inscrição confirmada" assim que o pagamento e reconhecido', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await escolherAtividade(page, 'Vôlei');
    await page.getByRole('button', { name: 'Continuar' }).click();

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);
    await expect(page.getByTestId('cobranca-aguardando')).toBeVisible();

    const enderecoDaCobranca = page.url();
    const codigo = codigoDaInscricao(enderecoDaCobranca);
    const idExterno = idExternoDaCobranca(codigo);

    // O provedor simulado recebe o pagamento e avisa o sistema pelo webhook
    // assinado, exatamente como faria um banco de verdade.
    const aviso = await page.request.post(`/dev/pagamentos/${idExterno}/pagar`);
    expect(aviso.ok(), await aviso.text()).toBeTruthy();

    // Nenhum F5: a propria tela percebe e troca de estado.
    await expect(page.getByTestId('cobranca-confirmada')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('heading', { name: 'Inscrição confirmada' })).toBeVisible();
    await expect(page.getByText('Recebemos seu pagamento')).toBeVisible();
    await expect(page.getByText('Confirmada').first()).toBeVisible();
    await expect(page.getByText(codigo).first()).toBeVisible();

    // O endereco continua o mesmo: a pagina nao foi recarregada.
    expect(page.url()).toBe(enderecoDaCobranca);

    // E nao ha mais como pagar de novo.
    await expect(page.getByTestId('qr-code-pix')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Copiar código Pix' })).toHaveCount(0);
});
