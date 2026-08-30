import type { Page } from '@playwright/test';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A tela que mostra os avisos recebidos do provedor de pagamento.
 *
 * Ela é de computador: nasce de alguém sentado, investigando por que uma
 * cobrança não se confirmou. Por isso este arquivo declara o próprio tamanho de
 * tela — 1280×800 —, como já faz o `admin-barra-lateral.spec.ts`, e **não** vira
 * um projeto novo no `playwright.config.ts`: um projeto extra faria os outros
 * cenários de celular rodarem duas vezes só para provar uma coisa.
 *
 * O que se pergunta aqui, e que só um navegador responde:
 *
 * 1. quem administra chega à tela **pelo menu**, e não digitando o endereço;
 * 2. a lista mostra o que o provedor mandou, com a situação escrita;
 * 3. o filtro de situação separa o que falhou do resto;
 * 4. o conteúdo do aviso abre a um clique, e o botão diz que abriu;
 * 5. quem organiza o evento **não vê o item no menu** — porta que leva a 403
 *    ensina a ignorar o menu.
 */

const SENHA = 'senha-de-teste-do-painel';
const ADMINISTRADOR = 'avisos.administradora@example.com';
const ORGANIZADOR = 'avisos.organizador@example.com';

/** O identificador que só existe dentro do conteúdo do aviso, e não na linha. */
const TXID_DO_AVISO = 'txid-do-cenario-de-avisos-9911';

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

/**
 * Semeia três avisos, um de cada situação que interessa ver.
 *
 * Eles são gravados direto, e não entregues ao endereço do webhook: o caminho
 * de entrada é do Pest, e o que se prova aqui é a tela que lê o resultado.
 */
function semearAvisos(): void {
    artisan([
        'tinker',
        '--execute',
        `\\App\\Models\\WebhookPagamento::query()->where('gateway', 'cenario-e2e')->delete();` +
            `\\App\\Models\\WebhookPagamento::query()->create([` +
            `'gateway' => 'cenario-e2e', 'id_evento_externo' => 'aviso-processado',` +
            `'tipo_evento' => 'pix', 'payload' => ['pix' => [['txid' => '${TXID_DO_AVISO}', 'chave' => '[removido]']]],` +
            `'assinatura_valida' => true, 'recebido_em' => now()->subMinutes(10),` +
            `'processado_em' => now()->subMinutes(10), 'situacao' => 'processado',` +
            `]);` +
            `\\App\\Models\\WebhookPagamento::query()->create([` +
            `'gateway' => 'cenario-e2e', 'id_evento_externo' => 'aviso-ignorado',` +
            `'tipo_evento' => 'pix', 'payload' => ['pix' => []],` +
            `'assinatura_valida' => true, 'recebido_em' => now()->subMinutes(20),` +
            `'processado_em' => now()->subMinutes(20), 'situacao' => 'ignorado',` +
            `'erro' => 'Cobranca desconhecida neste sistema.',` +
            `]);` +
            `\\App\\Models\\WebhookPagamento::query()->create([` +
            `'gateway' => 'cenario-e2e', 'id_evento_externo' => null,` +
            `'tipo_evento' => 'pix', 'payload' => ['secret' => '[removido]'],` +
            `'assinatura_valida' => false, 'recebido_em' => now()->subMinutes(30),` +
            `'processado_em' => now()->subMinutes(30), 'situacao' => 'falhou',` +
            `'erro' => 'Assinatura invalida.',` +
            `]);`,
    ]);
}

async function entrar(page: Page, email: string): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(email);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));

    await page.goto('/admin/painel');
    await expect(page.getByRole('heading', { name: 'Painel', level: 1 })).toBeVisible();
}

test.beforeAll(() => {
    prepararConta(ADMINISTRADOR, 'Administradora dos avisos', 'administrador');
    prepararConta(ORGANIZADOR, 'Organizador do evento', 'organizador');
    semearAvisos();
});

