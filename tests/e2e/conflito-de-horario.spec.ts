import { EVENTO_DEMO } from './ambiente';
import { escolherAtividade, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * Duas modalidades no mesmo horario.
 *
 * O Futebol vai das 08h as 10h e o Volei das 09h as 11h: uma hora em comum
 * basta para que nao possam ser escolhidos juntos. A tela nao se limita a
 * travar — ela diz o nome da modalidade que esta atrapalhando, para a pessoa
 * saber o que desmarcar.
 */
const pessoa: PessoaDeTeste = {
    nome: 'Antônio Ferreira Dias',
    email: 'antonio.dias@example.com',
    telefone: '(11) 93333-6666',
    cpf: '22233344405',
    nascimento: '1990-01-30',
};

test('modalidade que se sobrepoe fica bloqueada e a tela diz com qual', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    const bloco = page.getByRole('group', { name: /Modalidades esportivas/ });
    await expect(bloco).toBeVisible();

    const volei = page.locator('label').filter({ hasText: 'Vôlei' }).first();
    const caixaDoVolei = volei.locator('input[type="checkbox"]');

    // Antes de escolher o Futebol, o Volei esta livre.
    await expect(caixaDoVolei).toBeEnabled();

    await escolherAtividade(page, 'Futebol');

    // Agora o Volei bloqueia, e o motivo tem nome.
    await expect(volei).toContainText('conflito de horário com Futebol');
    await expect(caixaDoVolei).toBeDisabled();

    // O motivo esta ligado a caixa: quem usa leitor de tela ouve a explicacao.
    const idDoMotivo = await caixaDoVolei.getAttribute('aria-describedby');
    expect(idDoMotivo).toBeTruthy();
    await expect(page.locator(`#${idDoMotivo}`)).toContainText('conflito de horário com Futebol');

    // Tocar no cartao nao adianta: continua desmarcado.
    await volei.click({ force: true });
    await expect(caixaDoVolei).not.toBeChecked();
    await expect(bloco.getByRole('status')).toHaveText('1 de 2 selecionada');

    // O Handebol comeca as 10h, exatamente quando o Futebol termina: encostar
    // nao e sobrepor, entao ele continua disponivel.
    const handebol = page.locator('label').filter({ hasText: 'Handebol' }).first();
    await expect(handebol.locator('input[type="checkbox"]')).toBeEnabled();

    // Desmarcado o Futebol, o Volei volta a ser uma opcao.
    await escolherAtividade(page, 'Futebol');
    await expect(caixaDoVolei).toBeEnabled();
    await expect(bloco.getByRole('status')).toHaveText('0 de 2 selecionadas');
});
