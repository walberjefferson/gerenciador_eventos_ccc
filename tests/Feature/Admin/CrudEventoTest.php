<?php

declare(strict_types=1);

use App\Models\Atividade;
use App\Models\ConflitoAtividade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * O cadastro da estrutura do evento.
 *
 * Duas coisas precisam ficar provadas aqui. A primeira: cada restricao que o
 * banco cobra chega ao organizador como frase em portugues, no campo certo,
 * antes de o PostgreSQL precisar recusar. A segunda, mais importante: mexer na
 * estrutura de um evento que ja tem gente inscrita e recusado — ninguem perde
 * vaga que ja tem, nem tem a escolha apagada por baixo.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

/**
 * Os campos de um evento valido, no formato que o formulario manda.
 *
 * @param  array<string, mixed>  $sobrescritas
 * @return array<string, mixed>
 */
function camposDoEvento(array $sobrescritas = []): array
{
    $inicio = Carbon::now()->addMonths(2)->startOfDay();

    return array_merge([
        'nome' => 'Copa CCC 2026',
        'slug' => 'copa-ccc-2026',
        'descricao' => 'Um evento de teste.',
        'data_inicio' => $inicio->toDateString(),
        'data_fim' => $inicio->copy()->addDay()->toDateString(),
        'inscricoes_abrem_em' => Carbon::now()->subDay()->format('Y-m-d\TH:i'),
        'inscricoes_fecham_em' => Carbon::now()->addMonth()->format('Y-m-d\TH:i'),
        'capacidade' => 100,
        'valor_centavos' => 12000,
        'moeda' => 'BRL',
        'prazo_pagamento_minutos' => 60,
        'situacao' => 'publicado',
        'regulamento' => 'Regulamento completo do evento de teste.',
        'versao_termos' => '1.0',
        'contato_email' => 'contato@example.com',
        'contato_telefone' => '(16) 3333-3333',
    ], $sobrescritas);
}

describe('permissao', function () {
    it('abre a lista de eventos para quem gerencia eventos', function () {
        Evento::factory()->create(['nome' => 'Copa CCC']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/eventos')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Admin/Eventos/Index')
                ->has('eventos', 1)
                ->where('eventos.0.nome', 'Copa CCC'));
    });

    it('recusa com 403 quem nao tem papel nenhum', function () {
        $this->actingAs(Cenario::usuarioCom())
            ->get('/admin/eventos')
            ->assertForbidden();
    });
});

