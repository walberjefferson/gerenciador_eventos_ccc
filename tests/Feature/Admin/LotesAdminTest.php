<?php

declare(strict_types=1);

use App\Models\Evento;
use App\Models\LogAuditoria;
use App\Models\Lote;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * O cadastro dos lotes de inscricao.
 *
 * O que este arquivo prova, em uma frase: **cada restricao que o banco cobra
 * chega ao organizador como frase em portugues, e nenhum lote de onde alguem
 * ja se inscreveu some nem encolhe.**
 *
 * Tres regras estao sob prova: todo lote encerra por alguma coisa (RN-L1), a
 * posicao nao se repete dentro do evento (RN-L2) e lote com inscricao nao e
 * excluido nem tem a quantidade reduzida abaixo do que ja saiu (RN-L12).
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

/**
 * Os campos de um lote valido, no formato que o formulario manda.
 *
 * @param  array<string, mixed>  $sobrescritas
 * @return array<string, mixed>
 */
function camposDoLote(array $sobrescritas = []): array
{
    return array_merge([
        'nome' => '1º lote',
        'posicao' => 1,
        'valor_centavos' => 10000,
        'disponivel_ate' => Carbon::now()->addWeek()->format('Y-m-d\TH:i'),
        'quantidade' => null,
    ], $sobrescritas);
}

describe('permissao', function () {
    it('recusa com 403 quem nao gerencia eventos', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom())
            ->post("/admin/eventos/{$evento->id}/lotes", camposDoLote())
            ->assertForbidden();
    });

    it('recusa alcancar o lote de outro evento pela URL', function () {
        $outro = Evento::factory()->create();
        $lote = Lote::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$outro->id}/lotes/{$lote->id}")
            ->assertNotFound();
    });
});

describe('cadastro', function () {
    it('acrescenta um lote ao evento', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/lotes", camposDoLote())
            ->assertSessionHasNoErrors();

        $lote = Lote::query()->where('evento_id', $evento->id)->sole();

        expect($lote->nome)->toBe('1º lote')
            ->and($lote->valor_centavos)->toBe(10000)
            ->and($lote->vagas_ocupadas)->toBe(0);
    });

    it('registra a criacao na auditoria', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/lotes", camposDoLote());

        $lote = Lote::query()->where('evento_id', $evento->id)->sole();

        $registro = LogAuditoria::query()
            ->where('entidade', 'lote')
            ->where('entidade_id', $lote->id)
            ->first();

        expect($registro)->not->toBeNull()
            ->and($registro?->acao->value)->toBe('criou');
    });

    it('altera o lote e registra a alteracao', function () {
        $evento = Evento::factory()->create();
        $lote = Lote::factory()->for($evento)->create(['valor_centavos' => 10000]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$evento->id}/lotes/{$lote->id}", camposDoLote(['valor_centavos' => 18000]))
            ->assertSessionHasNoErrors();

        expect($lote->fresh()?->valor_centavos)->toBe(18000)
            ->and(LogAuditoria::query()
                ->where('entidade', 'lote')
                ->where('entidade_id', $lote->id)
                ->where('acao', 'alterou')
                ->exists())->toBeTrue();
    });

    it('exclui um lote de onde ninguem se inscreveu', function () {
        $evento = Evento::factory()->create();
        $lote = Lote::factory()->for($evento)->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$evento->id}/lotes/{$lote->id}")
            ->assertSessionHasNoErrors();

        expect(Lote::query()->whereKey($lote->id)->exists())->toBeFalse()
            ->and(LogAuditoria::query()->where('entidade', 'lote')->where('acao', 'removeu')->exists())->toBeTrue();
    });
});

