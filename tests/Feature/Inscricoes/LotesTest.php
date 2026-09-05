<?php

declare(strict_types=1);

use App\Actions\Inscricoes\CriarInscricao;
use App\Actions\Inscricoes\ExpirarInscricoesVencidas;
use App\Actions\Inscricoes\ReservarVagas;
use App\Actions\Inscricoes\ResolverLoteVigente;
use App\DTOs\Inscricoes\DadosNovaInscricao;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Inscricoes\LoteIndisponivelException;
use App\Models\Evento;
use App\Models\Inscricao;
use App\Models\Lote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Inscricoes\Cenario;

/**
 * O lote de inscricao: o degrau de preco entre o evento e a inscricao.
 *
 * O que este arquivo prova, em uma frase: **so entra quem entra pelo lote que
 * vale agora, e o preco daquele lote fica gravado na inscricao para sempre.**
 *
 * As perguntas respondidas aqui:
 *
 * - qual e o lote vigente, com lote vencido e lote esgotado pelo caminho;
 * - que a inscricao guarda de qual lote veio e por quanto entrou;
 * - que mexer no valor do lote depois nao alcanca inscricao nenhuma (RN-L7);
 * - que enviar um lote que nao e mais o vigente e recusado, e nao "corrigido"
 *   em silencio (RN-L5);
 * - que a ultima vaga do lote so e vendida uma vez;
 * - que a vaga devolvida por expiracao volta para o evento e NAO para o lote
 *   (RN-L6);
 * - que evento sem lote nenhum continua exatamente como era (RN-L8);
 * - que evento com todos os lotes esgotados fecha as inscricoes (RN-L9).
 */
describe('qual lote vale agora', function () {
    it('e o primeiro lote da ordem que ainda esta disponivel', function () {
        $cenario = Cenario::montar()->comLotes([
            ['valor_centavos' => 10000, 'quantidade' => 5],
            ['valor_centavos' => 15000, 'quantidade' => 5],
        ]);

        $vigente = app(ResolverLoteVigente::class)($cenario->evento);

        expect($vigente?->id)->toBe($cenario->lotes[0]->id)
            ->and($vigente?->valor_centavos)->toBe(10000);
    });

    it('pula o lote vencido por data e entrega o seguinte', function () {
        $cenario = Cenario::montar()->comLotes([
            ['disponivel_ate' => Carbon::now()->subDay(), 'valor_centavos' => 10000],
            ['disponivel_ate' => Carbon::now()->addWeek(), 'valor_centavos' => 15000],
        ]);

        expect(app(ResolverLoteVigente::class)($cenario->evento)?->id)->toBe($cenario->lotes[1]->id);
    });

    it('pula o lote esgotado por quantidade e entrega o seguinte', function () {
        $cenario = Cenario::montar()->comLotes([
            ['esgotado' => true, 'quantidade' => 3, 'valor_centavos' => 10000],
            ['quantidade' => 5, 'valor_centavos' => 15000],
        ]);

        expect($cenario->lotes[0]->vagas_ocupadas)->toBe(3)
            ->and(app(ResolverLoteVigente::class)($cenario->evento)?->id)->toBe($cenario->lotes[1]->id);
    });

    it('nao entrega lote nenhum quando todos venceram ou esgotaram', function () {
        $cenario = Cenario::montar()->comLotes([
            ['disponivel_ate' => Carbon::now()->subDay()],
            ['esgotado' => true],
        ]);

        expect(app(ResolverLoteVigente::class)($cenario->evento))->toBeNull();
    });

    it('escreve a situacao de cada lote em uma palavra', function () {
        $cenario = Cenario::montar()->comLotes([
            ['disponivel_ate' => Carbon::now()->subDay()],
            ['quantidade' => 5],
            ['quantidade' => 5],
        ]);

        $vigenteId = app(ResolverLoteVigente::class)($cenario->evento)?->id;

        expect($cenario->lotes[0]->situacaoEm(null, $vigenteId))->toBe('encerrado')
            ->and($cenario->lotes[1]->situacaoEm(null, $vigenteId))->toBe('vigente')
            ->and($cenario->lotes[2]->situacaoEm(null, $vigenteId))->toBe('futuro');
    });
});

