import type { Page } from '@playwright/test';
import { EVENTO_DEMO } from './ambiente';
import { artisan } from './apoio';
import { expect, test } from './base';

/**
 * A largura dos campos dos formulários do painel, medida no navegador.
 *
 * O defeito que este arquivo existe para impedir não aparece em teste de
 * comportamento nenhum: o formulário grava, o filtro filtra, e mesmo assim a
 * tela mostra um select estreito e sozinho com meia linha vazia ao lado, ou uma
 * grade de duas colunas com dois buracos. É erro de GRADE, e grade só se mede
 * onde ela é calculada — no navegador, com `boundingBox`.
 *
 * São três perguntas, e as três valem para qualquer ajuste futuro de grade:
 *
 * 1. o campo que fica sozinho na linha ocupa a linha inteira (não sobra faixa
 *    vazia ao lado dele);
 * 2. os campos que dividem a linha a cobrem de ponta a ponta — a soma das
 *    larguras mais o vão fecha a largura da grade;
 * 3. a ordem de tabulação acompanha a ordem visual, porque mexer na posição de
 *    um campo no markup é mexer também no caminho de quem navega no teclado.
 *
 * Como o `admin-avisos-pagamento.spec.ts`, este arquivo declara o próprio
 * tamanho de tela e **não** vira projeto novo no `playwright.config.ts`: são
 * telas de computador, e um projeto extra faria a suíte de celular inteira
 * rodar duas vezes só para provar uma medida.
 */

const SENHA = 'senha-de-teste-das-larguras';

const ADMINISTRADOR = 'larguras.administradora@example.com';

/**
 * A folga em pixels aceita ao comparar duas larguras que deveriam ser iguais.
 *
 * Borda, arredondamento de subpixel e a divisão de uma grade ímpar fazem duas
 * medidas "iguais" diferirem por frações. Comparar por igualdade exata daria um
 * cenário que passa numa máquina e falha na outra; 2 px é folga suficiente para
 * o arredondamento e pequena demais para esconder uma coluna vazia, que sempre
 * custa dezenas de pixels.
 */
const TOLERANCIA = 2;

/** Uma medida de tela, na forma que interessa aqui. */
interface Caixa {
    esquerda: number;
    direita: number;
    topo: number;
    largura: number;
}

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
 * Espera a tela parar de se mexer antes de medir.
 *
 * O diálogo entra com `zoom-in-95` e `slide-in-from-top`: durante os 200 ms da
 * animação, o conteúdo está ESCALADO e deslocado, e `boundingBox` devolve a
 * medida do meio do caminho — foi o que fez este arquivo acusar "Dia e Posição
 * não estão na mesma linha" com 43 px de diferença numa execução e passar na
 * seguinte. Medida de layout tirada de uma tela em movimento é sorteio.
 *
 * A corrida com o tempo existe para o caso de alguém introduzir uma animação em
 * laço (um "carregando" girando, por exemplo): `finished` de animação infinita
 * nunca resolve, e o cenário morreria no tempo limite em vez de medir.
 */
async function assentar(page: Page): Promise<void> {
    await page.evaluate(
        async () =>
            await Promise.race([
                Promise.all(document.getAnimations().map((animacao) => animacao.finished.catch(() => undefined))),
                new Promise((resolva) => setTimeout(resolva, 1000)),
            ]),
    );
}

/**
 * A caixa de um campo, pelo `id` dele.
 *
 * Mede o CAMPO, e não o `div` que o embrulha, de propósito: todo campo destes
 * formulários é filho de um `flex flex-col`, que o estica à largura do wrapper.
 * Medir o campo prova as duas coisas de uma vez — que a célula da grade tem a
 * largura certa e que o campo realmente a ocupa, em vez de ficar encolhido
 * dentro dela.
 */
async function caixaDoCampo(page: Page, id: string): Promise<Caixa> {
    await assentar(page);

    const caixa = await page.locator(`#${id}`).boundingBox();

    expect(caixa, `não achei o campo "#${id}" na tela`).not.toBeNull();

    return {
        esquerda: caixa!.x,
        direita: caixa!.x + caixa!.width,
        topo: caixa!.y,
        largura: caixa!.width,
    };
}

