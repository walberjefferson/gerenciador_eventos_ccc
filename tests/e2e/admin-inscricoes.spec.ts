import { devices, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { URL_BASE } from './ambiente';
import { artisan, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * A gestao das inscricoes pelo lado de dentro.
 *
 * Tres perguntas, na ordem em que a organizacao as faz de verdade: eu consigo
 * **achar** uma pessoa no meio de todas; eu consigo **cancelar** a inscricao
 * dela e a vaga volta na hora; e o sistema me impede de cancelar **sem dizer
 * por que**.
 *
 * A quarta pergunta e de quem cuida do dinheiro: o organizador **nao** pode
 * declarar que um pagamento entrou. Essa e a unica acao do sistema que afirma
 * "entrou dinheiro" sem que nenhuma fonte externa tenha reconhecido nada, e
 * por isso e exclusiva do administrador (DA-13).
 *
 * As contas nascem por linha de comando, como no admin-acesso.spec.ts: o
 * cadastro publico foi fechado de proposito (DA-11).
 */

const SENHA = 'senha-de-teste-das-inscricoes';

const ORGANIZADOR = 'inscricoes.organizador@example.com';
const ADMINISTRADOR = 'inscricoes.administrador@example.com';

/** A pessoa que vai ser cancelada no meio do cenario. */
const DESISTENTE: PessoaDeTeste = {
    nome: 'Marta Desistente Lima',
    email: 'marta.desistente@example.com',
    telefone: '(11) 97777-3311',
    cpf: '10120130068',
    nascimento: '1988-09-02',
};

/** A outra pessoa: existe so para provar que o filtro realmente filtra. */
const VIZINHA: PessoaDeTeste = {
    nome: 'Beatriz Vizinha Rocha',
    email: 'beatriz.vizinha@example.com',
    telefone: '(11) 97777-3322',
    cpf: '20220230056',
    nascimento: '1990-03-19',
};

/** A atividade escolhida pelas duas: e nela que a vaga precisa voltar. */
const ATIVIDADE = 'Handebol';

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

/**
 * Quantas vagas o painel diz que ainda restam na atividade.
 *
 * Vem da tela, e nao do banco, de proposito: o que importa provar e que o
 * numero que a organizacao **le** volta ao lugar depois do cancelamento.
 *
 * A celula e escrita para gente ("22 vagas", "1 vaga"), entao o numero e lido
 * de dentro do texto. Se um dia a atividade ficar sem limite, a celula dira
 * "Sem limite" e a leitura falha aqui mesmo, em vez de virar uma conta com
 * NaN que passaria despercebida.
 */
async function vagasRestantes(page: Page): Promise<number> {
    await page.goto('/admin/painel');

    const linha = page.getByRole('row').filter({ has: page.getByRole('rowheader', { name: ATIVIDADE }) });

    await expect(linha).toBeVisible();

    // Capacidade, Reservadas, Confirmadas e Restantes — a ultima e a que conta.
    const celula = (await linha.getByRole('cell').last().innerText()).trim();
    const numero = /^(\d+)\s+vagas?$/.exec(celula);

    expect(numero, `o painel precisa dizer quantas vagas restam em ${ATIVIDADE}, e disse "${celula}"`).not.toBeNull();

    return Number(numero![1]);
}

/** Abre a ficha de uma pessoa achando-a pela busca da lista. */
async function abrirFichaDe(page: Page, nome: string): Promise<void> {
    await page.goto('/admin/inscricoes');

    // O painel de filtros comeca recolhido: abrir e o primeiro passo de quem
    // vai filtrar, tanto aqui quanto na tela.
    await page.getByTestId('abrir-filtros').click();
    await page.getByLabel('Buscar').fill(nome);
    await page.getByRole('button', { name: 'Filtrar' }).click();

    await expect(page.getByRole('rowheader', { name: nome })).toHaveCount(1);

    await page.getByRole('link', { name: `Abrir a ficha de ${nome}` }).click();

    await page.waitForURL(/\/admin\/inscricoes\/\d+$/);
    await expect(page.getByRole('heading', { name: nome, level: 1 })).toBeVisible();
}

/**
 * O token que o Laravel exige em qualquer envio.
 *
 * O navegador manda sozinho; um pedido feito na mao precisa copiar o valor do
 * cookie para o cabecalho, senao a recusa vem do controle de formulario e nao
 * da permissao — e seria outro teste, nao o que interessa aqui.
 */
async function tokenDeSeguranca(contexto: BrowserContext): Promise<string> {
    const cookies = await contexto.cookies();
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

    expect(token, 'o cookie XSRF-TOKEN precisa existir depois do login').not.toBe('');

    return decodeURIComponent(token);
}

/**
 * Uma segunda janela, com identidade propria.
 *
 * Serve para colocar os dois papeis diante da mesma ficha ao mesmo tempo, cada
 * um com a sua sessao. A janela nasce com o mesmo aparelho e o mesmo endereco
 * base dos demais cenarios, e com a mesma tesoura na rede externa: nada de
 * esperar por uma fonte hospedada fora, que a suite nao pode depender de
 * internet.
 */
async function abrirJanelaSeparada(browser: Browser): Promise<BrowserContext> {
    const janela = await browser.newContext({
        ...devices['Pixel 5'],
        baseURL: URL_BASE,
        locale: 'pt-BR',
        timezoneId: 'America/Sao_Paulo',
    });

    const anfitriao = new URL(URL_BASE).host;

    await janela.route('**/*', async (rota) => {
        const destino = new URL(rota.request().url());
        const interno = destino.host === anfitriao || destino.protocol === 'data:' || destino.protocol === 'blob:';

        await (interno ? rota.continue() : rota.abort());
    });

    return janela;
}

test.beforeAll(() => {
    prepararConta(ORGANIZADOR, 'Organizadora das inscrições', 'organizador');
    prepararConta(ADMINISTRADOR, 'Administradora das inscrições', 'administrador');
});

test('o organizador acha a pessoa pelo filtro, cancela com motivo e a vaga volta', async ({ page }) => {
    await inscreverPessoa(page, DESISTENTE, ATIVIDADE);
    await inscreverPessoa(page, VIZINHA, ATIVIDADE);

    await entrar(page, ORGANIZADOR);

    const antes = await vagasRestantes(page);

    // 1. Achar: a busca reduz a lista a uma linha so.
    await page.goto('/admin/inscricoes');
    await page.getByTestId('abrir-filtros').click();
    await page.getByLabel('Buscar').fill('Marta Desistente');
    await page.getByRole('button', { name: 'Filtrar' }).click();

    await expect(page.getByRole('rowheader', { name: DESISTENTE.nome })).toHaveCount(1);
    await expect(page.getByRole('rowheader', { name: VIZINHA.nome })).toHaveCount(0);

    // O CPF nao aparece na lista, nem inteiro nem em pedaco.
    await expect(page.locator('body')).not.toContainText(DESISTENTE.cpf);

    // 2. Abrir a ficha.
    await page.getByRole('link', { name: `Abrir a ficha de ${DESISTENTE.nome}` }).click();
    await page.waitForURL(/\/admin\/inscricoes\/\d+$/);

    await expect(page.getByRole('heading', { name: 'Histórico da cobrança' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText(DESISTENTE.cpf);

    // 3. Cancelar, escrevendo o motivo que fica registrado.
    await page.getByTestId('abrir-cancelamento').click();

    const dialogo = page.getByRole('dialog');
    await expect(dialogo).toBeVisible();

    await dialogo.getByLabel('Motivo do cancelamento').fill('Desistiu por telefone e pediu para liberar a vaga.');
    await dialogo.getByRole('button', { name: 'Cancelar inscrição' }).click();

    await expect(page.getByText('Inscrição cancelada e vaga devolvida.')).toBeVisible();
    await expect(page.getByText('Desistiu por telefone e pediu para liberar a vaga.')).toBeVisible();

    // A inscricao nao some: fica registrada como cancelada, e a acao de
    // cancelar deixa de ser oferecida porque nao ha mais o que cancelar.
    await expect(page.getByTestId('abrir-cancelamento')).toHaveCount(0);

    // 4. E a vaga voltou para a fila, no numero que a organizacao le.
    const depois = await vagasRestantes(page);

    expect(depois).toBe(antes + 1);
});

test('cancelar sem escrever o motivo e barrado na tela', async ({ page }) => {
    const teimosa: PessoaDeTeste = {
        nome: 'Carla Teimosa Dias',
        email: 'carla.teimosa@example.com',
        telefone: '(11) 97777-3333',
        cpf: '30320330044',
        nascimento: '1985-11-30',
    };

    await inscreverPessoa(page, teimosa, ATIVIDADE);

    await entrar(page, ORGANIZADOR);
    await abrirFichaDe(page, teimosa.nome);

    await page.getByTestId('abrir-cancelamento').click();

    const dialogo = page.getByRole('dialog');
    await expect(dialogo).toBeVisible();

    // Botao apertado com o campo vazio: o navegador nem deixa o pedido sair.
    await dialogo.getByRole('button', { name: 'Cancelar inscrição' }).click();

    await expect(dialogo).toBeVisible();
    await expect(page.getByText('Inscrição cancelada e vaga devolvida.')).toHaveCount(0);

    // Esc fecha o dialogo e o foco volta para o botao que o abriu.
    await page.keyboard.press('Escape');

    await expect(dialogo).toBeHidden();
    await expect(page.getByTestId('abrir-cancelamento')).toBeFocused();

    // E a inscricao continua exatamente onde estava.
    await page.reload();
    await expect(page.getByTestId('abrir-cancelamento')).toBeVisible();
});

test('o organizador nao enxerga o botao de confirmar pagamento na mao; o administrador sim', async ({ page, browser }) => {
    const pagante: PessoaDeTeste = {
        nome: 'Nilza Pagante Alves',
        email: 'nilza.pagante@example.com',
        telefone: '(11) 97777-3344',
        cpf: '40420430032',
        nascimento: '1979-06-08',
    };

    await inscreverPessoa(page, pagante, ATIVIDADE);

    // O organizador ve a ficha inteira, menos a acao que mexe em dinheiro.
    await entrar(page, ORGANIZADOR);
    await abrirFichaDe(page, pagante.nome);

    await expect(page.getByTestId('abrir-cancelamento')).toBeVisible();
    await expect(page.getByTestId('abrir-confirmacao-manual')).toHaveCount(0);

    const enderecoDaFicha = page.url();

    // E nem pela porta dos fundos: sem o botao na tela, o pedido feito na mao
    // tambem e recusado, porque quem decide e a permissao, nao a interface.
    const tentativa = await page.request.post(`${enderecoDaFicha}/confirmar-pagamento`, {
        headers: { 'X-XSRF-TOKEN': await tokenDeSeguranca(page.context()) },
        form: { metodo: 'dinheiro', observacao: 'Tentando por fora da tela.' },
        failOnStatusCode: false,
    });

    expect(tentativa.status()).toBe(403);

    // Ja o administrador enxerga o botao e consegue reconhecer o pagamento.
    // Sessao propria, em outra janela: cada papel com a sua identidade.
    const janelaDoAdministrador = await abrirJanelaSeparada(browser);
    const telaDoAdministrador = await janelaDoAdministrador.newPage();

    try {
        await entrar(telaDoAdministrador, ADMINISTRADOR);
        await telaDoAdministrador.goto(enderecoDaFicha);

        const abrir = telaDoAdministrador.getByTestId('abrir-confirmacao-manual');

        await expect(abrir).toBeVisible();
        await abrir.click();

        const dialogo = telaDoAdministrador.getByRole('dialog');
        await expect(dialogo).toBeVisible();

        await dialogo.getByLabel('Como o pagamento foi recebido').fill('Entregou em espécie na secretaria, com recibo.');
        await dialogo.getByRole('button', { name: 'Confirmar pagamento' }).click();

        await expect(telaDoAdministrador.getByText('Pagamento reconhecido e inscrição confirmada.')).toBeVisible();

        // O historico guarda quem declarou: "esta pago" nunca fica sem dono.
        await expect(telaDoAdministrador.getByText(/Reconhecida na mão por Administradora das inscrições/)).toBeVisible();
    } finally {
        await janelaDoAdministrador.close();
    }
});