describe('a fotografia do preco', function () {
    it('grava na inscricao de qual lote ela veio e por quanto entrou', function () {
        $cenario = Cenario::montar(['valor_centavos' => 90000])->comLotes([
            ['valor_centavos' => 10000, 'quantidade' => 5],
            ['valor_centavos' => 15000, 'quantidade' => 5],
        ]);

        $inscricao = $cenario->inscrever(['lote_id' => $cenario->lotes[0]->id]);

        // O valor e o do LOTE, nunca o do evento — que aqui e nove vezes maior
        // justamente para que um engano nao passasse despercebido.
        expect($inscricao->lote_id)->toBe($cenario->lotes[0]->id)
            ->and($inscricao->valor_centavos)->toBe(10000);
    });

    it('nao muda a inscricao ja criada quando o valor do lote e alterado depois', function () {
        $cenario = Cenario::montar()->comLotes([['valor_centavos' => 10000, 'quantidade' => 5]]);

        $inscricao = $cenario->inscrever();

        $cenario->lotes[0]->update(['valor_centavos' => 25000]);

        expect($inscricao->fresh()?->valor_centavos)->toBe(10000);
    });

    it('cobra o valor do proprio evento quando nao ha lote nenhum', function () {
        $cenario = Cenario::montar(['valor_centavos' => 33000]);

        $inscricao = $cenario->inscrever();

        expect($inscricao->lote_id)->toBeNull()
            ->and($inscricao->valor_centavos)->toBe(33000);
    });

    it('aceita quem nao mandou lote nenhum e o poe no vigente', function () {
        // O caso de quem fala com a API sem passar pela tela: nao ha preco visto
        // a proteger, entao entra pelo que vale agora.
        $cenario = Cenario::montar()->comLotes([['valor_centavos' => 12000, 'quantidade' => 5]]);

        $inscricao = $cenario->inscrever();

        expect($inscricao->lote_id)->toBe($cenario->lotes[0]->id)
            ->and($inscricao->valor_centavos)->toBe(12000);
    });
});

