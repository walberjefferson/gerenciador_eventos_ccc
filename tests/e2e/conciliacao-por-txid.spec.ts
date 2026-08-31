import type { Page } from '@playwright/test';
import { artisan, idExternoDaCobranca, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * Conciliar dinheiro: sair de um identificador de cobrança e chegar à pessoa.
 *
 * O gesto é sempre o mesmo, e é o de quem está com o painel da instituição
 * financeira aberto ao lado: **copiar o `txid` de lá e colar na busca daqui**.
 * O que se prova é que esse gesto funciona de ponta a ponta — a busca acha a
 * inscrição certa e a ficha dela mostra **o mesmo** identificador que foi
 * colado, e não um código parecido.
 *
 * Esse "não um código parecido" é o motivo de este arquivo existir. A ficha
 * mostra dois códigos de 26 caracteres que nunca coincidem: o interno, que só
 * este sistema conhece, e o `txid`, que só o provedor conhece. Confundir um
 * com o outro é procurar pelo código errado no painel do provedor e concluir
 * que o pagamento não existe.
 *
 * O identificador não aparece em tela nenhuma do participante — ele é da
 * conversa entre o sistema e a instituição financeira. Por isso ele é
 * perguntado ao banco de dados, do lado de fora, exatamente como faz o
 * `confirmacao-do-pagamento.spec.ts`.
 */

const SENHA = 'senha-de-teste-da-conciliacao';
const ORGANIZADOR = 'conciliacao.organizadora@example.com';

const ATIVIDADE = 'Handebol';

/** Quem pagou e vai ser procurada pelo identificador da cobrança dela. */
const PAGANTE: PessoaDeTeste = {
    nome: 'Sônia Conciliada Prado',
    email: 'sonia.conciliada@example.com',
    telefone: '(11) 97777-5511',
    cpf: '50520530020',
    nascimento: '1983-04-21',
};

/** A outra: existe só para provar que a busca reduz mesmo a lista a uma linha. */
const VIZINHA: PessoaDeTeste = {
    nome: 'Rita Vizinha Antunes',
    email: 'rita.vizinha.conciliacao@example.com',
    telefone: '(11) 97777-5522',
    cpf: '60620630019',
    nascimento: '1991-12-05',
};

function prepararConta(email: string, nome: string, papel: string): void {
    artisan([
        'tinker',
        '--execute',
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
            `['email' => '${email}'],` +
            `['name' => '${nome}', 'password' => '${SENHA}', 'email_verified_at' => now()]` +
            `);` +
            `$usuario->syncRoles(['${papel}']);`,
    ]);
}

async function entrar(page: Page, email: string): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(email);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test.beforeAll(() => {
    prepararConta(ORGANIZADOR, 'Organizadora da conciliação', 'organizador');
});

test.describe('em tela grande', () => {
    // Conciliar é trabalho de quem está sentado, com dois painéis abertos.
    test.use({
        viewport: { width: 1280, height: 800 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('o txid colado na busca leva à inscrição, e a ficha mostra o mesmo txid', async ({ page }) => {
        const inscricao = await inscreverPessoa(page, PAGANTE, ATIVIDADE);
        await inscreverPessoa(page, VIZINHA, ATIVIDADE);

        const txid = idExternoDaCobranca(inscricao.codigo);

        await entrar(page, ORGANIZADOR);

        // 1. O gesto: colar na busca o identificador que veio do provedor.
        await page.goto('/admin/inscricoes');
        await page.getByTestId('abrir-filtros').click();
        await page.getByLabel('Buscar').fill(txid);
        await page.getByRole('button', { name: 'Filtrar' }).click();

        // 2. Sobra uma linha só — e é a da pessoa certa.
        await expect(page.getByRole('rowheader', { name: PAGANTE.nome })).toHaveCount(1);
        await expect(page.getByRole('rowheader', { name: VIZINHA.nome })).toHaveCount(0);

        // 3. A ficha dela.
        await page.getByRole('link', { name: `Abrir a ficha de ${PAGANTE.nome}` }).click();
        await page.waitForURL(/\/admin\/inscricoes\/\d+$/);

        await expect(page.getByRole('heading', { name: 'Histórico da cobrança' })).toBeVisible();

        // 4. O fecho: o identificador escrito na ficha é EXATAMENTE o que foi
        //    colado na busca. Sem isso, a conciliação seria um chute.
        const celulaDoTxid = page.locator('[data-testid^="cobranca-txid-"]').first();

        await expect(celulaDoTxid).toBeVisible();
        await expect(celulaDoTxid).toHaveText(txid);

        // E os dois códigos continuam distintos e rotulados de forma que
        // ninguém os troque: o de dentro de casa e o do provedor. O código
        // interno é o que estava sozinho na tela sob o rótulo "Cobrança" — e
        // procurá-lo no painel da Efí não devolve nada, porque ele não existe
        // lá.
        await expect(page.getByRole('columnheader', { name: 'Código interno' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'txid (Efí)' })).toBeVisible();

        const linhaDaCobranca = page.getByRole('row').filter({ has: page.locator('[data-testid^="cobranca-txid-"]') }).first();
        const codigoInterno = (await linhaDaCobranca.getByRole('rowheader').innerText()).trim();

        expect(codigoInterno).not.toBe(txid);
        expect(codigoInterno).not.toBe('');
    });

    test('um txid que não existe não devolve inscrição nenhuma', async ({ page }) => {
        await entrar(page, ORGANIZADOR);

        await page.goto('/admin/inscricoes');
        await page.getByTestId('abrir-filtros').click();
        await page.getByLabel('Buscar').fill('fake_txidquenuncaexistiu0000');
        await page.getByRole('button', { name: 'Filtrar' }).click();

        // Lista vazia é resposta, e a tela precisa dizê-la com todas as letras:
        // uma tabela em branco pareceria dado que sumiu.
        await expect(page.getByText('Nenhuma inscrição encontrada com esses filtros.')).toBeVisible();
    });
});
