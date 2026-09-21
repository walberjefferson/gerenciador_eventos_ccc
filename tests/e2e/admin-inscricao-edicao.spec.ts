import { type Page } from '@playwright/test';
import { artisan, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * A correcao de uma inscricao e as acoes da ficha, pelo lado de dentro.
 *
 * Quatro perguntas, na ordem em que a secretaria as faz de verdade: consigo
 * consertar o nome que veio errado; consigo trocar a atividade que a pessoa
 * escolheu por engano; o sistema me impede de criar um choque de horario; e
 * consigo mandar de novo a mensagem que ela jura nao ter recebido.
 *
 * A quinta e de quem cuida do acesso: quem nao tem a permissao nao alcanca a
 * tela — nem digitando o endereco.
 *
 * As contas nascem por linha de comando, como nos outros cenarios do painel: o
 * cadastro publico foi fechado de proposito (DA-11).
 */

const SENHA = 'senha-de-teste-da-edicao';

const ORGANIZADOR = 'edicao.organizador@example.com';
const PORTEIRO = 'edicao.portaria@example.com';

/** A pessoa cuja ficha vai ser corrigida ao longo do cenario. */
const INSCRITA: PessoaDeTeste = {
    nome: 'Joana Digitada Errado',
    email: 'joana.digitada@example.com',
    telefone: '(11) 96666-1122',
    cpf: '30330340042',
    nascimento: '1992-04-15',
    sexo: 'Feminino',
};

/** O nome depois da correção do primeiro cenário. */
const NOME_CORRIGIDO = 'Joana Corrigida Silva';

/** A atividade escolhida no formulario publico. */
const ESCOLHIDA = 'Futebol';

/** A troca: mesmo bloco, sem choque com a de cima. */
const NOVA = 'Basquete';

/** Esta se sobrepoe ao futebol (08-10 contra 09-11): o servidor precisa recusar. */
const CONFLITANTE = 'Vôlei';

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

/** Abre a ficha de uma pessoa achando-a pela busca da lista. */
async function abrirFichaDe(page: Page, nome: string): Promise<void> {
    await page.goto('/admin/inscricoes');

    await page.getByTestId('abrir-filtros').click();
    await page.getByLabel('Buscar').fill(nome);
    await page.getByRole('button', { name: 'Filtrar' }).click();

    await expect(page.getByRole('rowheader', { name: nome })).toHaveCount(1);

    // O mesmo gesto do admin-inscricoes.spec.ts: o link da linha tem nome
    // acessível próprio, e é por ele que se abre a ficha.
    await page.getByRole('link', { name: `Abrir a ficha de ${nome}` }).click();

    await page.waitForURL(/\/admin\/inscricoes\/\d+$/);
}

/** O endereco da ficha, para voltar a ela sem passar pela busca de novo. */
async function fichaDe(page: Page, nome: string): Promise<string> {
    await abrirFichaDe(page, nome);

    return page.url();
}

test.describe('edição e ações da inscrição', () => {
    test('corrige os dados, troca a atividade, recusa o choque e reenvia a mensagem', async ({ page }) => {
        prepararConta(ORGANIZADOR, 'Organizadora da Edição', 'organizador');

        await inscreverPessoa(page, INSCRITA, ESCOLHIDA);

        await entrar(page, ORGANIZADOR);

        const ficha = await fichaDe(page, INSCRITA.nome);

        // --- o caminho feliz: corrigir o nome e trocar a atividade ---------
        await page.getByTestId('editar-inscricao').click();
        await page.waitForURL(/\/editar$/);

        // O CPF nao esta na tela, e nao e esquecimento: e a chave que impede a
        // mesma pessoa de se inscrever duas vezes no mesmo evento.
        await expect(page.getByLabel('CPF')).toHaveCount(0);
        await expect(page.getByText(INSCRITA.cpf)).toHaveCount(0);

        await page.getByLabel('Nome completo').fill(NOME_CORRIGIDO);

        // Sai o futebol, entra o basquete: os dois no mesmo bloco, sem choque.
        await page.locator('label').filter({ hasText: ESCOLHIDA }).first().click();
        await page.locator('label').filter({ hasText: NOVA }).first().click();

        await page.getByTestId('gravar-edicao').click();

        await page.waitForURL(/\/admin\/inscricoes\/\d+$/);
        await expect(page.getByText('Inscrição atualizada.')).toBeVisible();
        await expect(page.getByRole('heading', { name: NOME_CORRIGIDO, level: 1 })).toBeVisible();

        // A atividade nova aparece na ficha, e a antiga saiu de cena.
        const atividades = page.getByRole('region', { name: 'Atividades escolhidas' });
        await expect(atividades.getByText(NOVA)).toBeVisible();
        await expect(atividades.getByText(ESCOLHIDA, { exact: false })).toHaveCount(0);

        // --- a recusa: duas atividades que se sobrepõem no horário ---------
        await page.goto(`${ficha}/editar`);

        await page.locator('label').filter({ hasText: CONFLITANTE }).first().click();
        await page.locator('label').filter({ hasText: 'Handebol' }).first().click();

        await page.getByTestId('gravar-edicao').click();

        // A frase vem do mesmo validador do formulário público: quem corrige lê
        // exatamente o que o participante leria.
        await expect(page.getByRole('alert').filter({ hasText: 'mesmo horário' })).toBeVisible();

        // --- reenviar a mensagem que a pessoa diz não ter recebido ---------
        await page.goto(ficha);

        await page.getByTestId('tipo-de-reenvio').selectOption('instrucoes-de-pagamento');
        await page.getByTestId('reenviar-mensagem').click();

        await expect(page.getByText(/está a caminho de/)).toBeVisible();
    });

    test('mostra o QR do ingresso e entrega o PDF depois do pagamento', async ({ page }) => {
        prepararConta(ORGANIZADOR, 'Organizadora da Edição', 'organizador');

        const pessoa: PessoaDeTeste = {
            nome: 'Paula Pagante Souza',
            email: 'paula.pagante@example.com',
            telefone: '(11) 96666-3344',
            cpf: '40440450039',
            nascimento: '1991-07-21',
            sexo: 'Feminino',
        };

        const inscricao = await inscreverPessoa(page, pessoa, ESCOLHIDA);

        // O dinheiro entra pelo caminho real do domínio — é ele que emite o
        // ingresso. A tela da cobrança tem cenário próprio; aqui o que se prova
        // é o que a ficha administrativa mostra depois.
        artisan([
            'tinker',
            '--execute',
            `$i = \\App\\Models\\Inscricao::query()->where('codigo_publico', '${inscricao.codigo}')->firstOrFail();` +
                `app(\\App\\Actions\\Pagamentos\\ConfirmarPagamento::class)($i->pagamentoPendente());`,
        ]);

        await entrar(page, ORGANIZADOR);
        await abrirFichaDe(page, pessoa.nome);

        // O desenho chega pronto do servidor, em SVG: aparece mesmo com a rede
        // ruim e não depende de biblioteca nenhuma no navegador.
        await expect(page.getByTestId('qr-do-ingresso').locator('svg')).toBeVisible();
        await expect(page.getByTestId('codigo-do-ingresso')).toBeVisible();

        const baixa = page.waitForEvent('download');
        await page.getByTestId('baixar-ingresso').click();

        const arquivo = await baixa;

        expect(arquivo.suggestedFilename()).toBe(`ingresso-${inscricao.codigo}.pdf`);
    });

    test('nega a tela de edição a quem não tem a permissão', async ({ page }) => {
        prepararConta(ORGANIZADOR, 'Organizadora da Edição', 'organizador');
        prepararConta(PORTEIRO, 'Voluntário do Portão', 'portaria');

        await entrar(page, ORGANIZADOR);

        // O primeiro cenário já corrigiu o nome desta pessoa: é pelo nome que
        // ficou que ela é encontrada agora.
        const ficha = await fichaDe(page, NOME_CORRIGIDO);

        // Sai o organizador, entra quem só alcança o portão.
        await page.goto('/admin');
        await entrar(page, PORTEIRO);

        // Digitar o endereço direto é a primeira coisa que qualquer pessoa
        // tenta: a recusa precisa vir do servidor, e não de um botão escondido.
        const resposta = await page.goto(`${ficha}/editar`);

        expect(resposta?.status()).toBe(403);
    });
});