describe('o lote virou enquanto a pessoa preenchia', function () {
    it('recusa o envio pelo lote que ja nao e mais o vigente', function () {
        $cenario = Cenario::montar()->comLotes([
            ['esgotado' => true, 'valor_centavos' => 10000],
            ['quantidade' => 5, 'valor_centavos' => 15000],
        ]);

        expect(fn () => $cenario->inscrever(['lote_id' => $cenario->lotes[0]->id]))
            ->toThrow(
                LoteIndisponivelException::class,
                'O lote de inscrição mudou enquanto você preenchia o formulário. Agora vale o 2º lote, por R$ 150,00. '
                .'Confira o novo valor e envie a inscrição novamente.'
            );

        // E nada foi gravado: nem inscricao, nem vaga presa no evento.
        expect(Inscricao::query()->count())->toBe(0)
            ->and($cenario->evento->fresh()?->vagasOcupadas())->toBe(0);
    });

    it('recusa com 422 na porta do formulario, no campo do lote', function () {
        $cenario = Cenario::montar()->comLotes([
            ['esgotado' => true, 'valor_centavos' => 10000],
            ['quantidade' => 5, 'valor_centavos' => 15000],
        ]);

        $this->postJson('/inscricoes', $cenario->payload(['lote_id' => $cenario->lotes[0]->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lote_id');
    });

    it('recusa quando o evento tem lotes e nenhum deles vale mais', function () {
        $cenario = Cenario::montar()->comLotes([['esgotado' => true]]);

        expect(fn () => $cenario->inscrever())
            ->toThrow(LoteIndisponivelException::class, 'Os lotes de inscrição se esgotaram.');
    });
});

describe('a ultima vaga do lote', function () {
    /**
     * O que precisa ficar provado aqui e que a vaga do lote e presa por UM
     * comando que so tem efeito se ainda houver vaga — e nunca por uma leitura
     * seguida de uma gravacao.
     *
     * As duas chamadas sao as duas requisicoes que leram o MESMO lote
     * disponivel: a primeira grava, a segunda encontra o contador ja cheio e e
     * recusada pelo proprio UPDATE. Nao ha aqui a maquinaria de processos do
     * ConcorrenciaTest porque ela nasceu para provar outra coisa (que duas
     * conexoes nao vendem a mesma vaga do EVENTO) e nao conhece lote; a garantia
     * do lote mora inteira dentro deste comando, e e ele que esta sob prova.
     */
    it('so e vendida uma vez, ainda que duas requisicoes a tenham visto livre', function () {
        $cenario = Cenario::montar()->comLotes([['quantidade' => 1, 'valor_centavos' => 10000]]);

        $lote = $cenario->lotes[0];
        $reservar = app(ReservarVagas::class);

        $reservar->reservarNoLote($lote);

        expect(fn () => $reservar->reservarNoLote($lote))
            ->toThrow(LoteIndisponivelException::class, 'As vagas do 1º lote acabaram neste instante.');

        expect($lote->fresh()?->vagas_ocupadas)->toBe(1);
    });

    it('recusa a segunda inscricao e deixa o contador no teto', function () {
        $cenario = Cenario::montar()->comLotes([['quantidade' => 1, 'valor_centavos' => 10000]]);

        $cenario->inscrever();

        expect(fn () => app(CriarInscricao::class)(
            DadosNovaInscricao::deArray($cenario->outraPessoa(2))
        ))->toThrow(LoteIndisponivelException::class);

        expect($cenario->lotes[0]->fresh()?->vagas_ocupadas)->toBe(1)
            ->and(Inscricao::query()->count())->toBe(1);
    });

    it('nao deixa o contador passar da quantidade nem por comando direto', function () {
        $cenario = Cenario::montar()->comLotes([['quantidade' => 1]]);

        app(ReservarVagas::class)->reservarNoLote($cenario->lotes[0]);

        // A ultima linha de defesa: mesmo que algum caminho de codigo errasse a
        // contabilidade, o CHECK do PostgreSQL recusaria a gravacao.
        expect(fn () => DB::table('lotes')
            ->where('id', $cenario->lotes[0]->id)
            ->update(['vagas_ocupadas' => 2]))
            ->toThrow(QueryException::class, 'lotes_quantidade_check');
    });

    it('recusa a vaga depois de o prazo do lote passar', function () {
        $cenario = Cenario::montar()->comLotes([['disponivel_ate' => Carbon::now()->addHour()]]);

        expect(fn () => app(ReservarVagas::class)->reservarNoLote(
            $cenario->lotes[0],
            Carbon::now()->addDay(),
        ))->toThrow(LoteIndisponivelException::class);

        expect($cenario->lotes[0]->fresh()?->vagas_ocupadas)->toBe(0);
    });
});

describe('a vaga que volta', function () {
    it('devolve a vaga ao evento quando a inscricao expira, mas nunca ao lote', function () {
        $cenario = Cenario::montar(['prazo_pagamento_minutos' => 60])
            ->comLotes([['quantidade' => 2, 'valor_centavos' => 10000]]);

        $inscricao = $cenario->inscrever();

        expect($cenario->evento->fresh()?->vagasOcupadas())->toBe(1)
            ->and($cenario->lotes[0]->fresh()?->vagas_ocupadas)->toBe(1);

        Carbon::setTestNow(Carbon::now()->addDay());

        app(ExpirarInscricoesVencidas::class)();

        expect($inscricao->fresh()?->situacao)->toBe(SituacaoInscricao::Expirada)
            // A vaga volta para o evento...
            ->and($cenario->evento->fresh()?->vagasOcupadas())->toBe(0)
            // ...e NAO volta para o lote: lote esgotado nao reabre (RN-L6).
            ->and($cenario->lotes[0]->fresh()?->vagas_ocupadas)->toBe(1);

        Carbon::setTestNow();
    });

    it('mantem o lote esgotado depois de a unica inscricao dele expirar', function () {
        $cenario = Cenario::montar(['prazo_pagamento_minutos' => 60])
            ->comLotes([
                ['quantidade' => 1, 'valor_centavos' => 10000],
                ['quantidade' => 5, 'valor_centavos' => 15000],
            ]);

        $cenario->inscrever();

        Carbon::setTestNow(Carbon::now()->addDay());

        app(ExpirarInscricoesVencidas::class)();

        // A vaga voltou para a capacidade do evento e sera vendida pelo preco do
        // 2o lote. E a consequencia da decisao registrada na RN-L6.
        expect(app(ResolverLoteVigente::class)($cenario->evento)?->id)->toBe($cenario->lotes[1]->id);

        Carbon::setTestNow();
    });
});

describe('as inscricoes na tela publica', function () {
    it('mostra todos os lotes, com o preco do vigente no lugar do preco do evento', function () {
        $cenario = Cenario::montar(['valor_centavos' => 90000]);
        $cenario->comLotes([
            ['disponivel_ate' => Carbon::now()->subDay(), 'valor_centavos' => 10000],
            ['quantidade' => 5, 'valor_centavos' => 15000],
            ['quantidade' => 5, 'valor_centavos' => 20000],
        ]);

        $this->get("/eventos/{$cenario->evento->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Eventos/Show')
                ->has('evento.lotes', 3)
                ->where('evento.valor_centavos', 15000)
                ->where('evento.lote_vigente_id', $cenario->lotes[1]->id)
                ->where('evento.lotes.0.situacao', 'encerrado')
                ->where('evento.lotes.0.selecionavel', false)
                ->where('evento.lotes.1.situacao', 'vigente')
                ->where('evento.lotes.1.selecionavel', true)
                ->where('evento.lotes.2.situacao', 'futuro')
                ->where('evento.lotes.2.selecionavel', false)
                ->where('evento.inscricoes_abertas', true)
                ->etc());
    });

    it('fecha as inscricoes com o motivo certo quando todos os lotes se esgotaram', function () {
        $cenario = Cenario::montar(['capacidade' => 100])->comLotes([
            ['disponivel_ate' => Carbon::now()->subDay()],
            ['esgotado' => true],
        ]);

        $this->get("/eventos/{$cenario->evento->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Eventos/Show')
                ->where('evento.inscricoes_abertas', false)
                // A capacidade sobrou de proposito: os dois tetos sao
                // independentes (RN-L10), e o motivo precisa dizer qual deles
                // fechou a porta.
                ->where('evento.motivo_inscricoes_fechadas', 'Os lotes de inscrição se esgotaram.')
                ->etc());
    });

    it('nao abre o formulario de inscricao quando nao ha lote vigente', function () {
        $cenario = Cenario::montar()->comLotes([['esgotado' => true]]);

        $this->get("/eventos/{$cenario->evento->slug}/inscricao")
            ->assertRedirect("/eventos/{$cenario->evento->slug}");
    });

    it('nao muda em nada a tela de um evento sem lotes', function () {
        $cenario = Cenario::montar(['valor_centavos' => 33000]);

        $this->get("/eventos/{$cenario->evento->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->component('Eventos/Show')
                ->has('evento.lotes', 0)
                ->where('evento.lote_vigente_id', null)
                ->where('evento.valor_centavos', 33000)
                ->where('evento.inscricoes_abertas', true)
                ->etc());
    });
});

describe('o lote e o evento', function () {
    it('nao apaga um lote que ja tem inscricao, nem pelo banco', function () {
        $cenario = Cenario::montar()->comLotes([['quantidade' => 5]]);

        $cenario->inscrever();

        expect(fn () => $cenario->lotes[0]->delete())
            ->toThrow(QueryException::class);
    });

    it('sabe dizer se o evento trabalha com lotes', function () {
        $semLotes = Evento::factory()->create();
        $comLotes = Evento::factory()->create();
        Lote::factory()->for($comLotes)->create();

        expect($semLotes->temLotes())->toBeFalse()
            ->and($comLotes->temLotes())->toBeTrue()
            ->and($semLotes->aceitaInscricaoPorLote())->toBeTrue();
    });
});
