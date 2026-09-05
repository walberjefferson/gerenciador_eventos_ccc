import { EVENTO_DEMO } from './ambiente';
import { artisan, escolherAtividade, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * Inscricao por lote, no navegador.
 *
 * O lote e um degrau de preco: ele vale ate uma data, ate acabarem as vagas
 * dele, ou ate o que vier primeiro. Quatro coisas precisam ficar provadas na
 * tela, e nao apenas no dominio:
 *
 * 1. a pessoa VE a sucessao inteira — de onde o preco veio e para onde ele vai;
 * 2. so o lote em vigor pode ser escolhido, e os outros nao sao apenas cinzas:
 *    sao controles desabilitados de verdade, para quem usa teclado e leitor;
 * 3. quando o lote vira no meio do preenchimento, a pessoa LE o que mudou em
 *    vez de descobrir um preco diferente na tela da cobranca;
 * 4. sem lote em vigor, o evento nao oferece inscricao — e explica por que.
 *
 * OS LOTES SAO MONTADOS AQUI, e nao no semeador: o evento de demonstracao nao
 * trabalha com lotes, e e assim que os outros cenarios o conhecem. Cada teste
 * monta a sucessao de que precisa e o `afterAll` devolve o evento ao estado de
 * antes — inclusive soltando o lote das inscricoes criadas pelo caminho, que a
 * chave estrangeira e "restrict" de proposito.
 */

interface LoteDeTeste {
    nome: string;
    posicao: number;
    valor: number;
    /** "AAAA-MM-DD HH:MM" ou nulo quando o lote so encerra por vagas. */
    ate?: string | null;
    quantidade?: number | null;
    ocupadas?: number;
}

/** Refaz a sucessao de lotes do evento de demonstracao, do zero. */
function definirLotes(lotes: LoteDeTeste[]): void {
    const dados = JSON.stringify(lotes);

    artisan([
        'tinker',
        '--execute',
        `$evento = \\App\\Models\\Evento::query()->where('slug', '${EVENTO_DEMO.slug}')->firstOrFail();
         \\Illuminate\\Support\\Facades\\DB::table('inscricoes')->where('evento_id', $evento->id)->update(['lote_id' => null]);
         \\Illuminate\\Support\\Facades\\DB::table('lotes')->where('evento_id', $evento->id)->delete();
         foreach (json_decode('${dados}', true) as $lote) {
             \\Illuminate\\Support\\Facades\\DB::table('lotes')->insert([
                 'evento_id' => $evento->id,
                 'nome' => $lote['nome'],
                 'posicao' => $lote['posicao'],
                 'valor_centavos' => $lote['valor'],
                 'disponivel_ate' => $lote['ate'] ?? null,
                 'quantidade' => $lote['quantidade'] ?? null,
                 'vagas_ocupadas' => $lote['ocupadas'] ?? 0,
                 'created_at' => now(),
                 'updated_at' => now(),
             ]);
         }`,
    ]);
}

/** Toma as vagas que faltam de um lote, como fariam outras pessoas. */
function esgotarLote(nome: string): void {
    artisan([
        'tinker',
        '--execute',
        `\\Illuminate\\Support\\Facades\\DB::statement("UPDATE lotes SET vagas_ocupadas = quantidade WHERE nome = '${nome}'");`,
    ]);
}

const pessoa: PessoaDeTeste = {
    nome: 'Vera Lúcia Antunes',
    email: 'vera.antunes@example.com',
    telefone: '(11) 93333-2222',
    cpf: '15350946056',
    nascimento: '1979-04-22',
};

const outraPessoa: PessoaDeTeste = {
    nome: 'Otávio Prado Lima',
    email: 'otavio.prado@example.com',
    telefone: '(11) 93333-1111',
    cpf: '40364836091',
    nascimento: '1988-11-03',
};

test.afterAll(() => definirLotes([]));

test('a vitrine mostra a sucessão de lotes e a inscrição sai pelo lote em vigor', async ({ page }) => {
    definirLotes([
        { nome: '1º lote', posicao: 1, valor: 9000, ate: '2026-08-10 23:59' },
        { nome: '2º lote', posicao: 2, valor: 11000, quantidade: 50 },
        { nome: '3º lote', posicao: 3, valor: 14000, quantidade: 50 },
    ]);

    await page.goto(`/eventos/${EVENTO_DEMO.slug}`);

    const lista = page.getByTestId('lista-de-lotes');
    await expect(lista).toBeVisible();

    // Os tres aparecem, cada um com o seu preco e a sua situacao escrita.
    await expect(lista.getByText('1º lote')).toBeVisible();
    await expect(lista.getByText('R$ 90,00')).toBeVisible();
    await expect(lista.getByText('Encerrado', { exact: true })).toBeVisible();
    await expect(lista.getByText('R$ 110,00')).toBeVisible();
    await expect(lista.getByText('Lote atual', { exact: true })).toBeVisible();
    await expect(lista.getByText('R$ 140,00')).toBeVisible();
    await expect(lista.getByText('Em breve', { exact: true })).toBeVisible();

    // O painel de compra mostra o preco do lote em vigor, e nao o do evento.
    await expect(page.getByTestId('lote-vigente-no-painel')).toContainText('2º lote');
    await expect(page.getByText('R$ 110,00').first()).toBeVisible();

    // E a inscricao sai por ele, ate a tela da cobranca.
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);
    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    await escolherAtividade(page, 'Futebol');
    await page.getByRole('button', { name: 'Continuar' }).click();

    await expect(page.getByTestId('lote-na-revisao')).toContainText('2º lote');

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);
    await expect(page.getByTestId('valor-da-cobranca')).toHaveText('R$ 110,00');
});

