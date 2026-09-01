import { devices, type Page } from '@playwright/test';
import { URL_BASE } from './ambiente';
import { artisan, contraste, inscreverPessoa, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * O desenho das listagens administrativas, medido no navegador.
 *
 * Os outros cenarios do painel provam que a lista ACHA a pessoa certa. Este
 * prova que ela pode ser LIDA: que a situacao chega como etiqueta com a palavra
 * escrita, que duas situacoes diferentes chegam com cores diferentes, que
 * nenhum par texto/fundo introduzido fica abaixo do minimo da WCAG e que o
 * botao de acao da linha e grande o bastante para um dedo.
 *
 * Tudo aqui e medido com a cor CALCULADA pelo navegador, e nao lida do arquivo
 * de estilo. E a unica forma de pegar o erro que interessa: o token esta certo,
 * a classe existe, e mesmo assim a etiqueta saiu cinza porque outra regra
 * venceu na cascata.
 *
 * O caminho de exclusao da lista de eventos entra por outra razao: cor por
 * intencao so vale se a acao continuar alcancavel por quem nao usa o mouse.
 */

const SENHA = 'senha-de-teste-das-listagens';

const ADMINISTRADOR = 'listagens.administradora@example.com';

/** A atividade que a primeira escolhe. Nada aqui depende de qual e. */
const ATIVIDADE = 'Handebol';

/** Fica aguardando pagamento: a etiqueta dela e a de ATENCAO, o relogio correndo. */
const AGUARDANDO: PessoaDeTeste = {
    nome: 'Aurora Etiqueta Prado',
    email: 'aurora.etiqueta@example.com',
    telefone: '(11) 96666-1010',
    // CPF proprio deste arquivo. A regra do dominio recusa duas inscricoes
    // ativas com o mesmo CPF no mesmo evento, entao dois cenarios que
    // compartilhem numero derrubam um ao outro — foi o que aconteceu com o
    // conciliacao-por-txid.spec.ts antes desta linha ficar unica.
    cpf: '50520531183',
    nascimento: '1991-04-17',
};

/** Nasce ja confirmada, direto no banco: a etiqueta dela e a de SUCESSO. */
const CONFIRMADA = {
    nome: 'Bento Etiqueta Prado',
    email: 'bento.etiqueta@example.com',
};

/** O sobrenome que reduz a lista as duas linhas deste arquivo. */
const SOBRENOME = 'Etiqueta Prado';

/** O evento descartavel do cenario de teclado: nasce sem inscricao para poder morrer. */
const EVENTO_DESCARTAVEL = 'Evento Descartavel das Etiquetas';

/**
 * A tabela da tela administrativa aberta.
 *
 * A ancora e o `id` do conteudo do AdminLayout — o mesmo que o link "Pular para
 * o conteudo" usa. Ele existe em toda tela do painel e nao e um `data-testid`
 * inventado para o teste.
 */
const TABELA = '#conteudo-administrativo table';

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
 * A segunda inscricao nasce no banco, e nao pelo formulario.
 *
 * O que este arquivo precisa e de DUAS situacoes diferentes lado a lado, e
 * percorrer o formulario de novo so para depois confirmar um pagamento seria
 * atravessar o ciclo do dinheiro inteiro para provar uma cor.
 */
function criarInscricaoConfirmada(): void {
    artisan([
        'tinker',
        '--execute',
        `$evento = \\App\\Models\\Evento::query()->where('slug', 'copa-ccc-2026')->firstOrFail();` +
            `$grupo = \\App\\Models\\GrupoParticipante::query()->firstOrFail();` +
            `\\App\\Models\\Inscricao::factory()->confirmada()->create([` +
            `'evento_id' => $evento->id,` +
            `'grupo_participante_id' => $grupo->id,` +
            `'nome_completo' => '${CONFIRMADA.nome}',` +
            `'email' => '${CONFIRMADA.email}',` +
            `]);`,
    ]);
}

/** Um evento sem nenhuma inscricao — o unico que a tela deixa excluir. */
function criarEventoDescartavel(): void {
    artisan([
        'tinker',
        '--execute',
        `\\App\\Models\\Evento::factory()->create([` + `'nome' => '${EVENTO_DESCARTAVEL}',` + `'slug' => 'evento-descartavel-das-etiquetas',` + `]);`,
    ]);
}

/** Abre a lista de inscricoes reduzida as duas pessoas deste arquivo. */
async function abrirListaDasDuas(page: Page): Promise<void> {
    await page.goto('/admin/inscricoes');

    // O painel de filtros comeca recolhido: abrir e o primeiro gesto de quem filtra.
    await page.getByTestId('abrir-filtros').click();
    await page.getByLabel('Buscar').fill(SOBRENOME);
    await page.getByRole('button', { name: 'Filtrar' }).click();

    await expect(page.locator(`${TABELA} tbody tr`)).toHaveCount(2);
}

/**
 * A posicao (1 em diante) da linha cujo cabecalho traz este nome.
 *
 * Os seletores de medida precisam de `nth-child`, e a ordem das linhas e do
 * servidor: fixar "e a ultima" seria escrever um cenario que passa hoje e
 * quebra quando alguem mudar a ordenacao da consulta.
 */
async function posicaoDaLinha(page: Page, nome: string): Promise<number> {
    const posicao = await page.evaluate(
        ({ tabela, procurado }) => {
            const linhas = Array.from(document.querySelectorAll(`${tabela} tbody tr`));

            return linhas.findIndex((linha) => (linha.querySelector('th')?.textContent ?? '').trim() === procurado) + 1;
        },
        { tabela: TABELA, procurado: nome },
    );

    expect(posicao, `nao achei a linha de "${nome}" na tabela`).toBeGreaterThan(0);

    return posicao;
}

/**
 * A altura minima que um botao de acao de LINHA precisa ter.
 *
 * As listagens do painel usam `tamanho="sm"` do BotaoDeAcao: 36 px, a altura do
 * `size: default` do Button do projeto. Nao e responsivo — e o mesmo botao no
 * celular e no computador.
 *
 * ISSO E UM DESVIO CONSCIENTE dos 44 px que o projeto cobra como alvo de toque,
 * e ele vale so aqui: o painel e operado no computador, com ponteiro, e numa
 * tabela densa o botao de 44 px empurra a linha para baixo ate a lista nao
 * caber. As telas publicas de inscricao, que sao mobile-first de verdade e onde
 * o dedo e o unico apontador, nao usam este componente — elas usam o Button, e
 * la os 44 px continuam cobrados pelos cenarios de sempre.
 *
 * O numero segue medido, e nao apenas assumido: 36 px fica bem acima do minimo
 * de 24 px que a WCAG 2.2 AA (2.5.8) exige, e o cenario falha se alguem baixar
 * o tamanho da listagem sem pensar.
 */
const ALTURA_MINIMA_DA_LINHA = 36;

/**
 * A cor de fundo realmente pintada de um elemento, como o navegador a calculou.
 *
 * Serve a pergunta "a cor esta MESMO variando?": duas etiquetas de situacoes
 * diferentes precisam devolver strings diferentes daqui. Se alguem trocar o
 * mapeamento por uma classe fixa, as duas passam a coincidir e o cenario cai.
 */
async function corDeFundo(page: Page, seletor: string): Promise<string> {
    return page.evaluate((alvo) => {
        const elemento = document.querySelector<HTMLElement>(alvo);

        return elemento === null ? '' : getComputedStyle(elemento).backgroundColor;
    }, seletor);
}

/**
 * O seletor da etiqueta de uma coluna, numa linha.
 *
 * `nth-child` conta o cabecalho de linha (`th`) junto, entao na lista de
 * inscricoes a quinta celula e a Situacao e a sexta e a Cobranca. A etiqueta e
 * o `div` que a `Badge` desenha dentro da celula; quando nao ha cobranca, a
 * celula traz um `span` com o travessao e nao casa com este seletor — que e
 * exatamente o que se quer.
 */
function etiquetaNaLinha(linha: number, coluna: number): string {
    return `${TABELA} tbody tr:nth-child(${linha}) td:nth-child(${coluna}) > div`;
}

test.beforeAll(async ({ browser }) => {
    prepararConta(ADMINISTRADOR, 'Administradora das listagens', 'administrador');

    /*
     * Esta pagina nasce fora da fixture do `base.ts`, entao a tesoura na rede
     * externa precisa ser posta na mao — como em admin-inscricoes.spec.ts. Sem
     * ela, o preparo esperaria por uma fonte hospedada fora e a suite passaria
     * a depender de internet.
     */
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

    const pagina = await janela.newPage();

    await inscreverPessoa(pagina, AGUARDANDO, ATIVIDADE);

    await janela.close();

    criarInscricaoConfirmada();
    criarEventoDescartavel();
});

test('a situacao e a cobranca chegam como etiqueta, com a palavra escrita', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await abrirListaDasDuas(page);

    // A palavra continua escrita dentro da etiqueta: a cor reforca, nunca
    // substitui (WCAG 1.4.1). Quem ouve a tela ouve o mesmo que se le.
    await expect(page.getByRole('cell', { name: 'Aguardando pagamento', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Confirmada', exact: true })).toBeVisible();

    // Nenhuma etiqueta virou so bolinha: as duas linhas trazem texto.
    const situacoes = await page.locator(`${TABELA} tbody tr td:nth-child(5) > div`).allInnerTexts();

    expect(situacoes).toHaveLength(2);
    expect(situacoes.map((texto) => texto.trim()).sort()).toEqual(['Aguardando pagamento', 'Confirmada']);

    // A cobranca da inscricao que passou pelo formulario existe e tambem e
    // etiqueta; a que nasceu confirmada no banco nao tem cobranca, e a celula
    // dela mostra o travessao em vez de inventar um estado.
    const cobrancas = await page.locator(`${TABELA} tbody tr td:nth-child(6)`).allInnerTexts();

    expect(cobrancas.map((texto) => texto.trim()).sort()).toEqual(['Aguardando pagamento', '—']);
});

test('duas situacoes diferentes rendem etiquetas de cores diferentes', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await abrirListaDasDuas(page);

    const primeira = await corDeFundo(page, etiquetaNaLinha(1, 5));
    const segunda = await corDeFundo(page, etiquetaNaLinha(2, 5));

    expect(primeira, 'a etiqueta da primeira linha precisa ter fundo pintado').not.toBe('');
    expect(primeira).not.toBe('rgba(0, 0, 0, 0)');

    // A prova de que a cor esta mesmo saindo do mapeamento, e nao de uma classe
    // fixa que pinta todas as linhas igual.
    expect(segunda, 'as duas situacoes sairam com o mesmo fundo — a cor virou enfeite').not.toBe(primeira);
});

test('toda etiqueta da lista de inscricoes passa em 4.5:1, medida no navegador', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await abrirListaDasDuas(page);

    for (const linha of [1, 2]) {
        for (const coluna of [5, 6]) {
            const seletor = etiquetaNaLinha(linha, coluna);

            // A celula da cobranca vazia nao tem etiqueta: nao ha o que medir.
            if ((await page.locator(seletor).count()) === 0) {
                continue;
            }

            const razao = await contraste(page, seletor);

            expect(razao, `a etiqueta da linha ${linha}, coluna ${coluna}, rendeu ${razao.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);
        }
    }
});

test('o botao de acao da linha tem contraste e alvo de dedo na lista de inscricoes', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await abrirListaDasDuas(page);

    for (const linha of [1, 2]) {
        const seletor = `${TABELA} tbody tr:nth-child(${linha}) td:nth-child(9) a`;

        await expect(page.locator(seletor)).toBeVisible();

        const razao = await contraste(page, seletor);

        expect(razao, `o botao "Abrir" da linha ${linha} rendeu ${razao.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);

        // Alvo de acionamento do botao de linha — ver ALTURA_MINIMA_DA_LINHA.
        const minima = ALTURA_MINIMA_DA_LINHA;
        const caixa = await page.locator(seletor).boundingBox();

        expect(caixa?.height ?? 0, `o botao "Abrir" da linha ${linha} ficou menor que ${minima} px`).toBeGreaterThanOrEqual(minima);
    }
});

test('os tres botoes da lista de eventos tem contraste e a altura do aparelho', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await page.goto('/admin/eventos');

    const linha = page.getByRole('row').filter({ has: page.getByRole('rowheader', { name: EVENTO_DESCARTAVEL }) });

    await expect(linha).toBeVisible();

    // A linha do evento descartavel e a unica que oferece os tres: as demais ja
    // receberam inscricao e por isso nem mostram o botao de excluir.
    for (const nome of ['Editar', 'Programação', 'Excluir']) {
        const botao = linha.getByRole(nome === 'Excluir' ? 'button' : 'link', { name: nome, exact: true });

        await expect(botao).toBeVisible();

        const minima = ALTURA_MINIMA_DA_LINHA;
        const caixa = await botao.boundingBox();

        expect(caixa?.height ?? 0, `o botao "${nome}" ficou menor que ${minima} px`).toBeGreaterThanOrEqual(minima);
    }

    // O contraste do texto, com a cor calculada pelo navegador. As tres
    // intencoes tem tons diferentes, e nenhuma pode ficar abaixo do minimo.
    const posicao = await posicaoDaLinha(page, EVENTO_DESCARTAVEL);
    const acoes = `${TABELA} tbody tr:nth-child(${posicao}) td:nth-child(7)`;

    for (const seletor of [`${acoes} a:nth-of-type(1)`, `${acoes} a:nth-of-type(2)`, `${acoes} button`]) {
        const razao = await contraste(page, seletor);

        expect(razao, `${seletor} rendeu ${razao.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);
    }

    // E a etiqueta de situacao do evento tambem: e a mesma coluna que a lista
    // de inscricoes tem, so que com outro mapa por tras.
    const situacao = await contraste(page, `${TABELA} tbody tr:nth-child(${posicao}) td:nth-child(3) > div`);

    expect(situacao, `a etiqueta de situacao do evento rendeu ${situacao.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);
});

test('o caminho de exclusao de um evento e percorrivel so pelo teclado, com foco a vista', async ({ page }) => {
    await entrar(page, ADMINISTRADOR);
    await page.goto('/admin/eventos');

    const linha = page.getByRole('row').filter({ has: page.getByRole('rowheader', { name: EVENTO_DESCARTAVEL }) });

    await expect(linha).toBeVisible();

    /** O anel de foco tem de ser VISTO: foco sem marca e o mesmo que sem foco. */
    const anelDeFoco = async (): Promise<boolean> =>
        page.evaluate(() => {
            const elemento = document.activeElement as HTMLElement | null;

            if (elemento === null || elemento === document.body) {
                return false;
            }

            const estilo = getComputedStyle(elemento);

            return estilo.outlineStyle !== 'none' || estilo.boxShadow !== 'none';
        });

    /** Anda de Tab em Tab ate parar no botao pedido. Se nao chegar, ele nao e alcancavel. */
    const tabularAte = async (nome: string): Promise<void> => {
        for (let passo = 0; passo < 120; passo += 1) {
            const parou = await page.evaluate((procurado) => (document.activeElement?.textContent ?? '').trim() === procurado, nome);

            if (parou) {
                return;
            }

            await page.keyboard.press('Tab');
        }

        throw new Error(`nao cheguei ao botao "${nome}" so com Tab`);
    };

    // Do topo do documento ate o botao de excluir da linha, sem tocar no mouse.
    await tabularAte('Excluir');

    expect(await anelDeFoco(), 'o botao "Excluir" recebe o foco sem mostrar').toBe(true);

    // Enter no botao: a confirmacao aparece na propria linha.
    await page.keyboard.press('Enter');

    await expect(linha.getByText('Excluir mesmo?')).toBeVisible();

    // E a confirmacao tambem e alcancavel so com Tab.
    await tabularAte('Sim, excluir');

    expect(await anelDeFoco(), 'o botao "Sim, excluir" recebe o foco sem mostrar').toBe(true);

    await page.keyboard.press('Enter');

    // O evento sai da lista, e quem percorreu o caminho nunca precisou do mouse.
    await expect(page.getByRole('rowheader', { name: EVENTO_DESCARTAVEL })).toHaveCount(0);
});
