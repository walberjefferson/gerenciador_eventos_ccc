import { type Locator, type Page } from '@playwright/test';
import { artisan, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O aviso rapido de toda acao do painel.
 *
 * Quem trabalha no painel nao le o alto da pagina: clica, espera e segue. O
 * aviso rapido (toast) e a resposta que aparece onde a pessoa esta olhando —
 * e o paragrafo dentro do conteudo continua existindo para quem voltar a tela
 * depois.
 *
 * Quatro perguntas, na ordem em que elas quebram de verdade:
 *
 * 1. a acao confirmada aparece como aviso, sem que o texto suma da pagina;
 * 2. **a mesma frase, duas vezes seguidas, aparece duas vezes** — e a pergunta
 *    que decide este arquivo: reenviar a mesma mensagem produz um texto
 *    identico ao anterior, e uma tela que ficasse de olho no TEXTO nao veria
 *    mudanca nenhuma na segunda vez. O defeito so aparece no uso repetido, que
 *    e justamente o uso real do painel;
 * 3. a recusa de negocio — a que nao e culpa de nenhum campo — aparece como
 *    aviso de erro, e nao pendurada num campo inocente;
 * 4. quem usa leitor de tela ouve a frase **uma vez so**: o anuncio e do aviso
 *    rapido, e o paragrafo da pagina deixou de ser regiao viva.
 */

const SENHA = 'senha-de-teste-dos-avisos';

const ORGANIZADOR = 'avisos.organizador@example.com';
const ADMINISTRADOR = 'avisos.administrador@example.com';

const ATIVIDADE = 'Handebol';

const CANCELADA: PessoaDeTeste = {
    nome: 'Laura Avisada Nunes',
    email: 'laura.avisada@example.com',
    telefone: '(11) 96666-1101',
    cpf: '11220330012',
    nascimento: '1987-04-14',
    sexo: 'Feminino',
};

const ANUNCIADA: PessoaDeTeste = {
    nome: 'Cecília Anunciada Prado',
    email: 'cecilia.anunciada@example.com',
    telefone: '(11) 96666-1104',
    cpf: '44550660046',
    nascimento: '1995-02-09',
    sexo: 'Feminino',
};

const REENVIADA: PessoaDeTeste = {
    nome: 'Otavio Reenvio Castro',
    email: 'otavio.reenvio@example.com',
    telefone: '(11) 96666-1102',
    cpf: '22330440020',
    nascimento: '1990-12-03',
    sexo: 'Masculino',
};

const DISPUTADA: PessoaDeTeste = {
    nome: 'Helena Disputada Rocha',
    email: 'helena.disputada@example.com',
    telefone: '(11) 96666-1103',
    cpf: '33440550038',
    nascimento: '1983-08-21',
    sexo: 'Feminino',
};

/** A frase que o servidor escreve ao cancelar. Ela e sempre a mesma. */
const AVISO_DO_CANCELAMENTO = 'Inscrição cancelada e vaga devolvida.';

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

    await page.getByRole('link', { name: `Abrir a ficha de ${nome}` }).click();

    await page.waitForURL(/\/admin\/inscricoes\/\d+$/);
    await expect(page.getByRole('heading', { name: nome, level: 1 })).toBeVisible();
}

/**
 * Os avisos rapidos que estao na tela agora.
 *
 * Reconhecidos pelo botao de fechar, que e nosso e esta em portugues — e nao
 * por classe de estilo, que muda com o desenho e levaria o cenario junto.
 */
function avisosRapidos(page: Page): Locator {
    return page.locator('li').filter({ has: page.getByRole('button', { name: 'Fechar aviso' }) });
}

/**
 * O token que o Laravel exige em qualquer envio.
 *
 * Aqui ele serve para cancelar a inscricao **por fora da tela aberta**, que e o
 * jeito de reproduzir a corrida real: alguem cancela enquanto outra pessoa esta
 * com o dialogo de confirmacao de pagamento aberto.
 */
async function tokenDeSeguranca(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

    expect(token, 'o cookie XSRF-TOKEN precisa existir depois do login').not.toBe('');

    return decodeURIComponent(token);
}

test.beforeAll(() => {
    prepararConta(ORGANIZADOR, 'Organizadora dos avisos', 'organizador');
    prepararConta(ADMINISTRADOR, 'Administradora dos avisos', 'administrador');
});

test('a acao confirmada vira aviso rapido, e o texto continua na pagina', async ({ page }) => {
    await inscreverPessoa(page, CANCELADA, ATIVIDADE);

    await entrar(page, ORGANIZADOR);
    await abrirFichaDe(page, CANCELADA.nome);

    await page.getByTestId('abrir-cancelamento').click();

    const dialogo = page.getByRole('dialog');
    await expect(dialogo).toBeVisible();

    await dialogo.getByLabel('Motivo do cancelamento').fill('Desistiu por telefone e pediu para liberar a vaga.');
    await dialogo.getByRole('button', { name: 'Cancelar inscrição' }).click();

    // 1. O aviso rapido aparece, com a frase que o servidor escreveu.
    await expect(avisosRapidos(page).filter({ hasText: AVISO_DO_CANCELAMENTO })).toHaveCount(1);

    // 2. E o paragrafo dentro do conteudo continua la: quem rolar a pagina
    //    depois de o aviso sumir ainda le o que aconteceu.
    const paragrafo = page.locator('#conteudo-administrativo p').filter({ hasText: AVISO_DO_CANCELAMENTO });

    await expect(paragrafo).toHaveCount(1);
    await expect(paragrafo).toBeVisible();
});