/**
 * Prova que os campos de uma linha a cobrem inteira, sem célula vazia.
 *
 * A conta é a única que responde à pergunta "sobrou coluna?": a soma das
 * larguras mais os vãos entre elas tem de dar a largura da linha de referência.
 * Se uma célula ficar vazia, falta o pedaço dela na soma — e a diferença é
 * grande demais para caber na tolerância.
 */
function linhaFechada(campos: Caixa[], referencia: Caixa, rotulo: string): void {
    const primeiro = campos[0];
    const ultimo = campos[campos.length - 1];

    // Todos os campos da linha estão MESMO na mesma linha.
    for (const campo of campos) {
        expect(Math.abs(campo.topo - primeiro.topo), `${rotulo}: um campo caiu para outra linha`).toBeLessThanOrEqual(TOLERANCIA);
    }

    // E a linha começa e termina onde a referência começa e termina.
    expect(Math.abs(primeiro.esquerda - referencia.esquerda), `${rotulo}: a linha começa recuada`).toBeLessThanOrEqual(TOLERANCIA);
    expect(Math.abs(ultimo.direita - referencia.direita), `${rotulo}: sobrou espaço vazio no fim da linha`).toBeLessThanOrEqual(TOLERANCIA);
}

/** Abre a lista de eventos administrativa e devolve a linha do evento de demonstração. */
async function linhaDoEventoDemo(page: Page) {
    await page.goto('/admin/eventos');

    const linha = page.getByRole('row').filter({ has: page.getByRole('rowheader', { name: EVENTO_DEMO.nome }) });

    await expect(linha).toBeVisible();

    return linha;
}

/**
 * Percorre o formulário com Tab a partir do primeiro campo e devolve os `id`
 * por onde o foco passou, na ordem.
 *
 * É o que garante que reordenar o markup não criou um caminho de teclado que
 * salta para trás: a ordem visual e a de tabulação são a mesma coisa vista de
 * dois lugares.
 */
async function ordemDeTabulacao(page: Page, primeiro: string, passos: number): Promise<string[]> {
    await page.locator(`#${primeiro}`).focus();

    const visitados = [primeiro];

    for (let passo = 1; passo < passos; passo += 1) {
        await page.keyboard.press('Tab');

        visitados.push(await page.evaluate(() => document.activeElement?.id ?? ''));
    }

    return visitados;
}

test.beforeAll(() => {
    prepararConta(ADMINISTRADOR, 'Administradora das larguras', 'administrador');
});

