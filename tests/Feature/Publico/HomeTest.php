<?php

declare(strict_types=1);

use App\Models\Evento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A porta da rua.
 *
 * Quem abre o endereco do sistema precisa descobrir, em segundos, qual e o
 * evento, quando ele acontece e como se inscrever. Este arquivo prova os tres
 * estados dessa tela — um evento aberto, mais de um, nenhum — mais o caso
 * limite que engana: o evento ja publicado cuja janela de inscricao ainda nao
 * comecou. Ele aparece como "proximo", nunca com botao de inscricao.
 *
 * Prova tambem duas coisas que so se veem olhando a resposta: que a pagina se
 * monta com **uma** consulta ao banco, e que ela nao leva para o navegador
 * nenhum dado que o publico nao deva ver.
 */

/**
 * Conta quantas consultas o trecho dispara.
 */
function consultasDisparadas(Closure $trecho): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $trecho();

    $total = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $total;
}

it('mostra o evento com inscricoes abertas, com nome, datas e o caminho da vitrine', function (): void {
    Evento::factory()->create([
        'nome' => 'Caminhada 2026',
        'slug' => 'caminhada-2026',
        'descricao' => 'Três dias de caminhada, oficinas e celebração.',
        'data_inicio' => '2026-09-12',
        'data_fim' => '2026-09-14',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Home')
            ->where('destaque.nome', 'Caminhada 2026')
            ->where('destaque.slug', 'caminhada-2026')
            ->where('destaque.data_inicio', '2026-09-12')
            ->where('destaque.data_fim', '2026-09-14')
            ->where('destaque.periodo_rotulo', 'De 12/09/2026 a 14/09/2026')
            ->where('destaque.resumo', 'Três dias de caminhada, oficinas e celebração.')
            ->where('destaque.inscricoes_abertas', true)
            ->where('destaque.abre_em_rotulo', null)
            ->has('outros_abertos', 0)
            ->where('proximo', null)
            ->where('aviso_sem_inscricoes', null)
        );
});

it('a rota da porta da rua continua se chamando home', function (): void {
    expect(route('home', absolute: false))->toBe('/');
});

it('destaca o evento de inicio mais proximo e lista os demais abaixo', function (): void {
    // Gravados de proposito fora de ordem: quem escolhesse "o primeiro do
    // banco" destacaria o de novembro.
    Evento::factory()->create([
        'nome' => 'Encontro de novembro',
        'slug' => 'encontro-de-novembro',
        'data_inicio' => '2026-11-20',
        'data_fim' => '2026-11-22',
    ]);

    Evento::factory()->create([
        'nome' => 'Encontro de setembro',
        'slug' => 'encontro-de-setembro',
        'data_inicio' => '2026-09-05',
        'data_fim' => '2026-09-06',
    ]);

    Evento::factory()->create([
        'nome' => 'Encontro de outubro',
        'slug' => 'encontro-de-outubro',
        'data_inicio' => '2026-10-10',
        'data_fim' => '2026-10-11',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Home')
            ->where('destaque.slug', 'encontro-de-setembro')
            ->has('outros_abertos', 2)
            ->where('outros_abertos.0.slug', 'encontro-de-outubro')
            ->where('outros_abertos.1.slug', 'encontro-de-novembro')
            ->where('aviso_sem_inscricoes', null)
        );
});

it('avisa com clareza quando nao ha nenhuma inscricao aberta', function (): void {
    Evento::factory()->rascunho()->create(['nome' => 'Rascunho escondido']);
    Evento::factory()->inscricoesEncerradas()->create(['nome' => 'Encerrado']);
    Evento::factory()->create(['nome' => 'Cancelado', 'situacao' => 'cancelado']);
    Evento::factory()->create(['nome' => 'Finalizado', 'situacao' => 'finalizado']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Home')
            ->where('destaque', null)
            ->has('outros_abertos', 0)
            ->where('proximo', null)
            ->where('aviso_sem_inscricoes', 'No momento não há inscrições abertas.')
        );
});

it('nao mostra evento em rascunho, cancelado ou finalizado nem quando ha um aberto', function (): void {
    Evento::factory()->create(['nome' => 'Aberto de verdade', 'slug' => 'aberto-de-verdade']);
    Evento::factory()->rascunho()->create(['nome' => 'Rascunho escondido']);
    Evento::factory()->create(['nome' => 'Cancelado', 'situacao' => 'cancelado']);
    Evento::factory()->create(['nome' => 'Finalizado', 'situacao' => 'finalizado']);
    Evento::factory()->inscricoesEncerradas()->create(['nome' => 'Encerrado']);

    $resposta = $this->get('/')->assertOk();

    $props = $resposta->viewData('page')['props'];
    $nomes = collect([$props['destaque'], $props['proximo'], ...$props['outros_abertos']])
        ->filter()
        ->pluck('nome')
        ->all();

    expect($nomes)->toBe(['Aberto de verdade']);
});

it('o evento publicado cuja janela ainda nao abriu aparece como proximo, e nunca como destaque', function (): void {
    // O caso limite: ja publicado, com data marcada, mas a inscricao so abre
    // amanha. Se ele virasse destaque, a tela ofereceria um botao que o
    // servidor recusaria.
    Evento::factory()->create([
        'nome' => 'Retiro de 2027',
        'slug' => 'retiro-de-2027',
        'situacao' => 'publicado',
        'inscricoes_abrem_em' => Carbon::parse('2027-01-15 09:00:00'),
        'inscricoes_fecham_em' => Carbon::parse('2027-02-15 23:59:00'),
        'data_inicio' => '2027-03-01',
        'data_fim' => '2027-03-03',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Home')
            ->where('destaque', null)
            ->has('outros_abertos', 0)
            ->where('proximo.nome', 'Retiro de 2027')
            ->where('proximo.inscricoes_abertas', false)
            ->where('proximo.abre_em_rotulo', 'As inscrições abrem em 15/01/2027 às 09:00.')
            ->where('aviso_sem_inscricoes', 'No momento não há inscrições abertas.')
        );
});

it('o evento com situacao aberta cuja janela ainda nao comecou tambem e apenas o proximo', function (): void {
    // Mesma armadilha pelo outro lado: a situacao ja diz "inscricoes abertas",
    // mas a data de abertura e amanha. Quem decide e a janela, nao o rotulo.
    Evento::factory()->inscricoesAindaNaoAbriram()->create([
        'nome' => 'Mutirão de amanhã',
        'slug' => 'mutirao-de-amanha',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Home')
            ->where('destaque', null)
            ->where('proximo.slug', 'mutirao-de-amanha')
            ->where('proximo.inscricoes_abertas', false)
            ->where('aviso_sem_inscricoes', 'No momento não há inscrições abertas.')
        );
});

it('monta a pagina inteira com uma consulta ao banco', function (): void {
    Evento::factory()->count(3)->create();
    Evento::factory()->inscricoesAindaNaoAbriram()->create();

    // O preparo do request (rotas, configuracao) fica de fora: o que se conta e
    // a montagem da pagina, repetida na segunda chamada.
    $this->get('/')->assertOk();

    $consultas = consultasDisparadas(fn () => $this->get('/')->assertOk());

    expect($consultas)->toBe(1);
});

it('nao leva para o navegador nada alem do que a tela mostra', function (): void {
    Evento::factory()->comCapacidade(200)->create([
        'nome' => 'Caminhada 2026',
        'slug' => 'caminhada-2026',
    ]);

    Evento::factory()->create([
        'nome' => 'Encontro seguinte',
        'data_inicio' => Carbon::now()->addMonths(2)->toDateString(),
        'data_fim' => Carbon::now()->addMonths(2)->addDay()->toDateString(),
    ]);

    Evento::factory()->inscricoesAindaNaoAbriram()->create(['nome' => 'Retiro futuro']);

    $props = $this->get('/')->assertOk()->viewData('page')['props'];

    $esperadas = [
        'abre_em_rotulo',
        'data_fim',
        'data_inicio',
        'inscricoes_abertas',
        'nome',
        'periodo_rotulo',
        'prazo_rotulo',
        'resumo',
        'situacao',
        'situacao_rotulo',
        'slug',
        'valor_centavos',
    ];

    $eventos = collect([$props['destaque'], $props['proximo'], ...$props['outros_abertos']])->filter();

    expect($eventos)->toHaveCount(3);

    // Cada evento entregue tem exatamente estas chaves. Nenhum id interno — o
    // slug e o identificador publico —, nenhuma contagem de inscritos, nenhuma
    // vaga restante e nenhum dado de participante.
    //
    // "valor_centavos" entrou na Etapa 24, e antes dela era proibido. A razao
    // da proibicao era que a home apresentava e encaminhava, sem falar de
    // dinheiro. A razao de ter mudado e o convite principal passar a dizer
    // quanto custa dentro do proprio botao: quem toca ja sabe o preco, em vez
    // de descobrir duas telas adiante. Foi decisao de quem encomendou.
    //
    // "prazo_rotulo" entrou na Etapa 25, com o redesenho da porta de entrada.
    // E uma FRASE, e nao um contador: "Encerram em 12 dias" nao desatualiza
    // enquanto a pessoa le, e diz o que ela precisa saber para agir hoje em vez
    // de "depois eu vejo". Quem escreve a frase e o servidor, como em
    // "periodo_rotulo" e "abre_em_rotulo".
    //
    // Vaga restante continua proibida, e por motivo que nao mudou: na porta de
    // entrada vira pressao sem contexto e desatualiza no segundo seguinte.
    $eventos->each(function (array $evento) use ($esperadas): void {
        $chaves = array_keys($evento);
        sort($chaves);

        expect($chaves)->toBe($esperadas);
    });

    // E, no texto cru da resposta, nada de contador de vaga nem de dinheiro,
    // mesmo que alguem os acrescente um dia fora do Resource.
    //
    // "codigo_publico" fica de fora desta lista de proposito: a palavra aparece
    // na tabela de rotas que o sistema escreve em toda pagina — e o nome do
    // parametro de "/inscricoes/{codigo_publico}/pagamento", nao o codigo de
    // ninguem. Que nenhum evento leve o seu ja esta provado acima, nas chaves.
    $conteudo = $this->get('/')->getContent();

    foreach (['vagas_reservadas', 'vagas_confirmadas', 'vagas_disponiveis', 'capacidade'] as $proibido) {
        expect($conteudo)->not->toContain($proibido);
    }
});