describe('restricoes do evento', function () {
    it('cadastra um evento e leva direto para a programacao', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', camposDoEvento())
            ->assertSessionHasNoErrors();

        expect(Evento::where('slug', 'copa-ccc-2026')->exists())->toBeTrue();
    });

    it('o evento novo ja nasce com o Dia 1 e um grupo de atividades', function () {
        $campos = camposDoEvento();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', $campos)
            ->assertSessionHasNoErrors();

        $evento = Evento::query()->where('slug', 'copa-ccc-2026')->firstOrFail();
        $dia = DiaEvento::query()->where('evento_id', $evento->id)->first();

        // Só o PRIMEIRO dia: acrescentar os demais é decisão de quem organiza,
        // e o sistema não inventa programação.
        expect(DiaEvento::query()->where('evento_id', $evento->id)->count())->toBe(1)
            ->and($dia->nome)->toBe('Dia 1')
            ->and($dia->data->toDateString())->toBe($campos['data_inicio'])
            ->and($dia->posicao)->toBe(1)
            ->and($dia->ativo)->toBeTrue();

        $grupo = GrupoAtividade::query()->where('dia_evento_id', $dia->id)->first();

        // O grupo mais permissivo possível: opcional e sem teto. Um grupo
        // obrigatório criado por conta própria travaria as inscrições de um
        // evento que talvez nem tenha atividades.
        expect($grupo)->not->toBeNull()
            ->and($grupo->nome)->toBe('Atividades')
            ->and($grupo->obrigatorio)->toBeFalse()
            ->and($grupo->min_selecoes)->toBe(0)
            ->and($grupo->max_selecoes)->toBeNull()
            ->and($grupo->posicao)->toBe(1)
            ->and($grupo->ativo)->toBeTrue();
    });

    it('nao deixa evento nem dia pela metade quando o cadastro e recusado', function () {
        $inicio = Carbon::now()->addMonths(2)->startOfDay();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', camposDoEvento([
                'data_fim' => $inicio->copy()->subYear()->toDateString(),
            ]))
            ->assertSessionHasErrors('data_fim');

        expect(Evento::count())->toBe(0)
            ->and(DiaEvento::count())->toBe(0)
            ->and(GrupoAtividade::count())->toBe(0);
    });

    it('editar um evento nao cria dia nem grupo novo', function () {
        $evento = Evento::factory()->create(['slug' => 'evento-ja-existente']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$evento->id}", camposDoEvento([
                'slug' => 'evento-ja-existente',
                'nome' => 'Nome novo do evento',
            ]))
            ->assertSessionHasNoErrors();

        expect(DiaEvento::query()->where('evento_id', $evento->id)->count())->toBe(0)
            ->and($evento->fresh()->nome)->toBe('Nome novo do evento');
    });

    it('recusa data final anterior a inicial', function () {
        $inicio = Carbon::now()->addMonths(2)->startOfDay();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', camposDoEvento([
                'data_inicio' => $inicio->toDateString(),
                'data_fim' => $inicio->copy()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('data_fim');

        expect(Evento::count())->toBe(0);
    });

    it('recusa fechamento das inscricoes antes da abertura', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', camposDoEvento([
                'inscricoes_abrem_em' => Carbon::now()->addMonth()->format('Y-m-d\TH:i'),
                'inscricoes_fecham_em' => Carbon::now()->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('inscricoes_fecham_em');
    });

    it('recusa capacidade negativa', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', camposDoEvento(['capacidade' => -1]))
            ->assertSessionHasErrors('capacidade');
    });

    it('recusa valor negativo', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', camposDoEvento(['valor_centavos' => -100]))
            ->assertSessionHasErrors('valor_centavos');
    });

    it('recusa endereco repetido', function () {
        Evento::factory()->create(['slug' => 'copa-ccc-2026']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/eventos', camposDoEvento())
            ->assertSessionHasErrors('slug');
    });
});

describe('evento com inscricao ativa', function () {
    it('recusa reduzir a capacidade abaixo do que ja esta ocupado', function () {
        $cenario = CenarioInscricao::montar(['capacidade' => 50]);
        $cenario->inscrever();

        $evento = $cenario->evento->fresh();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$evento->id}", camposDoEvento([
                'slug' => $evento->slug,
                'valor_centavos' => $evento->valor_centavos,
                'capacidade' => 0,
            ]))
            ->assertSessionHasErrors('capacidade');

        expect($evento->fresh()->capacidade)->toBe(50);
    });

    it('recusa mudar o valor com inscricao ativa em pe', function () {
        $cenario = CenarioInscricao::montar(['valor_centavos' => 12000]);
        $cenario->inscrever();

        $evento = $cenario->evento->fresh();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$evento->id}", camposDoEvento([
                'slug' => $evento->slug,
                'capacidade' => $evento->capacidade,
                'valor_centavos' => 9900,
            ]))
            ->assertSessionHasErrors('valor_centavos');

        expect($evento->fresh()->valor_centavos)->toBe(12000);
    });

    it('recusa excluir evento que ja recebeu inscricao', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$cenario->evento->id}")
            ->assertSessionHasErrors('exclusao');

        expect(Evento::whereKey($cenario->evento->id)->exists())->toBeTrue();
    });

    it('aceita mudar o nome mesmo com inscricao ativa', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $evento = $cenario->evento->fresh();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$evento->id}", camposDoEvento([
                'nome' => 'Copa CCC — nome corrigido',
                'slug' => $evento->slug,
                'capacidade' => $evento->capacidade,
                'valor_centavos' => $evento->valor_centavos,
            ]))
            ->assertSessionHasNoErrors();

        expect($evento->fresh()->nome)->toBe('Copa CCC — nome corrigido');
    });
});

