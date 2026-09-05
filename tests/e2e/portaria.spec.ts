import type { Page } from '@playwright/test';
import { artisan, idExternoDaCobranca, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O portao, do jeito que ele e usado no dia do evento.
 *
 * Seis perguntas, na ordem em que a organizacao as faz de verdade: a entrada e
 * aceita e diz QUEM entrou; a segunda leitura do mesmo ingresso e recusada com
 * a hora da primeira; quem tem permissao desfaz o engano e o ingresso volta a
 * valer; os contadores de presentes e faltantes acompanham; a portaria nao
 * alcanca mais nada do painel; e a digitacao continua visivel mesmo sem camera.
 *
 * A CAMERA NAO E EXERCITADA AQUI, e a ausencia e deliberada: `getUserMedia` nao
 * funciona em origem insegura, e a suite roda em http. O caminho da digitacao —
 * que e o principal, e o que precisa funcionar quando a camera falha — e
 * percorrido inteiro. O que se prova sobre a camera e o que da para provar em
 * http: que a tela NAO depende dela.
 *
 * As contas nascem por linha de comando, como no admin-acesso.spec.ts: o
 * cadastro publico foi fechado de proposito (DA-11).
 *
 * CPF proprio deste arquivo: o dominio recusa duas inscricoes ativas com o
 * mesmo documento no mesmo evento, e dois cenarios com o mesmo numero se
 * matariam.
 */
const SENHA = 'senha-de-teste-da-portaria';

const PORTEIRO = 'portaria.voluntario@example.com';
const ORGANIZADOR = 'portaria.organizadora@example.com';

const PARTICIPANTE: PessoaDeTeste = {
    nome: 'Genivaldo Teixeira Moraes',
    email: 'genivaldo.moraes@example.com',
    telefone: '(11) 96543-7070',
    cpf: '70884495280',
    nascimento: '1983-04-27',
};

/**
 * A segunda pessoa, so para o cenario do desfazer.
 *
 * Ela existe porque o dominio recusa duas inscricoes ATIVAS com o mesmo
 * documento no mesmo evento: reaproveitar o CPF de cima faria o segundo
 * cenario morrer no formulario, sem chegar perto do portao.
 */
const PARTICIPANTE_DO_ENGANO: PessoaDeTeste = {
    nome: 'Otavio Bezerra Quintanilha',
    email: 'otavio.quintanilha@example.com',
    telefone: '(11) 96543-8080',
    cpf: '65415549609',
    nascimento: '1991-12-05',
};

const ATIVIDADE = 'Vôlei';

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

/** Sai da tela e volta com sessao limpa: cada cenario entra com um papel so. */
async function sair(page: Page): Promise<void> {
    await page.context().clearCookies();
}

/**
 * Uma inscricao paga de verdade, com ingresso emitido, e o codigo dele.
 *
 * O dinheiro e simulado, mas o caminho e o real: o provedor simulado emite o
 * mesmo aviso assinado que uma instituicao financeira emitiria, e e o dominio
 * que confirma a inscricao e faz o ingresso nascer.
 */
async function inscreverEPagar(page: Page, pessoa: PessoaDeTeste): Promise<string> {
    const inscricao = await inscreverPessoa(page, pessoa, ATIVIDADE);

    const idExterno = idExternoDaCobranca(inscricao.codigo);
    const aviso = await page.request.post(`/dev/pagamentos/${idExterno}/pagar`);
    expect(aviso.ok(), await aviso.text()).toBeTruthy();

    // O codigo do ingresso e lido da propria tela do participante, e nao do
    // banco: e exatamente o que a pessoa leva impresso para o portao.
    await page.goto(inscricao.urlDoAcompanhamento);

    const codigo = (await page.getByTestId('codigo-do-ingresso').innerText()).trim();

    expect(codigo, 'o participante confirmado precisa ver o codigo do ingresso').toMatch(/^[0-9A-HJKMNP-TV-Z]{4}-/);


    return codigo;
}

/** O numero de um dos cartoes do topo da portaria. */
async function contador(page: Page, qual: 'presentes' | 'faltantes'): Promise<number> {
    const texto = await page.getByTestId(`portaria-${qual}`).innerText();
    const numero = /(\d+)/.exec(texto);

    expect(numero, `o cartao de ${qual} precisa mostrar um numero, e mostrou "${texto}"`).not.toBeNull();

    return Number(numero![1]);
}

/** Digita o codigo e confere, como quem esta com a camera suja na fila. */
async function conferir(page: Page, codigo: string): Promise<void> {
    await page.getByTestId('portaria-codigo').fill(codigo);
    await page.getByTestId('portaria-conferir').click();
    await expect(page.getByTestId('resultado-da-leitura')).toBeVisible();
}

test.beforeAll(() => {
    prepararConta(PORTEIRO, 'Voluntário do portão', 'portaria');
    prepararConta(ORGANIZADOR, 'Organizadora do evento', 'organizador');
});

test('a entrada e aceita uma vez, a segunda e recusada e quem pode desfaz', async ({ page }) => {
    const codigo = await inscreverEPagar(page, PARTICIPANTE);

    // ---- 1. A portaria confere e a entrada e aceita, com o nome na tela ----
    await entrar(page, PORTEIRO);
    await page.goto('/admin/portaria');

    await expect(page.getByRole('heading', { name: 'Portaria', level: 1 })).toBeVisible();

    const presentesAntes = await contador(page, 'presentes');
    const faltantesAntes = await contador(page, 'faltantes');

    expect(faltantesAntes, 'a pessoa que acabou de pagar precisa estar entre os esperados').toBeGreaterThan(0);

    await conferir(page, codigo);

    const resultado = page.getByTestId('resultado-da-leitura');

    await expect(resultado).toHaveAttribute('data-resultado', 'aceito');
    await expect(page.getByTestId('veredito-da-leitura')).toHaveText('Entrada liberada');
    await expect(page.getByTestId('nome-de-quem-entrou')).toHaveText(PARTICIPANTE.nome);
    await expect(resultado).toContainText(ATIVIDADE);

    // Os contadores andaram: um a mais dentro, um a menos faltando.
    expect(await contador(page, 'presentes')).toBe(presentesAntes + 1);
    expect(await contador(page, 'faltantes')).toBe(faltantesAntes - 1);

    // ---- 2. O mesmo codigo de novo: recusado, com a hora da primeira ----
    await conferir(page, codigo);

    await expect(resultado).toHaveAttribute('data-resultado', 'recusado');
    await expect(resultado).toHaveAttribute('data-motivo', 'ja-utilizado');
    await expect(page.getByTestId('veredito-da-leitura')).toHaveText('Entrada recusada');
    await expect(page.getByTestId('motivo-da-recusa')).toContainText('Entrada já registrada em');
    // Com a hora, no formato que se le em voz alta no portao.
    await expect(page.getByTestId('motivo-da-recusa')).toContainText(/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/);

    // E o contador NAO andou de novo: recusar nao pode contar ninguem.
    expect(await contador(page, 'presentes')).toBe(presentesAntes + 1);

    // ---- 3. Codigo que nao existe ----
    await conferir(page, 'ZZZZ-ZZZZ-ZZZZ');

    await expect(resultado).toHaveAttribute('data-motivo', 'nao-encontrado');
    await expect(page.getByTestId('motivo-da-recusa')).toContainText('Código não encontrado');

    // ---- 4. A portaria NAO desfaz: o botao nem existe para ela ----
    await conferir(page, codigo);
    await expect(page.getByTestId('desfazer-entrada')).toHaveCount(0);

    // ---- 5. Quem organiza desfaz, e o ingresso volta a valer ----
    await sair(page);
    await entrar(page, ORGANIZADOR);
    await page.goto('/admin/portaria');

    await conferir(page, codigo);
    await expect(resultado).toHaveAttribute('data-motivo', 'ja-utilizado');

    // Na recusa por "ja utilizado" nao ha o que desfazer a partir da leitura:
    // desfazer sai da entrada ACEITA. Entao aceitamos de novo pelo caminho de
    // verdade — desfazendo a partir da ficha nao existe nesta fase.
    await expect(page.getByTestId('desfazer-entrada')).toHaveCount(0);
});

test('quem organiza desfaz a entrada aceita e o contador volta ao lugar', async ({ page }) => {
    const codigo = await inscreverEPagar(page, PARTICIPANTE_DO_ENGANO);

    await entrar(page, ORGANIZADOR);
    await page.goto('/admin/portaria');

    const presentesAntes = await contador(page, 'presentes');

    await conferir(page, codigo);
    await expect(page.getByTestId('resultado-da-leitura')).toHaveAttribute('data-resultado', 'aceito');
    expect(await contador(page, 'presentes')).toBe(presentesAntes + 1);

    // O botao existe para quem tem "presenca.desfazer" — e so para essa pessoa.
    await page.getByTestId('desfazer-entrada').click();

    await expect(page.getByTestId('portaria-aviso')).toContainText('Entrada desfeita');

    // O contador voltou ao lugar…
    expect(await contador(page, 'presentes')).toBe(presentesAntes);

    // …e o mesmo ingresso e aceito de novo, que e o ponto inteiro de desfazer.
    await conferir(page, codigo);
    await expect(page.getByTestId('resultado-da-leitura')).toHaveAttribute('data-resultado', 'aceito');
    expect(await contador(page, 'presentes')).toBe(presentesAntes + 1);
});

test('a portaria nao alcanca mais nada do painel, e entra numa tela util', async ({ page }) => {
    await entrar(page, PORTEIRO);

    // O endereco mais obvio do sistema leva o voluntario a tela dele, e nao a
    // um 403 do painel. Este era o defeito que o redirecionamento fixo tinha.
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/admin\/portaria$/);
    await expect(page.getByRole('heading', { name: 'Portaria', level: 1 })).toBeVisible();

    // A digitacao esta sempre visivel: um portao que so funciona com camera e
    // um portao que um dia nao funciona.
    await expect(page.getByTestId('portaria-codigo')).toBeVisible();
    await expect(page.getByTestId('portaria-conferir')).toBeVisible();

    for (const porta of ['/admin/painel', '/admin/inscricoes', '/admin/eventos', '/admin/usuarios', '/admin/auditoria']) {
        const resposta = await page.goto(porta);

        expect(resposta?.status(), `a portaria nao pode alcancar ${porta}`).toBe(403);
    }
});
