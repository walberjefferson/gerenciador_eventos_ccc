import { EVENTO_DEMO } from './ambiente';
import { escolherAtividade, escolherNaLista } from './apoio';
import { expect, test } from './base';

/**
 * O que a pessoa ve quando erra o preenchimento.
 *
 * Sao dois momentos diferentes: o aviso que a propria tela da antes de gastar
 * a viagem ate o servidor, e a recusa do servidor, que manda em ultima
 * instancia. Nos dois, a mesma exigencia: frase simples e o cursor de volta no
 * campo com problema, para ninguem ficar preso sem entender o que houve.
 */

const CPF_IMPOSSIVEL = '11111111111';

test('a tela avisa, antes de enviar, o CPF incompleto e o campo obrigatorio vazio', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await page.getByLabel('Nome completo').fill('Maria de Lourdes Alves');
    // E-mail de proposito em branco.
    await page.getByLabel('Telefone com DDD').fill('(11) 96666-3333');
    await page.getByLabel('CPF').fill('123');
    await page.getByLabel('Data de nascimento').fill('1975-02-20');
    await escolherNaLista(page, 'Cidade', 'São Paulo (SP)');
    await escolherNaLista(page, 'Grupo', 'Centro');

    await page.getByRole('button', { name: 'Continuar' }).click();

    // Continua na mesma etapa: nada avancou por cima do erro.
    await expect(page.getByRole('heading', { name: 'Seus dados' })).toBeVisible();

    // As duas frases sao escritas para quem nunca usou o sistema.
    const avisoDoEmail = page.getByRole('alert').filter({ hasText: 'Este e-mail parece incompleto' });
    await expect(avisoDoEmail).toBeVisible();
    await expect(page.getByRole('alert').filter({ hasText: 'Este CPF não parece válido' })).toBeVisible();

    // O erro esta ligado ao campo: o leitor de tela le os dois juntos.
    const email = page.locator('#email');
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAttribute('aria-describedby', 'erro-email');
    await expect(page.locator('#documento')).toHaveAttribute('aria-describedby', 'erro-documento');

    // E o cursor volta para o primeiro campo com problema.
    await expect(email).toBeFocused();
});

test('o servidor recusa o CPF impossivel e a tela volta ao passo do campo', async ({ page }) => {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    // Onze digitos: passa pela conferencia de formato da tela e so o servidor
    // percebe que este numero nao existe.
    await page.getByLabel('Nome completo').fill('José Carlos Batista');
    await page.getByLabel('E-mail').fill('jose.batista@example.com');
    await page.getByLabel('Telefone com DDD').fill('(11) 95555-4444');
    await page.getByLabel('CPF').fill(CPF_IMPOSSIVEL);
    await page.getByLabel('Data de nascimento').fill('1980-11-07');
    await escolherNaLista(page, 'Cidade', 'São Paulo (SP)');
    await escolherNaLista(page, 'Grupo', 'Centro');

    await page.getByRole('button', { name: 'Continuar' }).click();

    await expect(page.getByRole('group', { name: /Modalidades esportivas/ })).toBeVisible();
    await escolherAtividade(page, 'Futebol');
    await page.getByRole('button', { name: 'Continuar' }).click();

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    // A recusa do servidor traz a pessoa de volta ao passo 1, no campo errado.
    await expect(page.getByRole('heading', { name: 'Seus dados' })).toBeVisible();
    await expect(page.getByRole('alert').filter({ hasText: 'Este CPF não parece válido' })).toBeVisible();
    await expect(page.locator('#documento')).toBeFocused();

    // E nenhuma cobranca foi criada: ninguem saiu desta tela.
    expect(page.url()).toContain('/inscricao');
});