test('quem usa leitor de tela ouve a frase uma vez so', async ({ page }) => {
    await inscreverPessoa(page, ANUNCIADA, ATIVIDADE);

    await entrar(page, ORGANIZADOR);
    await abrirFichaDe(page, ANUNCIADA.nome);

    await page.getByTestId('abrir-cancelamento').click();

    const dialogo = page.getByRole('dialog');
    await dialogo.getByLabel('Motivo do cancelamento').fill('Cancelamento para conferir o anúncio.');
    await dialogo.getByRole('button', { name: 'Cancelar inscrição' }).click();

    await expect(avisosRapidos(page).filter({ hasText: AVISO_DO_CANCELAMENTO })).toHaveCount(1);

    // O paragrafo continua com o texto, mas nao e mais regiao viva: se fosse,
    // o leitor de tela leria a mesma frase duas vezes — uma pelo aviso rapido
    // e outra pela pagina. Quem anuncia agora e so o aviso.
    const paragrafo = page.locator('#conteudo-administrativo p').filter({ hasText: AVISO_DO_CANCELAMENTO });

    await expect(paragrafo).toHaveCount(1);
    await expect(paragrafo).not.toHaveAttribute('role', /status|alert/);

    // E nao ha nenhuma outra regiao viva no conteudo desta ficha repetindo o
    // recado por outro caminho.
    await expect(page.locator('#conteudo-administrativo [role="status"], #conteudo-administrativo [aria-live]')).toHaveCount(0);
});

test('a mesma frase, duas vezes seguidas, aparece duas vezes', async ({ page }) => {
    await inscreverPessoa(page, REENVIADA, ATIVIDADE);

    await entrar(page, ORGANIZADOR);
    await abrirFichaDe(page, REENVIADA.nome);

    // Reenviar a mesma mensagem produz um texto identico ao da vez anterior —
    // e por isso que esta e a acao escolhida para o cenario.
    const frase = `“Instruções de pagamento” está a caminho de ${REENVIADA.email}.`;

    await page.getByTestId('tipo-de-reenvio').selectOption('instrucoes-de-pagamento');
    await page.getByTestId('reenviar-mensagem').click();

    const primeiro = avisosRapidos(page).filter({ hasText: frase });
    await expect(primeiro).toHaveCount(1);

    // Fechar o primeiro deixa a prova inequivoca: o que aparecer depois e um
    // aviso NOVO, e nao o anterior que ficou na tela.
    await primeiro.getByRole('button', { name: 'Fechar aviso' }).click();
    await expect(avisosRapidos(page)).toHaveCount(0);

    await page.getByTestId('reenviar-mensagem').click();

    await expect(avisosRapidos(page).filter({ hasText: frase })).toHaveCount(1);
});

test('a recusa de negocio vira aviso de erro, e nao erro de campo', async ({ page }) => {
    await inscreverPessoa(page, DISPUTADA, ATIVIDADE);

    await entrar(page, ADMINISTRADOR);
    await abrirFichaDe(page, DISPUTADA.nome);

    const enderecoDaFicha = page.url();

    // O dialogo abre enquanto a inscricao ainda aguarda pagamento.
    await page.getByTestId('abrir-confirmacao-manual').click();

    const dialogo = page.getByRole('dialog');
    await expect(dialogo).toBeVisible();

    await dialogo.getByLabel('Como o pagamento foi recebido').fill('Recebido em espécie na secretaria, recibo 118.');

    // E, por fora desta tela, a inscricao e cancelada — a corrida que acontece
    // de verdade quando duas pessoas cuidam do mesmo evento.
    const cancelamento = await page.request.post(`${enderecoDaFicha}/cancelar`, {
        headers: { 'X-XSRF-TOKEN': await tokenDeSeguranca(page) },
        form: { motivo: 'Cancelada pelo balcão enquanto a confirmação estava aberta.' },
        failOnStatusCode: false,
    });

    expect(cancelamento.status()).toBeLessThan(400);

    await dialogo.getByRole('button', { name: 'Confirmar pagamento' }).click();

    // A recusa aparece como aviso de erro, com a razao escrita em portugues.
    const recusa = avisosRapidos(page).filter({ hasText: 'Esta inscricao foi cancelada' });

    await expect(recusa).toHaveCount(1);

    // E nao ficou pendurada no campo da observacao, que nao tem nada de errado:
    // o texto digitado estava certo; o que nao cabia era a acao. Por isso o
    // dialogo fecha — nao ha nada ali para a pessoa corrigir.
    await expect(dialogo).toBeHidden();
});
