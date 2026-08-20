import { EVENTO_DEMO } from './ambiente';
import { escolherAtividade, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O limite de escolhas do bloco.
 *
 * O bloco de modalidades aceita de 1 a 2. Quando a segunda entra, o contador
 * diz "2 de 2 selecionadas" e o resto para de responder — com a frase que
 * ensina a saida: desmarcar uma para trocar.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Luzia Aparecida Nunes',
    email: 'luzia.nunes@example.com',
    telefone: '(11) 92222-7777',
    cpf: '55566677720',
    nascimento: '1995-08-25',
};

test('atingido o maximo, o contador avisa e as demais opcoes travam ate liberar uma', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    const bloco = page.getByRole('group', { name: /Modalidades esportivas/ });
    await expect(bloco).toBeVisible();

    // A regra esta escrita antes de qualquer tentativa.
    await expect(bloco).toContainText('Escolha de 1 a 2');
    await expect(bloco.getByRole('status')).toHaveText('0 de 2 selecionadas');

    const basquete = page.locator('label').filter({ hasText: 'Basquete' }).first();
    const caixaDoBasquete = basquete.locator('input[type="checkbox"]');

    // Futebol (08h-10h) e Handebol (10h-12h) apenas se encostam: cabem juntos.
    await escolherAtividade(page, 'Futebol');
    await expect(bloco.getByRole('status')).toHaveText('1 de 2 selecionada');

    await escolherAtividade(page, 'Handebol');
    await expect(bloco.getByRole('status')).toHaveText('2 de 2 selecionadas');

    // O Basquete nao tem conflito de horario nenhum: o que trava e o limite.
    await expect(caixaDoBasquete).toBeDisabled();
    await expect(basquete).toContainText('Desmarque uma para trocar');

    await basquete.click({ force: true });
    await expect(caixaDoBasquete).not.toBeChecked();

    // Liberada uma vaga do bloco, o Basquete volta a aceitar a escolha.
    await escolherAtividade(page, 'Handebol');
    await expect(bloco.getByRole('status')).toHaveText('1 de 2 selecionada');
    await expect(caixaDoBasquete).toBeEnabled();

    await escolherAtividade(page, 'Basquete');
    await expect(caixaDoBasquete).toBeChecked();
    await expect(bloco.getByRole('status')).toHaveText('2 de 2 selecionadas');
});
