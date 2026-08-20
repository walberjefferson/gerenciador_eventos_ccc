import { expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { ambienteDeTeste } from './ambiente';

/**
 * Apoio comum dos cenarios de ponta a ponta.
 *
 * Nada aqui conhece regra de negocio: sao os gestos que uma pessoa faria na
 * tela, escritos uma vez so, mais dois atalhos para falar com o servidor
 * (semear o banco e descobrir o identificador da cobranca no provedor
 * simulado) que nao existem no navegador.
 */

/** Roda um comando artisan no mesmo ambiente do servidor de teste. */
export function artisan(argumentos: string[]): string {
    return execFileSync('php', ['artisan', '--no-interaction', ...argumentos], {
        env: { ...process.env, ...ambienteDeTeste },
        encoding: 'utf-8',
        stdio: ['ignore', 'pipe', 'inherit'],
    });
}

/**
 * Banco do zero com o catalogo de cidades e o evento de demonstracao.
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
    nascimento: string;
}

/**
 * Preenche o passo 1 (dados pessoais) e segue para o passo 2.
 */
export async function preencherDadosPessoais(page: Page, pessoa: PessoaDeTeste): Promise<void> {
    await page.getByLabel('Nome completo').fill(pessoa.nome);
    await page.getByLabel('E-mail').fill(pessoa.email);
    await page.getByLabel('Telefone com DDD').fill(pessoa.telefone);
    await page.getByLabel('CPF').fill(pessoa.cpf);
    await page.getByLabel('Data de nascimento').fill(pessoa.nascimento);

    await escolherNaLista(page, 'Cidade', 'São Paulo (SP)');
    await escolherNaLista(page, 'Grupo', 'Centro');
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
