import { expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { ambienteDeTeste, EVENTO_DEMO } from './ambiente';

/**
 * Apoio comum dos cenarios de ponta a ponta.
 *
 * Nada aqui conhece regra de negocio: sao os gestos que uma pessoa faria na
 * tela, escritos uma vez so, mais dois atalhos para falar com o servidor
 * (semear o banco e descobrir o identificador da cobranca no provedor
 * simulado) que nao existem no navegador.
 */

/**
 * A razao de contraste entre o texto de um elemento e o fundo atras dele,
 * pela formula da WCAG. Abaixo de 4,5 o texto comum reprova.
 *
 * Ela mede NO NAVEGADOR, e nao no arquivo de estilo: le a cor calculada do
 * elemento, sobe pelos ancestrais ate achar o primeiro fundo realmente pintado
 * (cor transparente herda de quem esta atras) e faz a conta. E por isso que os
 * tokens do projeto estao em hexadecimal e nao em `oklch()` — o comentario no
 * topo do `resources/css/app.css` conta essa historia.
 *
 * Vive aqui, e nao dentro de um cenario, porque tres arquivos ja precisavam
 * dela: a home, a lista administrativa e o que vier. Copia de funcao de medida
 * e o jeito mais rapido de duas telas passarem a medir coisas diferentes.
 *
 * O seletor e uma string de CSS de proposito: a funcao inteira roda dentro da
 * pagina, e um `Locator` do Playwright nao atravessa essa fronteira.
 */
export async function contraste(page: Page, seletor: string): Promise<number> {
    return page.evaluate((alvo) => {
        const elemento = document.querySelector<HTMLElement>(alvo);

        if (elemento === null) {
            return 0;
        }

        const canais = (cor: string): number[] => (cor.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);

        const luminancia = (cor: string): number => {
            const [r, g, b] = canais(cor).map((canal) => {
                const parte = canal / 255;

                return parte <= 0.03928 ? parte / 12.92 : ((parte + 0.055) / 1.055) ** 2.4;
            });

            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };

        /** O fundo pintado mais proximo: cor transparente herda de quem esta atras. */
        const fundo = (de: HTMLElement): string => {
            let atual: HTMLElement | null = de;

            while (atual !== null) {
                const cor = getComputedStyle(atual).backgroundColor;
                const alfa = canais(cor).length === 3 ? (cor.match(/[\d.]+/g) ?? [])[3] : undefined;

                if (cor !== 'rgba(0, 0, 0, 0)' && alfa !== '0') {
                    return cor;
                }

                atual = atual.parentElement;
            }

            return 'rgb(255, 255, 255)';
        };

        const claro = Math.max(luminancia(getComputedStyle(elemento).color), luminancia(fundo(elemento)));
        const escuro = Math.min(luminancia(getComputedStyle(elemento).color), luminancia(fundo(elemento)));

        return (claro + 0.05) / (escuro + 0.05);
    }, seletor);
}

/** Roda um comando artisan no mesmo ambiente do servidor de teste. */
export function artisan(argumentos: string[]): string {
    return execFileSync('php', ['artisan', '--no-interaction', ...argumentos], {
        env: { ...process.env, ...ambienteDeTeste },
        encoding: 'utf-8',
        stdio: ['ignore', 'pipe', 'inherit'],
    });
}

/**
 * Banco do zero com o catalogo real de setores e o evento de demonstracao.
 *
 * Sempre o mesmo estado inicial: nenhum cenario depende do que outro deixou
 * para tras.
 */
export function semearBanco(): void {
    artisan(['migrate:fresh', '--seed', '--force']);
}

/** Dados de uma pessoa fictícia. Cada cenario usa o seu, para nao disputar CPF. */
export interface PessoaDeTeste {
    nome: string;
    email: string;
    telefone: string;
    cpf: string;
    /** Em ISO (AAAA-MM-DD), como cada cenario a escreve. */
    nascimento: string;
}

/**
 * A data como a TELA a escreve: dd/mm/aaaa.
 *
 * O campo de nascimento deixou de ser o seletor nativo do navegador e passou a
 * ser digitavel, com a mascara do desenho. Os cenarios continuam guardando a
 * data em ISO — e o formato do dominio, e e ele que aparece nas asseveracoes —
 * e a conversao mora aqui, num lugar so: mudar o formato da tela nao pode
 * obrigar a reescrever dezoito arquivos de cenario.
 */
export function dataComoNaTela(iso: string): string {
    const [ano, mes, dia] = iso.split('-');

    return `${dia}/${mes}/${ano}`;
}

/**
 * Preenche o passo 1 (dados pessoais) e segue para o passo 2.
 */
export async function preencherDadosPessoais(page: Page, pessoa: PessoaDeTeste): Promise<void> {
    await page.getByLabel('Nome completo').fill(pessoa.nome);
    await page.getByLabel('E-mail').fill(pessoa.email);
    await page.getByLabel('Telefone com DDD').fill(pessoa.telefone);
    await page.getByLabel('CPF').fill(pessoa.cpf);
    await page.getByLabel('Data de nascimento').fill(dataComoNaTela(pessoa.nascimento));

    // Dado real do catalogo. O grupo tem PARENTESE de proposito: e o que
    // quebraria um seletor mal escrito, e e o nome que a comunidade usa.
    await escolherNaLista(page, 'Setor', 'Setor Batalha');
    await escolherNaLista(page, 'Grupo', 'Batalha (Sede)');
}

/**
 * As listas de escolha sao do radix-vue: abrem um painel proprio em vez do
 * `<select>` do sistema. O gesto e o mesmo — tocar e escolher.
 */
export async function escolherNaLista(page: Page, rotulo: string, opcao: string): Promise<void> {
    await page.getByLabel(rotulo, { exact: true }).click();
    await page.getByRole('option', { name: opcao, exact: true }).click();
}

/** Marca uma atividade tocando no cartao inteiro, como quem usa o celular. */
export async function escolherAtividade(page: Page, nome: string): Promise<void> {
    await page.locator('label').filter({ hasText: nome }).first().click();
}

/** O codigo publico da inscricao, lido do endereco da tela de cobranca. */
export function codigoDaInscricao(url: string): string {
    const encontrado = /\/inscricoes\/([^/]+)\/pagamento/.exec(url);

    expect(encontrado, `nao encontrei o codigo da inscricao em ${url}`).not.toBeNull();

    return encontrado![1];
}

/**
 * O identificador da cobranca no provedor simulado.
 *
 * O navegador nao tem — e nem deveria ter — esse dado: ele e da conversa entre
 * o sistema e a instituicao financeira. Perguntamos ao banco de dados, do lado
 * de fora, exatamente como faria quem opera o sistema.
 */
export function idExternoDaCobranca(codigoPublico: string): string {
    const saida = artisan([
        'tinker',
        '--execute',
        `echo \\App\\Models\\Pagamento::query()->whereHas('inscricao', fn ($consulta) => $consulta->where('codigo_publico', '${codigoPublico}'))->value('id_externo');`,
    ]);

    const idExterno = /fake_[a-z0-9]+/.exec(saida)?.[0];

    expect(idExterno, `nao encontrei a cobranca da inscricao ${codigoPublico}: ${saida}`).toBeTruthy();

    return idExterno as string;
}

/**
 * Muda a capacidade de uma atividade direto no banco.
 *
 * Serve para montar o cenario de "esgotado" sem precisar inventar dezenas de
 * inscricoes: capacidade zero e o mesmo que nao ter mais vaga. Quem usa deve
 * devolver o valor original no fim, para nao contaminar os outros cenarios.
 */
export function definirCapacidadeDaAtividade(nome: string, capacidade: number | null): void {
    const valor = capacidade === null ? 'null' : String(capacidade);

    artisan(['tinker', '--execute', `\\App\\Models\\Atividade::query()->where('nome', '${nome}')->update(['capacidade' => ${valor}]);`]);
}

/** O que sobra de uma inscricao recem-feita, do ponto de vista de quem a fez. */
export interface InscricaoDeTeste {
    /** O codigo publico, lido do endereco da cobranca. */
    codigo: string;
    /** O endereco assinado da tela de cobranca. */
    urlDaCobranca: string;
    /** O endereco assinado da pagina de acompanhamento, como a tela oferece. */
    urlDoAcompanhamento: string;
}

/**
 * Percorre o formulario inteiro e devolve a inscricao pronta.
 *
 * E o mesmo caminho dos cenarios da vitrine, feito com os mesmos gestos: os
 * cenarios do participante comecam depois disso, e nao teriam por que
 * repetir o preenchimento passo a passo.
 */
export async function inscreverPessoa(page: Page, pessoa: PessoaDeTeste, atividade: string): Promise<InscricaoDeTeste> {
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);

    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await escolherAtividade(page, atividade);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);
    await expect(page.getByTestId('cobranca-aguardando')).toBeVisible();

    const urlDaCobranca = page.url();

    // O link do acompanhamento nasce assinado no servidor: e assim que o
    // participante chega a propria pagina, e e assim que o teste chega tambem.
    const acompanhamento = await page.getByTestId('link-acompanhamento').getAttribute('href');

    expect(acompanhamento, 'a tela da cobranca precisa oferecer o link do acompanhamento').toBeTruthy();

    return {
        codigo: codigoDaInscricao(urlDaCobranca),
        urlDaCobranca,
        urlDoAcompanhamento: acompanhamento as string,
    };
}