describe('restricoes do lote', function () {
    it('recusa lote sem limite nenhum', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/lotes", camposDoLote([
                'disponivel_ate' => null,
                'quantidade' => null,
            ]))
            ->assertSessionHasErrors('disponivel_ate');

        expect(Lote::query()->count())->toBe(0);
    });

    it('aceita lote que encerra so pela quantidade', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/lotes", camposDoLote([
                'disponivel_ate' => null,
                'quantidade' => 40,
            ]))
            ->assertSessionHasNoErrors();

        expect(Lote::query()->sole()->quantidade)->toBe(40);
    });

    it('recusa posicao repetida dentro do mesmo evento', function () {
        $evento = Evento::factory()->create();
        Lote::factory()->for($evento)->create(['posicao' => 1]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/lotes", camposDoLote(['posicao' => 1]))
            ->assertSessionHasErrors('posicao');
    });

    it('aceita a mesma posicao em eventos diferentes', function () {
        $primeiro = Evento::factory()->create();
        $segundo = Evento::factory()->create();
        Lote::factory()->for($primeiro)->create(['posicao' => 1]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$segundo->id}/lotes", camposDoLote(['posicao' => 1]))
            ->assertSessionHasNoErrors();
    });

    it('recusa valor negativo', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/eventos/{$evento->id}/lotes", camposDoLote(['valor_centavos' => -1]))
            ->assertSessionHasErrors('valor_centavos');
    });
});

describe('lote com gente dentro', function () {
    it('recusa excluir lote de onde alguem ja se inscreveu', function () {
        $cenario = CenarioInscricao::montar()->comLotes([['quantidade' => 5]]);
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/eventos/{$cenario->evento->id}/lotes/{$cenario->lotes[0]->id}")
            ->assertSessionHasErrors('exclusao');

        expect(Lote::query()->whereKey($cenario->lotes[0]->id)->exists())->toBeTrue();
    });

    it('recusa reduzir a quantidade abaixo do que ja saiu', function () {
        $cenario = CenarioInscricao::montar()->comLotes([['quantidade' => 5]]);
        $cenario->inscrever();

        $lote = $cenario->lotes[0];

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$cenario->evento->id}/lotes/{$lote->id}", camposDoLote([
                'disponivel_ate' => null,
                'quantidade' => 0,
            ]))
            ->assertSessionHasErrors('quantidade');

        expect($lote->fresh()?->quantidade)->toBe(5);
    });

    it('aceita reduzir a quantidade ate o que ja saiu', function () {
        $cenario = CenarioInscricao::montar()->comLotes([['quantidade' => 5]]);
        $cenario->inscrever();

        $lote = $cenario->lotes[0];

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/eventos/{$cenario->evento->id}/lotes/{$lote->id}", camposDoLote([
                'disponivel_ate' => null,
                'quantidade' => 1,
            ]))
            ->assertSessionHasNoErrors();

        expect($lote->fresh()?->quantidade)->toBe(1);
    });
});

describe('tela da programacao', function () {
    it('mostra os lotes com a situacao, o que ja saiu e a soma ao lado da capacidade', function () {
        $cenario = CenarioInscricao::montar(['capacidade' => 100]);
        $cenario->comLotes([
            ['quantidade' => 30, 'valor_centavos' => 10000],
            ['quantidade' => 40, 'valor_centavos' => 15000],
        ]);
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get("/admin/eventos/{$cenario->evento->id}/estrutura")
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('lotes', 2)
                ->where('lotes.0.situacao', 'vigente')
                ->where('lotes.0.vagas_ocupadas', 1)
                ->where('lotes.0.inscricoes', 1)
                ->where('lotes.1.situacao', 'futuro')
                // RN-L10 — a soma viaja como informacao, ao lado da capacidade,
                // e nunca como bloqueio.
                ->where('lotes_resumo.soma_quantidades', 70)
                ->where('lotes_resumo.capacidade', 100)
                ->etc());
    });

    it('nao inventa soma quando nenhum lote tem quantidade', function () {
        $cenario = CenarioInscricao::montar()->comLotes([['disponivel_ate' => Carbon::now()->addWeek()]]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get("/admin/eventos/{$cenario->evento->id}/estrutura")
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->where('lotes_resumo.soma_quantidades', null)
                ->etc());
    });

    it('entrega lista vazia para evento sem lotes', function () {
        $evento = Evento::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get("/admin/eventos/{$evento->id}/estrutura")
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina->has('lotes', 0)->etc());
    });
});