test.describe('em tela grande', () => {
    // A tela é de investigação, feita para quem está sentado num computador.
    test.use({
        viewport: { width: 1280, height: 800 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('quem administra chega pelo menu e vê os avisos que o provedor mandou', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);

        // Pelo menu, e não pelo endereço digitado: é assim que a pessoa chega.
        await page.getByRole('link', { name: 'Avisos do provedor' }).click();

        await expect(page).toHaveURL(/\/admin\/pagamentos\/avisos$/);
        await expect(page.getByRole('heading', { name: 'Avisos do provedor', level: 1 })).toBeVisible();

        const tabela = page.getByTestId('tabela-avisos');
        await expect(tabela).toBeVisible();

        // A situação vem escrita, e não só colorida: quem não distingue cores lê
        // exatamente a mesma informação (WCAG 1.4.1).
        await expect(tabela).toContainText('Processado');
        await expect(tabela).toContainText('Ignorado');
        await expect(tabela).toContainText('Falhou');

        // Assinatura inválida é informação de segurança, e aparece por extenso.
        await expect(tabela).toContainText('Inválida');
        await expect(tabela).toContainText('Assinatura invalida.');

        // E a explicação de que "ignorado" não é erro fica à vista, ao lado do
        // filtro — não escondida atrás de um ícone.
        await expect(page.getByText('Ignorado não é erro')).toBeVisible();
    });

    test('o filtro de situação separa o que falhou do resto', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);
        await page.goto('/admin/pagamentos/avisos');

        // O painel de filtros comeca recolhido quando nenhum filtro esta
        // valendo: os campos existem, mas ficam `hidden` ate alguem abrir.
        await page.getByTestId('abrir-filtros').click();

        await page.getByTestId('avisos-filtro-situacao').selectOption('falhou');
        await page.getByRole('button', { name: 'Filtrar' }).click();

        const tabela = page.getByTestId('tabela-avisos');

        await expect(tabela).toContainText('Falhou');
        await expect(tabela).not.toContainText('Processado');

        // O endereço guarda o filtro: recarregar ou virar a página não joga fora
        // o que a pessoa acabou de pedir.
        await expect(page).toHaveURL(/situacao=falhou/);
    });

    test('o conteúdo do aviso abre a um clique, e o botão diz que abriu', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);
        await page.goto('/admin/pagamentos/avisos');

        const botao = page.getByRole('button', { name: 'Ver conteúdo do aviso' }).first();

        await expect(botao).toBeVisible();
        await expect(botao).toHaveAttribute('aria-expanded', 'false');

        // Alvo de dedo tem piso, mesmo numa tela de computador (DA-42).
        const caixa = await botao.boundingBox();
        expect(caixa, 'o botão precisa estar desenhado na tela').not.toBeNull();
        expect(Math.round(caixa!.height), 'o botão precisa ter 44px de altura').toBeGreaterThanOrEqual(44);

        // Fechado, o conteúdo não está na tela: um jsonb inteiro por linha
        // tornaria a lista ilegível.
        await expect(page.getByText(TXID_DO_AVISO)).toHaveCount(0);

        await botao.click();

        await expect(botao).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByText(TXID_DO_AVISO)).toBeVisible();

        await botao.click();

        await expect(botao).toHaveAttribute('aria-expanded', 'false');
        await expect(page.getByText(TXID_DO_AVISO)).toHaveCount(0);
    });

    test('o painel diz há quanto tempo chegou o último aviso', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);

        const cartao = page.getByTestId('painel-avisos-do-provedor');

        await expect(cartao).toBeVisible();
        await expect(page.getByTestId('painel-ultimo-aviso')).toContainText('há');
    });

    test('quem organiza o evento não vê o item no menu, e a porta continua fechada', async ({ page }) => {
        await entrar(page, ORGANIZADOR);

        // Item de menu que só leva a uma porta fechada ensina a ignorar o menu.
        await expect(page.getByRole('link', { name: 'Avisos do provedor' })).toHaveCount(0);

        // E o cartão do painel some pelo mesmo motivo: ele é um caminho para a
        // mesma tela.
        await expect(page.getByTestId('painel-avisos-do-provedor')).toHaveCount(0);

        // A tranca não é do menu: é da rota. Digitar o endereço não abre nada.
        const resposta = await page.goto('/admin/pagamentos/avisos');
        expect(resposta?.status()).toBe(403);
    });
});
