import type { Page } from '@playwright/test';
import { artisan, escolherAtividade, preencherDadosPessoais, type PessoaDeTeste } from './apoio';
import { expect, test } from './base';

/**
 * Atividade sem hora marcada, do cadastro à inscrição.
 *
 * Nem toda programação tem horário. Um mutirão acontece "no sábado": obrigar
 * quem organiza a inventar 08:00 às 17:00 é pedir um dado que ninguém tem — e
 * mostrar essa hora inventada para quem se inscreve é pior ainda.
 *
 * Três perguntas, na ordem em que elas aparecem na vida real: dá para gravar
 * uma atividade deixando o horário em branco; o sistema recusa meio horário
 * (só o começo, sem o fim), explicando o que falta; e a tela de quem se
 * inscreve não escreve horário nenhum — nem "a definir", nem travessão.
 *
 * O EVENTO DESTE CENÁRIO É SÓ DELE. A suíte inteira compartilha um banco
 * semeado, e uma atividade que ocupa o dia inteiro bloquearia as demais
 * escolhas do mesmo dia: colocá-la no evento de demonstração quebraria os
 * outros cenários. Aqui ela nasce num evento próprio, com um dia e um grupo
 * próprios — exatamente o formato com que um evento novo passa a nascer.
 */

const SENHA = 'senha-de-teste-do-horario-opcional';

const ORGANIZADOR = 'horario.opcional@example.com';

const SLUG = 'encontro-de-voluntarios';

const ATIVIDADE = 'Mutirão de limpeza';

const PESSOA: PessoaDeTeste = {
    nome: 'Vera Lúcia Andrade',
    email: 'vera.andrade@example.com',
    telefone: '(11) 96666-1212',
    cpf: '70720730007',
    nascimento: '1985-04-12',
};

/** O identificador do evento deste cenário, descoberto na preparação. */
let eventoId = '';

function prepararConta(): void {
    artisan([
        'tinker',
        '--execute',
        `app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions();` +
            `$usuario = \\App\\Models\\User::query()->updateOrCreate(` +
            `['email' => '${ORGANIZADOR}'],` +
            `['name' => 'Organizador do mutirão', 'password' => '${SENHA}', 'email_verified_at' => now()]` +
            `);` +
            `$usuario->syncRoles(['organizador']);`,
    ]);
}

/**
 * O evento deste cenário: um dia só e um grupo só, como um evento recém-criado.
 *
 * A programação é montada por linha de comando porque o que está sob teste é a
 * ATIVIDADE sem horário, e não o formulário do evento — que tem tela e
 * cenários próprios.
 */
function prepararEvento(): string {
    const saida = artisan([
        'tinker',
        '--execute',
        `$evento = \\App\\Models\\Evento::query()->updateOrCreate(` +
            `['slug' => '${SLUG}'],` +
            `['codigo_publico' => (string) \\Illuminate\\Support\\Str::ulid(),` +
            `'nome' => 'Encontro de Voluntários',` +
            `'descricao' => 'Um dia de trabalho voluntário.',` +
            `'data_inicio' => now()->addMonths(2)->toDateString(),` +
            `'data_fim' => now()->addMonths(2)->toDateString(),` +
            `'inscricoes_abrem_em' => now()->subDay(),` +
            `'inscricoes_fecham_em' => now()->addMonth(),` +
            `'capacidade' => null,` +
            `'valor_centavos' => 5000,` +
            `'moeda' => 'BRL',` +
            `'prazo_pagamento_minutos' => 1440,` +
            `'situacao' => 'inscricoes_abertas',` +
            `'regulamento' => 'Regulamento do encontro de voluntários.',` +
            `'versao_termos' => '2026.1',` +
            `'contato_email' => 'contato@example.com',` +
            `'contato_telefone' => '(11) 90000-0000',` +
            `'configuracoes' => []]` +
            `);` +
            `$dia = \\App\\Models\\DiaEvento::query()->updateOrCreate(` +
            `['evento_id' => $evento->id, 'posicao' => 1],` +
            `['nome' => 'Dia 1', 'data' => $evento->data_inicio->toDateString(), 'ativo' => true]` +
            `);` +
            `\\App\\Models\\GrupoAtividade::query()->updateOrCreate(` +
            `['dia_evento_id' => $dia->id, 'nome' => 'Atividades'],` +
            `['obrigatorio' => true, 'min_selecoes' => 1, 'max_selecoes' => 1, 'posicao' => 1, 'ativo' => true]` +
            `);` +
            `echo 'EVENTO_ID=' . $evento->id;`,
    ]);

    const encontrado = /EVENTO_ID=(\d+)/.exec(saida);

    expect(encontrado, `não encontrei o identificador do evento na saída: ${saida}`).not.toBeNull();

    return (encontrado as RegExpExecArray)[1];
}