describe('dias do evento', function () {
    it('acrescenta um dia a programacao', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/dias", [
                'nome' => 'Sábado',
                'data' => $evento->data_inicio->toDateString(),
                'posicao' => 1,
            ])
            ->assertSessionHasNoErrors();

        expect(DiaEvento::where('evento_id', $evento->id)->count())->toBe(1);
    });

    it('recusa posicao repetida dentro do mesmo evento', function () {
        $evento = Evento::factory()->create();
        DiaEvento::factory()->for($evento)->create(['posicao' => 1, 'data' => $evento->data_inicio]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/dias", [
                'nome' => 'Domingo',
                'data' => $evento->data_inicio->toDateString(),
                'posicao' => 1,
            ])
            ->assertSessionHasErrors('posicao');
    });

    it('recusa data fora do periodo do evento', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/dias", [
                'nome' => 'Sábado',
                'data' => $evento->data_inicio->copy()->subMonth()->toDateString(),
                'posicao' => 1,
            ])
            ->assertSessionHasErrors('data');
    });

    it('recusa excluir dia com atividade ja escolhida', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$cenario->evento->id}/dias/{$cenario->dia->id}")
            ->assertSessionHasErrors('exclusao');

        expect(DiaEvento::whereKey($cenario->dia->id)->exists())->toBeTrue();
    });

    it('recusa alcancar o dia de outro evento pela URL', function () {
        $outro = Evento::factory()->create();
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$outro->id}/dias/{$cenario->dia->id}")
            ->assertNotFound();
    });
});

describe('grupos de atividades', function () {
    it('recusa maximo menor que o minimo', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/grupos", [
                'dia_evento_id' => $cenario->dia->id,
                'nome' => 'Oficinas',
                'min_selecoes' => 2,
                'max_selecoes' => 1,
                'posicao' => 9,
            ])
            ->assertSessionHasErrors('max_selecoes');
    });

    it('recusa grupo obrigatorio que nao pede escolha nenhuma', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/grupos", [
                'dia_evento_id' => $cenario->dia->id,
                'nome' => 'Oficinas',
                'obrigatorio' => true,
                'min_selecoes' => 0,
                'max_selecoes' => 2,
                'posicao' => 9,
            ])
            ->assertSessionHasErrors('min_selecoes');
    });

    it('acrescenta um grupo valido', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/grupos", [
                'dia_evento_id' => $cenario->dia->id,
                'nome' => 'Oficinas',
                'obrigatorio' => false,
                'min_selecoes' => 0,
                'max_selecoes' => 2,
                'posicao' => 9,
            ])
            ->assertSessionHasNoErrors();

        expect(GrupoAtividade::where('nome', 'Oficinas')->exists())->toBeTrue();
    });

    it('recusa excluir grupo com atividade ja escolhida', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$cenario->evento->id}/grupos/{$cenario->esportes->id}")
            ->assertSessionHasErrors('exclusao');

        expect(GrupoAtividade::whereKey($cenario->esportes->id)->exists())->toBeTrue();
    });
});

