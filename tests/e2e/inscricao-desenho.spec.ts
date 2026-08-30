import type { Locator, Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { expect, test } from './base';

/**
 * A tela de inscricao no desenho do prototipo.
 *
 * Este arquivo existe por causa de um defeito que so aparece em tela de
 * computador, e que a suite inteira — que roda num Pixel 5 — nunca veria: o
 * formulario era campo solto sobre o papel, em tres grades de larguras
 * diferentes, e CPF e nascimento saiam encolhidos no meio da linha enquanto a
 * coluna da direita simplesmente deixava de existir na altura deles.
 *
 * Por isso os cenarios de tela grande declaram o proprio tamanho de janela, no
 * mesmo formato de `admin-barra-lateral.spec.ts`, em vez de virarem um projeto
 * novo no `playwright.config.ts`: um projeto extra faria os outros cinquenta
 * cenarios de celular rodarem duas vezes so para provar isto aqui.
 */

/** A largura util de um campo, como o navegador a desenha. */
async function largura(campo: Locator): Promise<number> {
    const caixa = await campo.boundingBox();

    expect(caixa, 'o campo nao esta na tela').not.toBeNull();

    return Math.round(caixa!.width);
}

async function abrirOFormulario(page: Page): Promise<void> {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);
    await expect(page.getByRole('heading', { name: 'Seus dados' })).toBeVisible();
}