async function entrar(page: Page): Promise<void> {
    await page.goto('/login');

    await page.locator('#email').fill(ORGANIZADOR);
    await page.locator('#password').fill(SENHA);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/**
 * Devolve o evento deste cenário ao anonimato.
 *
 * A suíte inteira compartilha um banco só, e a home lista TODOS os eventos com
 * inscrições abertas: deixar este aqui aberto faria a home passar a mostrar
 * dois eventos e quebraria os cenários da porta da rua, que rodam depois deste
 * arquivo. Em rascunho ele continua existindo para quem administra e some do
 * lado de fora — que é exatamente o que rascunho quer dizer.
 */
function esconderEvento(): void {
    artisan(['tinker', '--execute', `\\App\\Models\\Evento::query()->where('slug', '${SLUG}')->update(['situacao' => 'rascunho']);`]);
}

test.beforeAll(() => {
    prepararConta();
    eventoId = prepararEvento();
});

test.afterAll(() => {
    esconderEvento();
});

/**
 * A programação é montada no computador, e é assim que os dois cenários de
 * cadastro rodam. O aparelho de 393px do resto da suíte é o de quem SE
 * INSCREVE — e é ele que continua valendo no último cenário deste arquivo.
 */
test.describe('no computador de quem organiza', () => {
    test.use({
        viewport: { width: 1280, height: 800 },
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
    });

    test('o organizador cadastra uma atividade deixando o horário em branco', async ({ page }) => {
        await entrar(page);
        await page.goto(`/admin/eventos/${eventoId}/estrutura`);

        // Um dia só: a seção de dias começa recolhida, e não escondida — o botão
        // diz quantos dias existem e abre a tabela com um toque.
        const alternarDias = page.getByTestId('alternar-dias');
        await expect(alternarDias).toHaveText('Mostrar os dias (1)');
        await expect(alternarDias).toHaveAttribute('aria-expanded', 'false');

        await alternarDias.click();
        await expect(alternarDias).toHaveText('Ocultar os dias');
        await expect(page.getByRole('rowheader', { name: 'Dia 1' })).toBeVisible();

        await page.getByRole('button', { name: 'Nova atividade' }).click();

        await page.locator('#atividade-nome').fill(ATIVIDADE);
        await page.locator('#atividade-grupo').selectOption({ label: 'Atividades' });

        // O horário fica em branco de propósito: é este o gesto sob teste.
        await page.getByRole('button', { name: 'Acrescentar' }).click();

        await expect(page.getByText('Atividade acrescentada.')).toBeVisible();

        // Na listagem do organizador a ausência é informação de trabalho, e aparece
        // como travessão — só aqui, nunca nas telas de quem se inscreve.
        const linha = page.getByRole('row').filter({ has: page.getByRole('rowheader', { name: ATIVIDADE }) });

        await expect(linha).toBeVisible();
        await expect(linha.getByRole('cell').first()).toHaveText('—');
    });

    test('o sistema recusa meio horário e diz o que falta', async ({ page }) => {
        await entrar(page);
        await page.goto(`/admin/eventos/${eventoId}/estrutura`);

        await page.getByRole('button', { name: 'Nova atividade' }).click();

        await page.locator('#atividade-nome').fill('Metade de um horário');
        await page.locator('#atividade-grupo').selectOption({ label: 'Atividades' });

        // Só o começo: data e hora de início, e nada de término.
        await page.getByLabel('Começa em (opcional)').fill('17/10/2026');
        await page.getByLabel('Hora — início').fill('08:00');

        await page.getByRole('button', { name: 'Acrescentar' }).click();

        await expect(page.getByRole('alert').filter({ hasText: 'hora de término' })).toBeVisible();

        // Nada foi gravado pela metade. O diálogo fecha antes da conferência: com
        // ele aberto, a listagem fica fora da árvore de acessibilidade e a busca
        // não provaria nada.
        await page.getByRole('button', { name: 'Cancelar' }).click();

        await expect(page.getByRole('rowheader', { name: 'Metade de um horário' })).toHaveCount(0);
    });
});

test('quem se inscreve não vê horário nenhum na atividade que ocupa o dia inteiro', async ({ page }) => {
    await page.goto(`/eventos/${SLUG}/inscricao`);

    await preencherDadosPessoais(page, PESSOA);
    await page.getByRole('button', { name: 'Continuar' }).click();

    const cartao = page.locator('label').filter({ hasText: ATIVIDADE }).first();

    await expect(cartao).toBeVisible();

    // Nem hora, nem "a definir", nem travessão: a linha do horário não existe.
    const texto = (await cartao.innerText()).trim();

    expect(texto).not.toMatch(/\d{2}:\d{2}/);
    expect(texto).not.toContain('—');
    expect(texto.toLowerCase()).not.toContain('definir');

    await escolherAtividade(page, ATIVIDADE);
    await page.getByRole('button', { name: 'Continuar' }).click();

    // Na revisão também não sobra separador órfão onde o horário estaria.
    await expect(page.getByText(ATIVIDADE).first()).toBeVisible();

    await page.getByLabel(/Li e aceito o regulamento/).check();
    await page.getByRole('button', { name: 'Confirmar inscrição' }).click();

    await page.waitForURL(/\/inscricoes\/[^/]+\/pagamento\?/);
    await expect(page.getByTestId('cobranca-aguardando')).toBeVisible();
});