describe('atividades', function () {
    it('recusa atividade que termina antes de comecar', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/atividades", [
                'grupo_atividade_id' => $cenario->esportes->id,
                'nome' => 'Xadrez',
                'comeca_em' => $cenario->hora(11)->format('Y-m-d\TH:i'),
                'termina_em' => $cenario->hora(10)->format('Y-m-d\TH:i'),
                'posicao' => 9,
            ])
            ->assertSessionHasErrors('termina_em');
    });

    it('recusa idade maxima menor que a minima', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/atividades", [
                'grupo_atividade_id' => $cenario->esportes->id,
                'nome' => 'Xadrez',
                'comeca_em' => $cenario->hora(10)->format('Y-m-d\TH:i'),
                'termina_em' => $cenario->hora(11)->format('Y-m-d\TH:i'),
                'idade_minima' => 30,
                'idade_maxima' => 12,
                'posicao' => 9,
            ])
            ->assertSessionHasErrors('idade_maxima');
    });

    it('recusa reduzir a capacidade da atividade abaixo do ocupado', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $futebol = $cenario->futebol->fresh();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$cenario->evento->id}/atividades/{$futebol->id}", [
                'grupo_atividade_id' => $futebol->grupo_atividade_id,
                'nome' => $futebol->nome,
                'comeca_em' => $futebol->comeca_em->format('Y-m-d\TH:i'),
                'termina_em' => $futebol->termina_em->format('Y-m-d\TH:i'),
                'capacidade' => 0,
                'posicao' => $futebol->posicao,
            ])
            ->assertSessionHasErrors('capacidade');
    });

    it('recusa excluir atividade ja escolhida por alguem', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$cenario->evento->id}/atividades/{$cenario->futebol->id}")
            ->assertSessionHasErrors('exclusao');

        expect(Atividade::whereKey($cenario->futebol->id)->exists())->toBeTrue();
    });

    it('exclui atividade que ninguem escolheu', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$cenario->evento->id}/atividades/{$cenario->trilha->id}")
            ->assertSessionHasNoErrors();

        expect(Atividade::whereKey($cenario->trilha->id)->exists())->toBeFalse();
    });
});

describe('conflitos', function () {
    it('normaliza o par ao cadastrar', function () {
        $cenario = CenarioInscricao::montar();

        [$menor, $maior] = ConflitoAtividade::normalizarPar((int) $cenario->futebol->id, (int) $cenario->volei->id);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/conflitos", [
                'atividade_a_id' => $maior,
                'atividade_b_id' => $menor,
                'motivo' => 'Mesma quadra.',
            ])
            ->assertSessionHasNoErrors();

        $conflito = ConflitoAtividade::query()->latest('id')->firstOrFail();

        expect((int) $conflito->atividade_a_id)->toBe($menor)
            ->and((int) $conflito->atividade_b_id)->toBe($maior);
    });

    it('recusa o mesmo par cadastrado ao contrario', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/conflitos", [
                'atividade_a_id' => $cenario->futebol->id,
                'atividade_b_id' => $cenario->volei->id,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/conflitos", [
                'atividade_a_id' => $cenario->volei->id,
                'atividade_b_id' => $cenario->futebol->id,
            ])
            ->assertSessionHasErrors('atividade_b_id');
    });

    it('recusa atividade que conflita consigo mesma', function () {
        $cenario = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$cenario->evento->id}/conflitos", [
                'atividade_a_id' => $cenario->futebol->id,
                'atividade_b_id' => $cenario->futebol->id,
            ])
            ->assertSessionHasErrors('atividade_a_id');
    });

    it('recusa par de atividades de eventos diferentes', function () {
        $primeiro = CenarioInscricao::montar();
        $segundo = CenarioInscricao::montar();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$primeiro->evento->id}/conflitos", [
                'atividade_a_id' => $primeiro->futebol->id,
                'atividade_b_id' => $segundo->futebol->id,
            ])
            ->assertSessionHasErrors('atividade_b_id');
    });
});

describe('tela da programacao', function () {
    it('mostra dias, grupos, atividades e quantas pessoas ja escolheram', function () {
        $cenario = CenarioInscricao::montar();
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get("/admin/eventos/{$cenario->evento->id}/estrutura")
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Admin/Eventos/Estrutura')
                ->where('evento.inscricoes_ativas', 1)
                ->has('dias', 1)
                ->has('dias.0.grupos', 2)
                ->has('atividades', 5)
                ->where('dias.0.grupos.0.atividades.0.escolhida_por', 1));
    });
});