test.describe('em tela de computador', () => {
    test.use({
        viewport: { width: 1280, height: 800 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('o formulario mora num painel branco, e nao solto sobre o papel', async ({ page }) => {
        await abrirOFormulario(page);

        const painel = page.getByTestId('painel-da-etapa');
        await expect(painel).toBeVisible();

        const fundos = await page.evaluate(() => {
            const painelDaEtapa = document.querySelector('[data-testid="painel-da-etapa"]') as HTMLElement;

            return {
                painel: getComputedStyle(painelDaEtapa).backgroundColor,
                pagina: getComputedStyle(document.body).backgroundColor,
                raio: getComputedStyle(painelDaEtapa).borderTopLeftRadius,
                borda: getComputedStyle(painelDaEtapa).borderTopWidth,
            };
        });

        // O painel e o cartao branco sobre o papel: se os dois fundos forem
        // iguais, ele voltou a ser uma div invisivel.
        expect(fundos.painel, 'o painel perdeu o fundo de cartao').toBe('rgb(255, 255, 255)');
        expect(fundos.painel).not.toBe(fundos.pagina);
        expect(fundos.raio, 'o raio do painel nao e o `--r` de 14px do prototipo').toBe('14px');
        expect(fundos.borda, 'o painel perdeu a borda de 1px').toBe('1px');

        // .panel__n — a frase que explica por que pedimos estes dados.
        await expect(painel.getByText('Usamos só para organizar o encontro e enviar sua confirmação.')).toBeVisible();
    });

    test('os sete campos alinham nas mesmas duas colunas', async ({ page }) => {
        await abrirOFormulario(page);

        const nome = await largura(page.locator('#nome_completo'));
        const email = await largura(page.locator('#email'));
        const telefone = await largura(page.locator('#telefone'));
        const cpf = await largura(page.locator('#documento'));
        const nascimento = await largura(page.locator('#data_nascimento'));
        const cidade = await largura(page.locator('#cidade_id'));
        const grupo = await largura(page.locator('#grupo_participante_id'));

        // O DEFEITO da imagem "antes": CPF e nascimento vinham de uma grade com
        // `sm:max-w-md` e mediam pouco mais da metade da coluna de e-mail e de
        // cidade. Numa grade unica, as duas colunas tem sempre a mesma medida.
        for (const [rotulo, medida] of [
            ['telefone', telefone],
            ['CPF', cpf],
            ['nascimento', nascimento],
            ['cidade', cidade],
            ['grupo', grupo],
        ] as const) {
            expect(Math.abs(medida - email), `${rotulo} nao tem a largura de coluna do e-mail (${medida} contra ${email})`).toBeLessThanOrEqual(1);
        }

        // .f--full — o nome ocupa as duas colunas, que e a soma delas mais os
        // 18px de intervalo.
        expect(nome, 'o nome deixou de ocupar a linha inteira').toBeGreaterThan(email * 1.8);

        // E os campos ficam em duas colunas de verdade: e-mail e telefone
        // dividem a mesma linha.
        const topoDoEmail = (await page.locator('#email').boundingBox())!.y;
        const topoDoTelefone = (await page.locator('#telefone').boundingBox())!.y;

        expect(Math.abs(topoDoEmail - topoDoTelefone), 'e-mail e telefone deixaram de dividir a linha').toBeLessThanOrEqual(1);
    });

    test('cada campo diz o que espera receber, antes de a pessoa errar', async ({ page }) => {
        await abrirOFormulario(page);

        await expect(page.locator('#nome_completo')).toHaveAttribute('placeholder', 'Como está no documento');
        await expect(page.locator('#email')).toHaveAttribute('placeholder', 'nome@email.com');
        await expect(page.locator('#telefone')).toHaveAttribute('placeholder', '(00) 00000-0000');
        await expect(page.locator('#documento')).toHaveAttribute('placeholder', '000.000.000-00');
        await expect(page.locator('#data_nascimento')).toHaveAttribute('placeholder', 'dd/mm/aaaa');

        // O texto de espera do grupo diz o que fazer ANTES: sem cidade, a lista
        // esta vazia e "Escolha o seu grupo" seria um convite falso.
        await expect(page.locator('#grupo_participante_id')).toContainText('Escolha a cidade primeiro');
    });

    test('o resumo abre pelo nome do evento, com a data e o lugar embaixo', async ({ page }) => {
        await abrirOFormulario(page);

        const resumo = page.getByRole('complementary').filter({ hasText: 'Total' });

        await expect(resumo.getByRole('heading', { name: EVENTO_DEMO.nome })).toBeVisible();
        // .summary__ev — "{quando} · {local}", numa linha so.
        await expect(resumo.getByText(/·\s*Sítio Santa Clara/)).toBeVisible();
        await expect(resumo.getByText(EVENTO_DEMO.valor)).toBeVisible();
        await expect(resumo.getByText('Você só paga na última etapa.')).toBeVisible();

        // O titulo "Resumo" saiu, e o cartao de contato tambem: o mesmo
        // telefone ja esta no rodape de toda tela publica.
        await expect(resumo.getByText('Resumo', { exact: true })).toHaveCount(0);
        await expect(resumo.getByText('Precisa de ajuda?')).toHaveCount(0);
    });

    test('o cabecalho leva de volta para a agenda e para a propria inscricao', async ({ page }) => {
        await abrirOFormulario(page);

        const navegacao = page.getByRole('navigation', { name: 'Navegação do site' });

        await expect(navegacao.getByRole('link', { name: 'Agenda' })).toBeVisible();
        await expect(navegacao.getByRole('link', { name: 'Minha inscrição' })).toBeVisible();

        await navegacao.getByRole('link', { name: 'Minha inscrição' }).click();
        await page.waitForURL(/\/acesso/);

        // Chegando la, o link da pagina atual se anuncia como tal.
        await expect(navegacao.getByRole('link', { name: 'Minha inscrição' })).toHaveAttribute('aria-current', 'page');
    });

    test('o valor da inscricao aparece uma vez so na dobra de cima', async ({ page }) => {
        await abrirOFormulario(page);

        // A linha "Valor da inscrição: R$ 120,00" saiu do cabecalho da tela: o
        // valor vive no resumo, e repeti-lo acima das etapas era o mesmo numero
        // duas vezes na mesma dobra.
        await expect(page.getByText('Valor da inscrição')).toHaveCount(0);
        await expect(page.getByRole('heading', { name: 'Inscrição', exact: true })).toBeVisible();
        await expect(page.getByTestId('voltar-ao-evento')).toContainText(EVENTO_DEMO.nome);
    });

    test('o calendario escolhe o dia e devolve o foco para o campo', async ({ page }) => {
        await abrirOFormulario(page);

        const campo = page.locator('#data_nascimento');

        await campo.fill('15/03/1990');
        await page.getByRole('button', { name: 'Escolher no calendário' }).click();

        const calendario = page.getByRole('dialog');
        await expect(calendario).toBeVisible();

        // O dia 20 do mesmo mes: o calendario abre no mes da data escrita. Cada
        // dia se anuncia por extenso ("terça-feira, 20 de março de 1990"), que
        // e o que um leitor de tela le antes de a pessoa escolher.
        await calendario.getByRole('button', { name: /20 de março de 1990/ }).click();

        await expect(campo).toHaveValue('20/03/1990');
        await expect(calendario).toHaveCount(0);
        // Quem escolheu terminou com o calendario: o cursor volta para o campo,
        // e nao para o botao que o abre nem para o vazio.
        await expect(campo).toBeFocused();
    });

    test('o calendario tambem funciona so com o teclado', async ({ page }) => {
        await abrirOFormulario(page);

        const campo = page.locator('#data_nascimento');

        await campo.fill('10/07/1985');
        await page.getByRole('button', { name: 'Escolher no calendário' }).focus();
        await page.keyboard.press('Enter');

        const calendario = page.getByRole('dialog');
        await expect(calendario).toBeVisible();

        // Uma casa para a direita e Enter: 10 vira 11 de julho.
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Enter');

        await expect(campo).toHaveValue('11/07/1985');
        await expect(calendario).toHaveCount(0);
        await expect(campo).toBeFocused();

        // Escape fecha sem escolher, e o foco continua no campo.
        await page.getByRole('button', { name: 'Escolher no calendário' }).focus();
        await page.keyboard.press('Enter');
        await expect(calendario).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(calendario).toHaveCount(0);
        await expect(campo).toHaveValue('11/07/1985');
        await expect(campo).toBeFocused();
    });

    test('data que nao existe no calendario vira aviso em portugues', async ({ page }) => {
        await abrirOFormulario(page);

        // 31 de fevereiro. Enquanto o campo era o seletor nativo do navegador,
        // isto nao podia sequer ser digitado.
        await page.locator('#data_nascimento').fill('31/02/2000');
        await page.locator('#nome_completo').click();

        await expect(page.getByRole('alert').filter({ hasText: 'Esta data não existe' })).toBeVisible();
    });
});

test.describe('no celular', () => {
    // Sem `test.use`: fica valendo o Pixel 5 de 393px do playwright.config.ts.

    test('a barra do rodape continua trazendo o Total junto do Continuar', async ({ page }) => {
        await abrirOFormulario(page);

        // DESVIO CONSCIENTE do prototipo, que poe as acoes so dentro do painel:
        // no celular nao existe resumo lateral, e sem esta barra o Total nao
        // apareceria em lugar nenhum do formulario.
        const barra = page.getByTestId('barra-de-acoes');

        await expect(barra).toBeVisible();
        await expect(barra.getByText('Total')).toBeVisible();
        await expect(barra.getByText(EVENTO_DEMO.valor)).toBeVisible();
        // O Total e a acao principal na MESMA barra: um toque, sem procurar o
        // botao em outro canto da tela.
        await expect(barra.getByRole('button', { name: 'Continuar' })).toBeVisible();

        // Ela e a ultima coisa do painel, e no fim da rolagem esta na tela.
        await page.mouse.wheel(0, 3000);
        await expect(barra.getByRole('button', { name: 'Continuar' })).toBeInViewport();

        // O total NAO se repete em tela grande, onde o resumo ao lado ja o diz.
        await expect(barra.locator('p.lg\\:hidden')).toHaveCount(1);
    });

    test('os campos empilham numa coluna so, sem escapar da largura do aparelho', async ({ page }) => {
        await abrirOFormulario(page);

        const email = await largura(page.locator('#email'));
        const cpf = await largura(page.locator('#documento'));

        expect(Math.abs(email - cpf), 'os campos deixaram de ter a mesma largura no celular').toBeLessThanOrEqual(1);

        const escapa = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

        expect(escapa, 'a tela de inscricao escapa da largura do aparelho').toBeLessThanOrEqual(0);
    });
});