test.describe('em tela de computador', () => {
    test.use({
        viewport: { width: 1280, height: 900 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('no formulário de evento, a Situação ocupa a largura que a grade oferece', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);

        const linha = await linhaDoEventoDemo(page);
        await linha.getByRole('link', { name: 'Editar', exact: true }).click();

        await expect(page.getByRole('heading', { name: /Editando/, level: 1 })).toBeVisible();

        const nome = await caixaDoCampo(page, 'evento-nome');
        const endereco = await caixaDoCampo(page, 'evento-slug');
        const situacao = await caixaDoCampo(page, 'evento-situacao');

        // A Situação não está dentro da grade de duas colunas: ela é uma linha
        // inteira da seção, como a Descrição. Por isso a referência de largura é
        // a LINHA formada por Nome e Endereço — as duas metades mais o vão entre
        // elas —, e não uma das metades.
        const vao = endereco.esquerda - nome.direita;
        const linhaInteira = nome.largura + vao + endereco.largura;

        expect(Math.abs(situacao.largura - linhaInteira), 'a Situação não ocupa a largura da linha da seção').toBeLessThanOrEqual(TOLERANCIA);

        // E ela começa e termina exatamente onde a grade de cima começa e termina:
        // é isso que faz o campo parecer parte do mesmo formulário.
        linhaFechada([situacao], { ...nome, direita: endereco.direita }, 'a Situação do evento');

        // Nenhum teto de largura sobrou no caminho entre o campo e a seção.
        const tetos = await page.evaluate(() => {
            const campo = document.querySelector<HTMLElement>('#evento-situacao');
            const encontrados: string[] = [];

            let atual: HTMLElement | null = campo;

            while (atual !== null && atual.tagName !== 'SECTION') {
                const teto = getComputedStyle(atual).maxWidth;

                if (teto !== 'none') {
                    encontrados.push(`${atual.tagName}.${atual.className}: ${teto}`);
                }

                atual = atual.parentElement;
            }

            return encontrados;
        });

        expect(tetos, 'algum ancestral do campo Situação ainda limita a largura').toEqual([]);
    });

    test('no diálogo do grupo, o Nome abre a grade e Dia e Posição fecham a linha', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);

        const linha = await linhaDoEventoDemo(page);
        await linha.getByRole('link', { name: 'Programação', exact: true }).click();

        await expect(page.getByRole('heading', { name: `Programação de ${EVENTO_DEMO.nome}`, level: 1 })).toBeVisible();

        await page.getByRole('button', { name: 'Novo grupo' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();

        const nome = await caixaDoCampo(page, 'grupo-nome');
        const dia = await caixaDoCampo(page, 'grupo-dia');
        const posicao = await caixaDoCampo(page, 'grupo-posicao');

        // O Nome é o primeiro e vale pela linha toda: Dia e Posição vêm DEPOIS dele.
        expect(nome.topo, 'o Nome do grupo não é mais o primeiro campo da grade').toBeLessThan(dia.topo);

        // Dia e Posição dividem a linha seguinte — o mesmo topo prova que estão
        // lado a lado, e não empilhados.
        expect(Math.abs(dia.topo - posicao.topo), 'Dia e Posição não estão na mesma linha').toBeLessThanOrEqual(TOLERANCIA);
        expect(dia.esquerda, 'a Posição não está à direita do Dia').toBeLessThan(posicao.esquerda);

        // As duas metades cobrem a linha do Nome de ponta a ponta: nenhuma célula
        // vazia sobrou ao lado de campo nenhum.
        linhaFechada([dia, posicao], nome, 'a linha de Dia e Posição');

        const vao = posicao.esquerda - dia.direita;

        expect(Math.abs(nome.largura - (dia.largura + vao + posicao.largura)), 'o Nome não ocupa a linha inteira').toBeLessThanOrEqual(TOLERANCIA);

        // E nenhum dos dois encolheu: cada um vale por METADE da grade, que é o
        // que sobra da linha depois de tirar o vão entre as colunas. Um campo
        // menor que isso significaria espaço vazio ao lado dele.
        const meiaGrade = (nome.largura - vao) / 2;

        for (const [rotulo, campo] of [
            ['Dia', dia],
            ['Posição', posicao],
        ] as const) {
            expect(campo.largura, `o campo ${rotulo} encolheu para menos de meia grade`).toBeGreaterThanOrEqual(meiaGrade - TOLERANCIA);
        }

        // A ordem de tabulação acompanha a nova ordem visual.
        expect(await ordemDeTabulacao(page, 'grupo-nome', 3)).toEqual(['grupo-nome', 'grupo-dia', 'grupo-posicao']);
    });

    test('no diálogo da atividade, o Nome abre a grade e Grupo e Posição fecham a linha', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);

        const linha = await linhaDoEventoDemo(page);
        await linha.getByRole('link', { name: 'Programação', exact: true }).click();

        await page.getByRole('button', { name: 'Nova atividade' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();

        const nome = await caixaDoCampo(page, 'atividade-nome');
        const grupo = await caixaDoCampo(page, 'atividade-grupo');
        const posicao = await caixaDoCampo(page, 'atividade-posicao');

        expect(nome.topo, 'o Nome da atividade não é mais o primeiro campo da grade').toBeLessThan(grupo.topo);

        expect(Math.abs(grupo.topo - posicao.topo), 'Grupo e Posição não estão na mesma linha').toBeLessThanOrEqual(TOLERANCIA);
        expect(grupo.esquerda, 'a Posição não está à direita do Grupo').toBeLessThan(posicao.esquerda);

        linhaFechada([grupo, posicao], nome, 'a linha de Grupo e Posição');

        const vao = posicao.esquerda - grupo.direita;

        expect(Math.abs(nome.largura - (grupo.largura + vao + posicao.largura)), 'o Nome não ocupa a linha inteira').toBeLessThanOrEqual(TOLERANCIA);

        expect(await ordemDeTabulacao(page, 'atividade-nome', 3)).toEqual(['atividade-nome', 'atividade-grupo', 'atividade-posicao']);
    });

    test('nos filtros de avisos, os cinco campos cabem numa linha só na tela larga', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);

        await page.goto('/admin/pagamentos/avisos');
        await page.getByTestId('abrir-filtros').click();

        const campos: Caixa[] = [];

        for (const id of ['avisos-de', 'avisos-ate', 'avisos-situacao', 'avisos-gateway', 'avisos-assinatura']) {
            campos.push(await caixaDoCampo(page, id));
        }

        // Em `lg` a grade tem cinco colunas para cinco campos: o `lg:col-span-1`
        // do Provedor desfaz o alargamento que só vale na faixa do meio. Se ele
        // tivesse ficado com `col-span-2`, o quinto campo desceria de linha —
        // por isso a prova é o topo comum aos cinco.
        linhaFechada(campos, { ...campos[0], direita: campos[campos.length - 1].direita }, 'os filtros de avisos em tela larga');

        // E as cinco colunas têm a mesma largura: nenhuma engoliu a vizinha.
        for (const campo of campos) {
            expect(Math.abs(campo.largura - campos[0].largura), 'um filtro ficou mais largo que os outros em tela larga').toBeLessThanOrEqual(
                TOLERANCIA,
            );
        }
    });
});

