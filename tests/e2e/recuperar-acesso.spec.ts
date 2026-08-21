import { type Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { artisan, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * "Fechei o navegador e perdi o link."
 *
 * A regra que manda nesta tela e uma so: a resposta e sempre a mesma. Com
 * inscricao ou sem inscricao, o mesmo texto — senao o formulario viraria uma
 * maquina de descobrir quem esta inscrito.
 */
const inscrita: PessoaDeTeste = {
    nome: 'Genoveva Martins Alencar',
    email: 'genoveva.alencar@example.com',
    telefone: '(11) 96543-5050',
    cpf: '85274196373',
    nascimento: '1980-08-21',
};

/** Um endereco que ninguem usou para se inscrever. */
const EMAIL_SEM_INSCRICAO = 'ninguem.por.aqui@example.com';

/** Pede o link e devolve, na integra, o que a tela respondeu. */
async function pedirOLink(page: Page, email: string): Promise<string> {
    await page.goto(`/acesso?evento=${EVENTO_DEMO.slug}`);

    await page.getByLabel('E-mail da inscrição').fill(email);
    await page.getByTestId('botao-enviar-acesso').click();

    const mensagem = page.getByTestId('mensagem-do-acesso');
    await expect(mensagem).toBeVisible();

    return (await mensagem.innerText()).trim();
}

test('a tela responde a mesma coisa para quem tem e para quem nao tem inscricao', async ({ page }) => {
    await inscreverPessoa(page, inscrita, 'Basquete');

    // O limite de tentativas do zero: assim o cenario nao depende do que
    // ficou de uma execucao anterior.
    artisan(['cache:clear']);

    // Quem ja se inscreveu chega aqui pela propria pagina do evento.
    await page.goto(`/eventos/${EVENTO_DEMO.slug}`);
    await page.getByTestId('link-ja-me-inscrevi').click();
    await page.waitForURL(/\/acesso\?evento=/);
    await expect(page.getByRole('heading', { name: 'Acessar minha inscrição' })).toBeVisible();
    await expect(page.getByText(EVENTO_DEMO.nome).first()).toBeVisible();

    const comInscricao = await pedirOLink(page, inscrita.email);
    const semInscricao = await pedirOLink(page, EMAIL_SEM_INSCRICAO);

    // Nem uma virgula de diferenca entre os dois casos.
    expect(semInscricao).toBe(comInscricao);
    expect(comInscricao).toContain('Se houver inscrição com esse e-mail, enviamos o link de acesso para ele.');
});

test('o formulario recusa e-mail malformado sem dizer se ele existe', async ({ page }) => {
    await page.goto('/acesso');

    await page.getByLabel('E-mail da inscrição').fill('isso-nao-e-um-email');
    await page.getByTestId('botao-enviar-acesso').click();

    // O erro fala do formato do campo, nunca da existencia da inscricao.
    const erro = page.locator('#erro-email');
    await expect(erro).toBeVisible();
    await expect(page.getByTestId('campo-email')).toHaveAttribute('aria-describedby', 'ajuda-email erro-email');
    await expect(page.getByTestId('mensagem-do-acesso')).toHaveCount(0);
});
