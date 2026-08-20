import { EVENTO_DEMO } from './ambiente';
import { definirCapacidadeDaAtividade, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * Atividade sem vaga.
 *
 * A "Trilha leve" fica com capacidade zero — que e o mesmo que nao ter mais
 * vaga — so durante este cenario. No fim, a capacidade original volta, para
 * que nenhum outro cenario herde este estado.
 */
const ATIVIDADE = 'Trilha leve';
const CAPACIDADE_ORIGINAL = 60;

const pessoa: PessoaDeTeste = {
    nome: 'Rita de Cássia Moraes',
    email: 'rita.moraes@example.com',
    telefone: '(11) 94444-5555',
    cpf: '77788899941',
    nascimento: '1983-06-12',
};

test.beforeAll(() => definirCapacidadeDaAtividade(ATIVIDADE, 0));
test.afterAll(() => definirCapacidadeDaAtividade(ATIVIDADE, CAPACIDADE_ORIGINAL));

test('atividade sem vaga aparece como esgotada e nao pode ser escolhida', async ({ page }) => {
    // Ja na vitrine do evento a falta de vaga esta escrita.
    await page.goto(`/eventos/${EVENTO_DEMO.slug}`);
    await expect(page.getByText(ATIVIDADE).first()).toBeVisible();

    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    const blocoDaTrilha = page.getByRole('group', { name: /Trilha/ });
    await expect(blocoDaTrilha).toBeVisible();

    const cartao = page.locator('label').filter({ hasText: ATIVIDADE }).first();
    const caixa = cartao.locator('input[type="checkbox"]');

    // A palavra aparece para quem enxerga...
    await expect(cartao.getByText('Esgotado').first()).toBeVisible();
    // ...e a caixa esta realmente desabilitada para quem usa teclado ou leitor.
    await expect(caixa).toBeDisabled();

    // Tocar no cartao inteiro, como se faz no celular, nao seleciona nada.
    // Playwright recusaria o clique num controle desabilitado; forcamos o toque
    // justamente para provar que, ainda assim, nada e selecionado.
    await cartao.click({ force: true });
    await expect(caixa).not.toBeChecked();
    await expect(blocoDaTrilha.getByRole('status')).toHaveText('0 de 1 selecionadas');

    // A outra trilha, com vaga, continua disponivel — o bloqueio e so da que acabou.
    const outra = page.locator('label').filter({ hasText: 'Trilha longa' }).first();
    await expect(outra.locator('input[type="checkbox"]')).toBeEnabled();
});