test.describe('na faixa intermediária, onde a grade dos avisos tem três colunas', () => {
    /*
     * 900 px, e não 768: em Chromium a barra de rolagem come alguns pixels da
     * largura de layout, e uma janela de exatamente 768 cairia para baixo do
     * ponto de corte do `md:` — o cenário mediria a grade de uma coluna só e
     * passaria por engano. Qualquer largura entre 768 e 1023 serve; o próprio
     * cenário confere em que faixa acabou caindo antes de medir.
     */
    test.use({
        viewport: { width: 900, height: 900 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('a segunda linha dos filtros de avisos não deixa coluna vazia', async ({ page }) => {
        await entrar(page, ADMINISTRADOR);

        await page.goto('/admin/pagamentos/avisos');
        await page.getByTestId('abrir-filtros').click();

        // A medida só vale se a página realmente estiver na faixa `md`.
        const larguraDeLayout = await page.evaluate(() => document.documentElement.clientWidth);

        expect(larguraDeLayout, 'a janela não caiu na faixa `md` (768–1023 px)').toBeGreaterThanOrEqual(768);
        expect(larguraDeLayout).toBeLessThan(1024);

        const de = await caixaDoCampo(page, 'avisos-de');
        const ate = await caixaDoCampo(page, 'avisos-ate');
        const situacao = await caixaDoCampo(page, 'avisos-situacao');
        const provedor = await caixaDoCampo(page, 'avisos-gateway');
        const assinatura = await caixaDoCampo(page, 'avisos-assinatura');

        // Primeira linha: as três primeiras colunas, que já estavam certas.
        linhaFechada([de, ate, situacao], { ...de, direita: situacao.direita }, 'a primeira linha dos filtros');

        // Segunda linha: Provedor e Assinatura, e mais nada.
        expect(provedor.topo, 'o Provedor não desceu para a segunda linha').toBeGreaterThan(de.topo);

        linhaFechada([provedor, assinatura], { ...de, direita: situacao.direita }, 'a segunda linha dos filtros');

        // O Provedor vale por duas colunas: é ele que absorve a coluna que sobrava.
        const vao = ate.esquerda - de.direita;

        expect(Math.abs(provedor.largura - (de.largura + vao + ate.largura)), 'o Provedor não ocupa duas das três colunas').toBeLessThanOrEqual(
            TOLERANCIA,
        );
    });
});
