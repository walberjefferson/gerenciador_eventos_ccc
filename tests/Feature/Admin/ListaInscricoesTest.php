<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * A lista de inscricoes: filtros, busca e paginacao.
 *
 * Duas coisas precisam ficar provadas. A primeira e util: os filtros se
 * combinam e a paginacao nao os perde pelo caminho. A segunda e de seguranca:
 * o CPF nao aparece nos dados mandados para a tela e nao serve de busca — nem
 * inteiro, nem em pedaco.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

it('recusa com 403 quem nao pode ver inscricoes', function () {
    $this->actingAs(Cenario::usuarioCom())
        ->get('/admin/inscricoes')
        ->assertForbidden();
});

it('mostra as inscricoes mais recentes primeiro', function () {
    $cenario = CenarioInscricao::montar();

    $antiga = $cenario->inscrever($cenario->outraPessoa(1, ['nome_completo' => 'Ana Antiga']));
    $antiga->forceFill(['created_at' => Carbon::now()->subDays(3)])->save();

    $cenario->inscrever($cenario->outraPessoa(2, ['nome_completo' => 'Bruno Recente']));

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get('/admin/inscricoes')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Admin/Inscricoes/Index')
            ->has('inscricoes.dados', 2)
            ->where('inscricoes.dados.0.nome_completo', 'Bruno Recente')
            ->where('inscricoes.total', 2));
});

it('nao manda documento nem impressao digital para a tela', function () {
    $cenario = CenarioInscricao::montar();
    $cenario->inscrever();

    $resposta = $this->actingAs(Cenario::usuarioCom('organizador'))->get('/admin/inscricoes');

    $resposta->assertInertia(fn (Assert $pagina) => $pagina
        ->has('inscricoes.dados.0', fn (Assert $linha) => $linha
            ->missing('documento')
            ->missing('documento_hash')
            ->etc()));

    expect($resposta->getContent())
        ->not->toContain('documento_hash')
        ->and($resposta->getContent())->not->toContain('52998224725');
});

describe('busca', function () {
    it('acha pelo nome', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever($cenario->outraPessoa(1, ['nome_completo' => 'Joana Pereira']));
        $cenario->inscrever($cenario->outraPessoa(2, ['nome_completo' => 'Carlos Souza']));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?busca=joana')
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 1)
                ->where('inscricoes.dados.0.nome_completo', 'Joana Pereira'));
    });

    it('acha pelo e-mail', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever($cenario->outraPessoa(1, ['email' => 'alvo@example.com']));
        $cenario->inscrever($cenario->outraPessoa(2));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?busca=alvo@example.com')
            ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.dados', 1));
    });

    it('acha pelo codigo publico', function () {
        $cenario = CenarioInscricao::montar();
        $alvo = $cenario->inscrever($cenario->outraPessoa(1));
        $cenario->inscrever($cenario->outraPessoa(2));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?busca='.$alvo->codigo_publico)
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 1)
                ->where('inscricoes.dados.0.codigo_publico', $alvo->codigo_publico));
    });

    it('nao acha ninguem por pedaco de CPF', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?busca=529982')
            ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.dados', 0));
    });

    it('nao acha ninguem nem pelo CPF inteiro', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?busca=529.982.247-25')
            ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.dados', 0));
    });
});

