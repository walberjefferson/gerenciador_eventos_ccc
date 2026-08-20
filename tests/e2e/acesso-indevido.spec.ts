import { EVENTO_DEMO } from './ambiente';
import { codigoDaInscricao, escolherAtividade, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O codigo da inscricao nao e senha.
 *
 * A tela da cobranca so abre com o link assinado que o sistema entregou. Sem a
 * assinatura, ou com ela adulterada, a porta fica fechada — mesmo para o
 * codigo de uma inscricao que existe de verdade.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Sebastião Ramos Pinto',
    email: 'sebastiao.pinto@example.com',
    telefone: '(11) 91111-8888',
    cpf: '88899900078',
    nascimento: '1978-03-09',
};

test('a tela da cobranca recusa quem chega sem a assinatura do link', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await escolherAtividade(page, 'Basquete');
    await page.getByRole('button', { name: 'Continuar' }).click();

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);

    const enderecoAssinado = page.url();
    const codigo = codigoDaInscricao(enderecoAssinado);

    // Com a assinatura, a cobranca abre normalmente.
    await expect(page.getByTestId('cobranca-aguardando')).toBeVisible();

    // Sem a assinatura, o mesmo endereco e recusado.
    const semAssinatura = await page.request.get(`/inscricoes/${codigo}/pagamento`);
    expect(semAssinatura.status()).toBe(403);

    // Com a assinatura adulterada, tambem.
    const adulterado = new URL(enderecoAssinado);
    adulterado.searchParams.set('signature', 'a'.repeat(64));
    const assinaturaFalsa = await page.request.get(adulterado.toString());
    expect(assinaturaFalsa.status()).toBe(403);

    // E a consulta de situacao, que a tela usa para saber do pagamento, e
    // protegida do mesmo jeito.
    const situacaoSemAssinatura = await page.request.get(`/inscricoes/${codigo}/situacao`);
    expect(situacaoSemAssinatura.status()).toBe(403);

    // Quem digita o endereco no navegador ve a recusa, nao a cobranca.
    const resposta = await page.goto(`/inscricoes/${codigo}/pagamento`);
    expect(resposta?.status()).toBe(403);
    await expect(page.getByTestId('cobranca-aguardando')).toHaveCount(0);
});
