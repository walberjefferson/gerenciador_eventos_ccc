import type { Page } from '@playwright/test';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A tela que diz quem entra no painel, com que papel, e ate quando.
 *
 * Roda em 1280x800 pelo mesmo motivo do `admin-barra-lateral.spec.ts`: o
 * restante da suite imita um Pixel 5, e a barra lateral — que e por onde esta
 * tela e alcancada — nem chega a ser barra abaixo de 768px, vira uma gaveta.
 * O caminho que interessa aqui, "clicar em Usuarios no menu", so existe em tela
 * grande. O arquivo declara o proprio viewport em vez de virar um projeto novo
 * no `playwright.config.ts`: um projeto extra faria todos os cenarios de
 * celular rodarem duas vezes para provar uma coisa so (D-86).
 */

const SENHA = 'senha-de-teste-do-painel';
const ADMINISTRADORA = 'contas.administradora@example.com';
const ORGANIZADORA = 'contas.organizadora@example.com';
const ALVO = 'contas.alvo@example.com';

/**
 * Cria as contas pela linha de comando, como nascem as contas de verdade.
 *
 * Nao existe tela de cadastro por onde o cenario pudesse passar: o cadastro
 * publico foi fechado (D-51) e esta feature, de proposito, nao trouxe um.
 */
function prepararContas(): void {
    const criar = (email: string, nome: string, papel: string): string =>
        `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
        `['email' => '${email}'],` +
        `['name' => '${nome}', 'password' => '${SENHA}', 'email_verified_at' => now(), 'ativo' => true]` +
        `);` +
        `$usuario->syncRoles(['${papel}']);`;

    artisan([
        'tinker',
        '--execute',
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            criar(ADMINISTRADORA, 'Administradora das contas', 'administrador') +
            criar(ORGANIZADORA, 'Organizadora sem acesso', 'organizador') +
            criar(ALVO, 'Alvo da promocao', 'organizador'),
    ]);
}

/** Entra pela tela de login, com os gestos de quem administra. */
async function entrar(page: Page, email: string): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(email);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test.beforeAll(() => {
    prepararContas();
});

test.describe('em tela grande', () => {
    test.use({
        viewport: { width: 1280, height: 800 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('a tela abre pelo menu e mostra papel e situacao de cada conta', async ({ page }) => {
        await entrar(page, ADMINISTRADORA);

        await page.goto('/admin/painel');

        // Pelo MENU, e nao pelo endereco: item que existe mas nao leva a lugar
        // nenhum e o defeito que este cenario procura.
        await page.getByRole('link', { name: 'Usuários' }).click();

        await expect(page).toHaveURL(/\/admin\/usuarios$/);
        await expect(page.getByRole('heading', { name: 'Usuários', level: 1 })).toBeVisible();

        const tabela = page.getByTestId('tabela-usuarios');
        await expect(tabela).toBeVisible();

        // A situacao aparece por extenso, e nao so por cor (WCAG 1.4.1).
        await expect(tabela.getByText('Ativo', { exact: true }).first()).toBeVisible();
    });

    test('trocar o papel de outra pessoa funciona e a lista volta com o papel novo', async ({ page }) => {
        await entrar(page, ADMINISTRADORA);
        await page.goto('/admin/usuarios');

        const linha = page.getByRole('row').filter({ hasText: ALVO });
        const seletor = linha.getByRole('combobox');

        await expect(seletor).toHaveValue('organizador');

        await seletor.selectOption('administrador');

        // A confirmacao vem do servidor: a lista recarrega com o papel gravado
        // e a frase de sucesso aparece.
        await expect(page.getByText(/agora é administrador/i)).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: ALVO }).getByRole('combobox')).toHaveValue('administrador');

        // Devolve o cenario ao estado inicial: os cenarios compartilham o mesmo
        // banco semeado (playwright.config.ts, workers: 1).
        await page.getByRole('row').filter({ hasText: ALVO }).getByRole('combobox').selectOption('organizador');
        await expect(page.getByText(/agora é organizador/i)).toBeVisible();
    });

    test('a propria linha aparece marcada como "voce" e sem acoes', async ({ page }) => {
        await entrar(page, ADMINISTRADORA);
        await page.goto('/admin/usuarios');

        const minhaLinha = page.getByTestId('linha-de-voce');

        await expect(minhaLinha).toHaveCount(1);
        await expect(minhaLinha).toContainText(ADMINISTRADORA);
        await expect(minhaLinha).toContainText('você');

        // Nem seletor de papel, nem botao de desativar.
        await expect(minhaLinha.getByRole('combobox')).toHaveCount(0);
        await expect(minhaLinha.getByRole('button')).toHaveCount(0);

        // E o motivo fica escrito: acao ausente sem explicacao parece defeito.
        await expect(minhaLinha).toContainText('Esta é a sua conta');
    });

    test('desativar pede confirmacao antes de tirar o acesso de alguem', async ({ page }) => {
        await entrar(page, ADMINISTRADORA);
        await page.goto('/admin/usuarios');

        const linha = page.getByRole('row').filter({ hasText: ALVO });

        await linha.getByRole('button', { name: 'Desativar' }).click();

        // Um clique so nao desativa ninguem: a pergunta aparece primeiro.
        await expect(linha).toContainText('Desativar mesmo?');
        await expect(linha).toContainText('Ativo');

        await linha.getByRole('button', { name: 'Não' }).click();
        await expect(linha).toContainText('Ativo');

        await linha.getByRole('button', { name: 'Desativar' }).click();
        await linha.getByRole('button', { name: 'Sim, desativar' }).click();

        await expect(page.getByRole('row').filter({ hasText: ALVO })).toContainText('Desativado');

        // Devolve o cenario ao estado inicial.
        await page.getByRole('row').filter({ hasText: ALVO }).getByRole('button', { name: 'Reativar' }).click();
        await expect(page.getByRole('row').filter({ hasText: ALVO })).toContainText('Ativo');
    });

    test('a matriz de papeis explica em portugues o que cada permissao alcanca', async ({ page }) => {
        await entrar(page, ADMINISTRADORA);
        await page.goto('/admin/usuarios');

        await page.getByTestId('ir-para-papeis').click();

        await expect(page).toHaveURL(/\/admin\/papeis$/);
        await expect(page.getByRole('heading', { name: 'Papéis', level: 1 })).toBeVisible();

        // O texto em portugues do PapeisSeeder, e nao so o nome tecnico.
        await expect(page.getByText('Cadastrar setores e grupos de participantes')).toBeVisible();

        // "Alcança"/"Não alcança" por extenso, nunca so a cor.
        await expect(page.getByTestId('organizador-usuarios.gerenciar')).toHaveText('Não alcança');
        await expect(page.getByTestId('administrador-usuarios.gerenciar')).toHaveText('Alcança');
    });

    test('quem organiza o evento nao ve o item no menu e recebe porta fechada', async ({ page }) => {
        await entrar(page, ORGANIZADORA);
        await page.goto('/admin/painel');

        await expect(page.getByRole('link', { name: 'Usuários' })).toHaveCount(0);

        const resposta = await page.goto('/admin/usuarios');

        expect(resposta?.status()).toBe(403);
    });
});