describe('filtros', function () {
    it('filtra por evento', function () {
        $primeiro = CenarioInscricao::montar();
        $segundo = CenarioInscricao::montar();

        $primeiro->inscrever($primeiro->outraPessoa(1));
        $segundo->inscrever($segundo->outraPessoa(2, ['atividades' => [$segundo->futebol->id]]));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?evento_id='.$primeiro->evento->id)
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 1)
                ->where('inscricoes.dados.0.evento', $primeiro->evento->nome));
    });

    it('filtra por situacao da inscricao', function () {
        $cenario = CenarioInscricao::montar();
        $confirmada = $cenario->inscrever($cenario->outraPessoa(1));
        $confirmada->forceFill(['situacao' => SituacaoInscricao::Confirmada->value])->save();
        $cenario->inscrever($cenario->outraPessoa(2));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?situacao=confirmada')
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 1)
                ->where('inscricoes.dados.0.situacao', 'confirmada'));
    });

    it('filtra por atividade escolhida', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever($cenario->outraPessoa(1, ['atividades' => [$cenario->futebol->id]]));
        $cenario->inscrever($cenario->outraPessoa(2, ['atividades' => [$cenario->volei->id]]));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get("/admin/inscricoes?evento_id={$cenario->evento->id}&atividade_id={$cenario->volei->id}")
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 1)
                ->has('opcoes.atividades', 5));
    });

    it('filtra por cidade e por grupo de participantes', function () {
        $cenario = CenarioInscricao::montar();
        $outroCenario = CenarioInscricao::montar();

        $cenario->inscrever($cenario->outraPessoa(1));
        $outroCenario->inscrever($outroCenario->outraPessoa(2));

        $usuario = Cenario::usuarioCom('organizador');

        $this->actingAs($usuario)
            ->get('/admin/inscricoes?cidade_id='.$cenario->cidade->id)
            ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.dados', 1));

        $this->actingAs($usuario)
            ->get('/admin/inscricoes?grupo_participante_id='.$outroCenario->grupoParticipante->id)
            ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.dados', 1));
    });

    it('filtra por situacao da cobranca', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever($cenario->outraPessoa(1));
        $cenario->inscrever($cenario->outraPessoa(2));

        $inscricao->pagamentos()->first()?->forceFill(['situacao' => 'pago'])->save();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?situacao_pagamento=pago')
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 1)
                ->where('inscricoes.dados.0.situacao_pagamento', 'pago'));
    });

    it('filtra por periodo de criacao', function () {
        $cenario = CenarioInscricao::montar();

        $antiga = $cenario->inscrever($cenario->outraPessoa(1));
        $antiga->forceFill(['created_at' => Carbon::now()->subDays(10)])->save();

        $cenario->inscrever($cenario->outraPessoa(2));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?criada_de='.Carbon::now()->subDay()->toDateString())
            ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.dados', 1));
    });

    it('combina filtros entre si', function () {
        $cenario = CenarioInscricao::montar();

        $alvo = $cenario->inscrever($cenario->outraPessoa(1, [
            'nome_completo' => 'Marta Alvo',
            'atividades' => [$cenario->volei->id],
        ]));
        $alvo->forceFill(['situacao' => SituacaoInscricao::Confirmada->value])->save();

        $cenario->inscrever($cenario->outraPessoa(2, ['nome_completo' => 'Marta Outra']));

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get("/admin/inscricoes?busca=marta&situacao=confirmada&evento_id={$cenario->evento->id}&atividade_id={$cenario->volei->id}")
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 1)
                ->where('inscricoes.dados.0.nome_completo', 'Marta Alvo')
                ->where('filtros.busca', 'marta')
                ->where('filtros.situacao', 'confirmada'));
    });
});

describe('paginacao', function () {
    it('pagina de 25 em 25 e preserva os filtros no endereco da proxima pagina', function () {
        $cenario = CenarioInscricao::montar(['capacidade' => null]);

        foreach (range(1, 27) as $indice) {
            $cenario->inscrever($cenario->outraPessoa($indice, ['nome_completo' => "Participante {$indice}"]));
        }

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?evento_id='.$cenario->evento->id)
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 25)
                ->where('inscricoes.ultima_pagina', 2)
                ->where('inscricoes.links.proxima', fn (?string $url): bool => is_string($url)
                    && str_contains($url, 'evento_id='.$cenario->evento->id)
                    && str_contains($url, 'page=2')));
    });

    it('a segunda pagina traz o resto do mesmo filtro', function () {
        $cenario = CenarioInscricao::montar(['capacidade' => null]);

        foreach (range(1, 27) as $indice) {
            $cenario->inscrever($cenario->outraPessoa($indice));
        }

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/inscricoes?evento_id='.$cenario->evento->id.'&page=2')
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('inscricoes.dados', 2)
                ->where('inscricoes.pagina_atual', 2)
                ->where('filtros.evento_id', (string) $cenario->evento->id));
    });
});

it('conta todas as inscricoes do evento, inclusive as canceladas', function () {
    $cenario = CenarioInscricao::montar();
    $cancelada = $cenario->inscrever($cenario->outraPessoa(1));
    $cancelada->forceFill(['situacao' => SituacaoInscricao::Cancelada->value])->save();

    expect(Inscricao::count())->toBe(1);

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get('/admin/inscricoes')
        ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.dados', 1));
});
