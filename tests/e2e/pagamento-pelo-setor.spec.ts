import type { Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { artisan, escolherAtividade, escolherNaLista, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O recebimento pela chave Pix do responsavel do setor, de ponta a ponta.
 *
 * Quatro cenarios, na ordem em que a vida acontece:
 *
 * 1. caminho feliz — a tela mostra chave, titular e QR Code do setor da pessoa,
 *    ela envia o comprovante e ve "em conferencia";
 * 2. conferencia — o responsavel entra, ve SO o seu setor, abre o comprovante,
 *    aceita com observacao e a inscricao fica confirmada;
 * 3. recusa — recusa com motivo; o participante le o motivo e envia outro;
 * 4. isolamento — o responsavel do Setor A nao ve nem alcanca inscricao do
 *    Setor B, nem pela lista, nem pela URL direta.
 *
 * O evento de demonstracao e colocado no modo setor por linha de comando, e os
 * setores ganham chave e responsavel do mesmo jeito: sao gestos de quem
 * administra o sistema, e nao do navegador. No fim, tudo volta como estava —
 * este arquivo nao pode mudar o que os outros cenarios encontram.
 */

const SENHA = 'senha-de-teste-do-painel';

const RESPONSAVEL_A = 'setor.batalha@example.com';
const RESPONSAVEL_B = 'setor.delmiro@example.com';

const SETOR_A = 'Setor Batalha';
const SETOR_B = 'Setor Delmiro';

const GRUPO_A = 'Batalha (Sede)';
const GRUPO_B = 'Mata Grande';

const CHAVE_A = 'batalha.setor@example.com';
const TITULAR_A = 'Joana Batalha da Silva';

const PESSOA_A: PessoaDeTeste = {
    nome: 'Marina do Setor Batalha',
    email: 'marina.batalha@example.com',
    telefone: '(82) 98111-2233',
    cpf: '81190000130',
    nascimento: '1993-04-12',
};

const PESSOA_B: PessoaDeTeste = {
    nome: 'Rogerio do Setor Delmiro',
    email: 'rogerio.delmiro@example.com',
    telefone: '(82) 98444-5566',
    cpf: '81190000210',
    nascimento: '1988-09-30',
};

/** Um comprovante de mentira, do tamanho e do tipo de um de verdade. */
const COMPROVANTE_JPEG = {
    name: 'comprovante-pix.jpg',
    mimeType: 'image/jpeg',
    // Um JPEG minimo de verdade: cabecalho, quantizacao e fim de imagem. Precisa
    // ser um arquivo que o servidor reconheca pelo CONTEUDO, porque a validacao
    // e por `mimetypes` e nao por extensao (RN-S5).
    buffer: Buffer.from(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a' +
            'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA' +
            'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
        'base64',
    ),
};

const COMPROVANTE_PNG = {
    name: 'comprovante-corrigido.png',
    mimeType: 'image/png',
    buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'),
};

function tinker(codigo: string): string {
    return artisan(['tinker', '--execute', codigo]);
}

/** Cria (ou reaproveita) a conta do responsavel e a amarra ao setor. */
function prepararResponsavel(email: string, nome: string, setor: string, chave: string, titular: string): void {
    tinker(
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
            `['email' => '${email}'],` +
            `['name' => '${nome}', 'password' => '${SENHA}', 'email_verified_at' => now(), 'ativo' => true]` +
            `);` +
            `$usuario->syncRoles(['responsavel-setor']);` +
            `\\App\\Models\\Cidade::query()->where('nome', '${setor}')->update([` +
            `'responsavel_id' => $usuario->id, 'chave_pix' => '${chave}', 'titular_chave_pix' => '${titular}'` +
            `]);`,
    );
}

/**
 * Todo setor ativo precisa estar pronto antes de o evento entrar no modo setor
 * (RN-S4). Os dois que os cenarios usam ganham dono; os demais ganham uma chave
 * generica, porque a regra olha o catalogo inteiro.
 */
function prepararCatalogoInteiro(): void {
    prepararResponsavel(RESPONSAVEL_A, 'Joana do Setor Batalha', SETOR_A, CHAVE_A, TITULAR_A);
    prepararResponsavel(RESPONSAVEL_B, 'Pedro do Setor Delmiro', SETOR_B, 'delmiro.setor@example.com', 'Pedro Delmiro');

    tinker(
        `$dono = \\App\\Models\\User::query()->where('email', '${RESPONSAVEL_A}')->value('id');` +
            `\\App\\Models\\Cidade::query()->whereNull('chave_pix')->update(['responsavel_id' => $dono, 'chave_pix' => 'outro.setor@example.com', 'titular_chave_pix' => 'Tesouraria']);`,
    );
}

function definirFormaDoEvento(forma: 'gateway' | 'setor'): void {
    // O prazo acompanha a forma: o modo setor exige no minimo 2 dias (RN-S8).
    const prazo = forma === 'setor' ? 10080 : 60;

    tinker(
        `\\App\\Models\\Evento::query()->where('slug', '${EVENTO_DEMO.slug}')` +
            `->update(['forma_recebimento' => '${forma}', 'prazo_pagamento_minutos' => ${prazo}]);`,
    );
}

/**
 * Entra no painel. Sempre limpando os cookies antes: varios cenarios trocam de
 * pessoa no meio, e quem ja esta logado nao ve a tela de login — ele e desviado
 * para o painel, e o campo de e-mail nunca aparece.
 */
async function entrar(page: Page, email: string): Promise<void> {
    await page.context().clearCookies();
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/** Percorre o formulario ate a tela da cobranca, escolhendo o setor pedido. */
async function inscreverNoSetor(page: Page, pessoa: PessoaDeTeste, setor: string, grupo: string, atividade: string): Promise<string> {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await escolherNaLista(page, 'Setor', setor);
    await escolherNaLista(page, 'Grupo', grupo);

    await page.getByRole('button', { name: 'Continuar' }).click();

    await escolherAtividade(page, atividade);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);

    return page.url();
}

test.beforeAll(() => {
    prepararCatalogoInteiro();
    definirFormaDoEvento('setor');
});

test.afterAll(() => {
    // O evento volta ao modo de sempre: nenhum outro cenario pediu isto.
    definirFormaDoEvento('gateway');
});

test('a tela de pagamento mostra a chave do setor e aceita o comprovante', async ({ page }) => {
    const urlDaCobranca = await inscreverNoSetor(page, PESSOA_A, SETOR_A, GRUPO_A, 'Futebol');

    // 1. Para quem a pessoa esta pagando.
    await expect(page.getByTestId('pix-do-setor')).toBeVisible();
    await expect(page.getByTestId('nome-do-setor')).toHaveText(SETOR_A);
    await expect(page.getByTestId('titular-do-setor')).toHaveText(TITULAR_A);
    await expect(page.getByTestId('chave-pix-do-setor')).toHaveText(CHAVE_A);

    // 2. O QR Code continua existindo: o BR Code e montado localmente, mas e um
    //    Pix como qualquer outro.
    await expect(page.locator('svg[role="img"][aria-label="QR Code para pagar com Pix"]')).toBeVisible();
    await expect(page.getByTestId('codigo-copia-e-cola')).toHaveValue(/br\.gov\.bcb\.pix/);

    // 3. O envio do comprovante.
    await expect(page.getByTestId('envio-de-comprovante')).toBeVisible();
    await page.getByTestId('campo-do-comprovante').setInputFiles(COMPROVANTE_JPEG);
    await page.getByTestId('botao-enviar-comprovante').click();

    // 4. "Em conferência" — e a inscricao continua aguardando pagamento (RN-S7).
    await expect(page.getByTestId('comprovante-em-conferencia')).toBeVisible();
    await expect(page.getByTestId('comprovante-em-conferencia')).toContainText(COMPROVANTE_JPEG.name);
    await expect(page.getByTestId('cobranca-aguardando')).toBeVisible();

    expect(urlDaCobranca).toContain('/pagamento');
});

test('o responsavel confere o comprovante do proprio setor e a inscricao fica confirmada', async ({ page }) => {
    await entrar(page, RESPONSAVEL_A);

    await page.goto('/admin/comprovantes');

    // Ele ve apenas o setor dele, e a tela diz isso.
    await expect(page.getByTestId('escopo-da-fila')).toContainText(SETOR_A);

    const linha = page.locator('tbody tr').filter({ hasText: PESSOA_A.nome });

    await expect(linha).toHaveCount(1);
    await expect(linha).toContainText(SETOR_A);

    // O comprovante sai por rota autenticada, e nao por URL publica (RN-S11).
    const endereco = await linha.getByRole('link', { name: 'Abrir comprovante' }).getAttribute('href');

    expect(endereco).toContain('/admin/comprovantes/');
    expect(endereco).toContain('/arquivo');

    const resposta = await page.request.get(endereco as string);

    expect(resposta.status()).toBe(200);
    expect(resposta.headers()['content-disposition']).toContain(COMPROVANTE_JPEG.name);

    // Aceitar exige a observacao, e ela vai para o historico do pagamento.
    await linha.getByRole('button', { name: 'Aceitar' }).click();
    await page.getByTestId('campo-observacao').fill('Pix de R$ 120,00 recebido na conta do setor, nome confere.');
    await page.getByTestId('confirmar-conferencia').click();

    await expect(page.getByTestId('aviso-da-fila')).toContainText('confirmada');
    await expect(page.locator('tbody tr').filter({ hasText: PESSOA_A.nome })).toHaveCount(0);

    // E a inscricao ficou confirmada de verdade, na ficha dela.
    await page.goto('/admin/inscricoes');
    await expect(page.getByRole('main')).toContainText(PESSOA_A.nome);
});

test('recusar com motivo deixa o participante enviar outro comprovante', async ({ page }) => {
    const pessoa: PessoaDeTeste = {
        nome: 'Clara da Segunda Tentativa',
        email: 'clara.segunda@example.com',
        telefone: '(82) 98777-1122',
        cpf: '81190000300',
        nascimento: '1995-01-20',
    };

    const urlDaCobranca = await inscreverNoSetor(page, pessoa, SETOR_A, GRUPO_A, 'Futebol');

    await page.getByTestId('campo-do-comprovante').setInputFiles(COMPROVANTE_JPEG);
    await page.getByTestId('botao-enviar-comprovante').click();
    await expect(page.getByTestId('comprovante-em-conferencia')).toBeVisible();

    // O responsavel recusa, escrevendo o motivo.
    await entrar(page, RESPONSAVEL_A);
    await page.goto('/admin/comprovantes');

    const linha = page.locator('tbody tr').filter({ hasText: pessoa.nome });

    await linha.getByRole('button', { name: 'Recusar' }).click();
    await page.getByTestId('campo-motivo').fill('O valor do comprovante não confere com o da inscrição.');
    await page.getByTestId('confirmar-conferencia').click();

    await expect(page.getByTestId('aviso-da-fila')).toContainText('recusado');

    // A pessoa volta a tela dela e le o motivo, com todas as letras.
    await page.context().clearCookies();
    await page.goto(urlDaCobranca);

    await expect(page.getByTestId('comprovante-recusado')).toContainText('não confere com o da inscrição');

    // E manda outro, que volta para a fila.
    await page.getByTestId('campo-do-comprovante').setInputFiles(COMPROVANTE_PNG);
    await page.getByTestId('botao-enviar-comprovante').click();

    await expect(page.getByTestId('comprovante-em-conferencia')).toContainText(COMPROVANTE_PNG.name);

    await entrar(page, RESPONSAVEL_A);
    await page.goto('/admin/comprovantes');
    await expect(page.locator('tbody tr').filter({ hasText: pessoa.nome })).toHaveCount(1);
});

test('o responsavel de um setor nao ve nem alcanca inscricao de outro setor', async ({ page }) => {
    await inscreverNoSetor(page, PESSOA_B, SETOR_B, GRUPO_B, 'Futebol');

    await page.getByTestId('campo-do-comprovante').setInputFiles(COMPROVANTE_JPEG);
    await page.getByTestId('botao-enviar-comprovante').click();
    await expect(page.getByTestId('comprovante-em-conferencia')).toBeVisible();

    // O identificador do comprovante do Setor B, lido de quem realmente alcanca.
    await entrar(page, RESPONSAVEL_B);
    await page.goto('/admin/comprovantes');

    const doB = page.locator('tbody tr').filter({ hasText: PESSOA_B.nome });

    await expect(doB).toHaveCount(1);

    const arquivoDeB = await doB.getByRole('link', { name: 'Abrir comprovante' }).getAttribute('href');

    expect(arquivoDeB).toBeTruthy();

    // Agora o do Setor A: ele nao ve a linha...
    await entrar(page, RESPONSAVEL_A);
    await page.goto('/admin/comprovantes');

    await expect(page.getByTestId('escopo-da-fila')).toContainText(SETOR_A);
    await expect(page.locator('tbody tr').filter({ hasText: PESSOA_B.nome })).toHaveCount(0);

    // ...e nao alcanca o arquivo pela URL direta. Nao e lista filtrada: e 403.
    const recusado = await page.request.get(arquivoDeB as string);

    expect(recusado.status()).toBe(403);

    // A lista de inscricoes tambem chega recortada.
    await page.goto('/admin/inscricoes');
    await expect(page.getByRole('main')).not.toContainText(PESSOA_B.nome);
});