test('o lote encerrado e o futuro aparecem, e nenhum dos dois pode ser escolhido', async ({ page }) => {
    definirLotes([
        { nome: '1º lote', posicao: 1, valor: 9000, ate: '2026-08-10 23:59' },
        { nome: '2º lote', posicao: 2, valor: 11000, quantidade: 50 },
        { nome: '3º lote', posicao: 3, valor: 14000, quantidade: 50 },
    ]);

    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);
    await preencherDadosPessoais(page, pessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    const lista = page.getByTestId('lista-de-lotes');
    await expect(lista).toBeVisible();

    const escolhas = lista.locator('input[type="radio"]');
    await expect(escolhas).toHaveCount(3);

    // O que ja passou e o que ainda vem sao controles DESABILITADOS: quem usa
    // teclado ou leitor de tela ouve que nao dao para marcar, em vez de tentar.
    await expect(escolhas.nth(0)).toBeDisabled();
    await expect(escolhas.nth(1)).toBeEnabled();
    await expect(escolhas.nth(2)).toBeDisabled();

    // O lote em vigor ja vem marcado: nao ha escolha a fazer, ha um fato a ler.
    await expect(escolhas.nth(1)).toBeChecked();

    // A linha inteira do lote encerrado esta anunciada como indisponivel.
    await expect(lista.getByTestId('lote-encerrado')).toHaveAttribute('aria-disabled', 'true');
    await expect(lista.getByTestId('lote-futuro')).toHaveAttribute('aria-disabled', 'true');

    // Tocar na linha do lote futuro, como se faz no celular, nao muda nada.
    await lista.getByTestId('lote-futuro').click({ force: true });
    await expect(escolhas.nth(2)).not.toBeChecked();
    await expect(escolhas.nth(1)).toBeChecked();
});

test('o lote que esgota durante o preenchimento é recusado com a explicação do que mudou', async ({ page }) => {
    definirLotes([
        { nome: '1º lote', posicao: 1, valor: 9000, quantidade: 1 },
        { nome: '2º lote', posicao: 2, valor: 15000, quantidade: 50 },
    ]);

    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);
    await preencherDadosPessoais(page, outraPessoa);
    await page.getByRole('button', { name: 'Continuar' }).click();

    // A pessoa viu o 1o lote, por R$ 90,00.
    await expect(page.getByTestId('lista-de-lotes').getByText('1º lote')).toBeVisible();

    await escolherAtividade(page, 'Futebol');
    await page.getByRole('button', { name: 'Continuar' }).click();

    // Enquanto ela revisava, a ultima vaga do 1o lote foi de outra pessoa.
    esgotarLote('1º lote');

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    // Ninguem e cobrado em silencio por um preco que nao viu: a tela volta para
    // a etapa dos lotes e diz o que mudou e quanto custa agora.
    const aviso = page.getByTestId('erro-do-lote');
    await expect(aviso).toBeVisible();
    await expect(aviso).toContainText('O lote de inscrição mudou enquanto você preenchia');
    await expect(aviso).toContainText('R$ 150,00');

    // E a lista ja foi recarregada: o 1o lote consta encerrado e o 2o e o atual.
    const lista = page.getByTestId('lista-de-lotes');
    await expect(lista.getByTestId('lote-encerrado')).toContainText('1º lote');
    await expect(lista.getByTestId('lote-vigente')).toContainText('2º lote');

    const escolhas = lista.locator('input[type="radio"]');
    await expect(escolhas.nth(0)).toBeDisabled();
    await expect(escolhas.nth(1)).toBeChecked();

    // Continua na propria pagina: nada foi gravado.
    expect(page.url()).toContain(`/eventos/${EVENTO_DEMO.slug}/inscricao`);
});

test('sem nenhum lote em vigor, o evento explica e não oferece inscrição', async ({ page }) => {
    definirLotes([
        { nome: '1º lote', posicao: 1, valor: 9000, ate: '2026-08-10 23:59' },
        { nome: '2º lote', posicao: 2, valor: 11000, quantidade: 5, ocupadas: 5 },
    ]);

    await page.goto(`/eventos/${EVENTO_DEMO.slug}`);

    // A explicacao aparece no lugar do botao, em palavras.
    await expect(page.getByText('Os lotes de inscrição se esgotaram.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Fazer inscrição' })).toHaveCount(0);

    // Os lotes continuam a vista: quem chegou tarde ve o que perdeu.
    const lista = page.getByTestId('lista-de-lotes');
    await expect(lista.getByText('Prazo encerrado em 10/08/2026')).toBeVisible();
    await expect(lista.getByText('Vagas esgotadas')).toBeVisible();

    // E o formulario nao abre nem por endereco direto.
    await page.goto(`/eventos/${EVENTO_DEMO.slug}/inscricao`);
    await page.waitForURL(new RegExp(`/eventos/${EVENTO_DEMO.slug}$`));
});
