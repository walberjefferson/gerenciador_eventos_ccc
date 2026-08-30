import type { Page } from '@playwright/test';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A tela que guarda a credencial do provedor de pagamento.
 *
 * É a tela mais perigosa do sistema, e os cenários aqui perguntam exatamente
 * as quatro coisas que, se falharem, custam caro:
 *
 * 1. quem administra consegue cadastrar e salvar;
 * 2. **o que foi salvo não reaparece na tela** — nem no campo, nem no HTML;
 * 3. quem organiza o evento recebe a porta fechada, e não uma tela vazia;
 * 4. passar a cobrar de verdade exige confirmação escrita.
 *
 * Nenhum certificado é enviado por aqui de propósito: certificado não entra em
 * repositório, em nenhum formato. O caminho do upload é provado pelo Pest, que
 * fabrica o arquivo em tempo de execução.
 */

const SENHA = 'senha-de-teste-do-painel';

const ADMINISTRADOR = 'credenciais.administradora@example.com';
const ORGANIZADOR = 'credenciais.organizador@example.com';

/** O valor fictício que os cenários digitam — e que não pode voltar da tela. */
const IDENTIFICACAO = 'Client_Id_Ficticio_Do_Cenario_E2E_9911';
const CHAVE_PIX = 'pix-ficticio-do-cenario@example.com';

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
    prepararConta(ADMINISTRADOR, 'Administradora das credenciais', 'administrador');
    prepararConta(ORGANIZADOR, 'Organizador do evento', 'organizador');
});

test('quem administra cadastra homologação, salva, e o que salvou não volta para a tela', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await page.goto('/admin/pagamentos/credenciais');

    await expect(page.getByRole('heading', { name: 'Credenciais de pagamento', level: 1 })).toBeVisible();
    await expect(page.getByTestId('credenciais-bloco-homologacao')).toBeVisible();
    await expect(page.getByTestId('credenciais-bloco-producao')).toBeVisible();

    // Antes de qualquer coisa: o sistema avisa que ainda está lendo o arquivo
    // de ambiente do servidor, e não esta tela.
    await expect(page.getByTestId('credenciais-origem')).toContainText('arquivo de ambiente');

    await page.getByTestId('credenciais-client-id-homologacao').fill(IDENTIFICACAO);
    await page.getByTestId('credenciais-client-secret-homologacao').fill('Chave_Ficticia_Do_Cenario_E2E_2288');
    await page.getByTestId('credenciais-chave-pix-homologacao').fill(CHAVE_PIX);

    // O valor do aviso automático não se digita à mão: o botão gera um forte.
    await page.getByTestId('credenciais-gerar-hmac-homologacao').click();

    const valorGerado = await page.getByTestId('credenciais-webhook-hmac-homologacao').inputValue();
    expect(valorGerado).toHaveLength(64);

    // E, com ele em mãos, o endereço para colar no painel da Efí fica pronto —
    // com o valor e com o `?ignorar=` que a Efí exige.
    const endereco = page.getByTestId('credenciais-webhook-url-homologacao');
    await expect(endereco).toContainText(`hmac=${valorGerado}`);
    await expect(endereco).toContainText('&ignorar=');
    await expect(endereco).toContainText('/webhooks/pagamentos');

    await page.getByTestId('credenciais-salvar-homologacao').click();

    await expect(page.getByTestId('credenciais-sucesso')).toBeVisible();

    // A prova que importa: **nada do que foi digitado voltou**.
    await expect(page.getByTestId('credenciais-client-id-homologacao')).toHaveValue('');
    await expect(page.getByTestId('credenciais-client-secret-homologacao')).toHaveValue('');
    await expect(page.getByTestId('credenciais-chave-pix-homologacao')).toHaveValue('');
    await expect(page.getByTestId('credenciais-webhook-hmac-homologacao')).toHaveValue('');

    // Nem no campo, nem em lugar nenhum do HTML — props do Inertia inclusive.
    const html = await page.content();
    expect(html).not.toContain(IDENTIFICACAO);
    expect(html).not.toContain('Chave_Ficticia_Do_Cenario_E2E_2288');
    expect(html).not.toContain(CHAVE_PIX);
    expect(html).not.toContain(valorGerado);

    // O que a tela mostra é a existência do valor, e não o valor.
    await expect(page.getByTestId('credenciais-client-id-homologacao')).toHaveAttribute('placeholder', /valor guardado/i);

    // E recarregar a página não traz o segredo de volta pela porta dos fundos.
    await page.reload();
    expect(await page.content()).not.toContain(IDENTIFICACAO);
});

test('quem organiza o evento recebe a porta fechada, não uma tela vazia', async ({ page }) => {
    await entrar(page, ORGANIZADOR);

    const resposta = await page.goto('/admin/pagamentos/credenciais');

    expect(resposta?.status()).toBe(403);
    await expect(page.getByTestId('credenciais-bloco-homologacao')).toHaveCount(0);

    // E o menu não oferece o caminho: item que só leva a uma porta fechada
    // ensina a pessoa a ignorar o menu.
    await page.goto('/admin/painel');
    await expect(page.getByRole('link', { name: 'Credenciais de pagamento' })).toHaveCount(0);
});

test('passar a cobrar de verdade exige confirmação escrita', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await page.goto('/admin/pagamentos/credenciais');

    await page.getByTestId('credenciais-ativar-producao').click();

    const confirmacao = page.getByTestId('credenciais-confirmar-producao');
    await expect(confirmacao).toBeVisible();
    await expect(confirmacao).toContainText('cobrança gerada será real');

    // Enquanto a palavra não for digitada, o botão não deixa passar. Clicar em
    // "sim" já virou reflexo para quem usa computador; digitar, não.
    await expect(page.getByTestId('credenciais-confirmar-ativacao')).toBeDisabled();

    await page.getByTestId('credenciais-palavra-confirmacao').fill('talvez');
    await expect(page.getByTestId('credenciais-confirmar-ativacao')).toBeDisabled();

    await page.getByTestId('credenciais-palavra-confirmacao').fill('PRODUCAO');
    await expect(page.getByTestId('credenciais-confirmar-ativacao')).toBeEnabled();

    await page.getByTestId('credenciais-cancelar-ativacao').click();
    await expect(confirmacao).toHaveCount(0);

    // Nada mudou: produção continua sem estar em uso.
    await expect(page.getByTestId('credenciais-ativo-producao')).toHaveCount(0);
});

test('o teste de conexão diz o que falta, sem repetir o que foi digitado', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await page.goto('/admin/pagamentos/credenciais');

    await page.getByTestId('credenciais-testar-producao').click();

    const resultado = page.getByTestId('credenciais-teste-producao');
    await expect(resultado).toBeVisible();
    await expect(resultado).toContainText('Nao ha credencial cadastrada');

    // O cadastro de homologação existe (cenário anterior), mas está sem
    // certificado: a recusa fala do que falta, em português.
    await page.getByTestId('credenciais-testar-homologacao').click();

    const resultadoHomologacao = page.getByTestId('credenciais-teste-homologacao');
    await expect(resultadoHomologacao).toBeVisible();
    await expect(resultadoHomologacao).toContainText('Certificado');
    await expect(resultadoHomologacao).not.toContainText(IDENTIFICACAO);
});
