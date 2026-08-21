<?php

declare(strict_types=1);

use App\Models\Cidade;
use App\Models\GrupoParticipante;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * O catalogo global: cidades e grupos de participantes.
 *
 * O que precisa ficar provado e sempre o mesmo: cadastro que ja esta em uso
 * nao some do banco, e quem tenta apagar recebe uma frase em portugues
 * explicando o que fazer — nunca um erro de banco de dados na tela.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

describe('cidades', function () {
    it('abre a lista para quem gerencia o catalogo', function () {
        Cidade::factory()->create(['nome' => 'Franca', 'uf' => 'SP']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/catalogo/cidades')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Admin/Catalogo/Cidades')
                ->has('cidades', 1)
                ->where('cidades.0.nome', 'Franca'));
    });

    it('recusa com 403 quem nao tem papel nenhum', function () {
        $this->actingAs(Cenario::usuarioCom())
            ->get('/admin/catalogo/cidades')
            ->assertForbidden();
    });

    it('cadastra uma cidade', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/cidades', ['nome' => 'Ribeirão Preto', 'uf' => 'sp'])
            ->assertRedirect();

        expect(Cidade::where('nome', 'Ribeirão Preto')->where('uf', 'SP')->exists())->toBeTrue();
    });

    it('recusa nome repetido no mesmo estado antes de o banco reclamar', function () {
        Cidade::factory()->create(['nome' => 'Franca', 'uf' => 'SP']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/cidades', ['nome' => 'Franca', 'uf' => 'SP'])
            ->assertSessionHasErrors('nome');

        expect(Cidade::count())->toBe(1);
    });

    it('aceita o mesmo nome em estados diferentes', function () {
        Cidade::factory()->create(['nome' => 'Franca', 'uf' => 'SP']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/cidades', ['nome' => 'Franca', 'uf' => 'MG'])
            ->assertSessionHasNoErrors();

        expect(Cidade::count())->toBe(2);
    });

    it('recusa sigla de estado que nao existe', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/cidades', ['nome' => 'Cidade Nova', 'uf' => 'XX'])
            ->assertSessionHasErrors('uf');
    });

    it('recusa apagar cidade em uso, explicando o caminho certo', function () {
        $cidade = Cidade::factory()->create();
        GrupoParticipante::factory()->for($cidade)->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/catalogo/cidades/{$cidade->id}")
            ->assertSessionHasErrors('exclusao');

        expect(Cidade::whereKey($cidade->id)->exists())->toBeTrue();

        $erro = session('errors')->first('exclusao');

        expect($erro)->toContain('Desative a cidade');
    });

    it('apaga cidade que ninguem usa', function () {
        $cidade = Cidade::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/catalogo/cidades/{$cidade->id}")
            ->assertSessionHasNoErrors();

        expect(Cidade::whereKey($cidade->id)->exists())->toBeFalse();
    });

    it('desativa em vez de apagar, quando e esse o caminho', function () {
        $cidade = Cidade::factory()->create(['ativo' => true]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/catalogo/cidades/{$cidade->id}", [
                'nome' => $cidade->nome,
                'uf' => $cidade->uf,
                'ativo' => false,
            ])
            ->assertSessionHasNoErrors();

        expect($cidade->fresh()->ativo)->toBeFalse();
    });
});

describe('grupos de participantes', function () {
    it('cadastra um grupo numa cidade', function () {
        $cidade = Cidade::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/grupos-participantes', [
                'cidade_id' => $cidade->id,
                'nome' => 'Grupo do Centro',
            ])
            ->assertSessionHasNoErrors();

        expect(GrupoParticipante::where('nome', 'Grupo do Centro')->exists())->toBeTrue();
    });

    it('recusa nome repetido na mesma cidade', function () {
        $cidade = Cidade::factory()->create();
        GrupoParticipante::factory()->for($cidade)->create(['nome' => 'Grupo do Centro']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/grupos-participantes', [
                'cidade_id' => $cidade->id,
                'nome' => 'Grupo do Centro',
            ])
            ->assertSessionHasErrors('nome');

        expect(GrupoParticipante::count())->toBe(1);
    });

    it('recusa apagar grupo com inscricao, sem apagar resposta de ninguem', function () {
        $cenario = CenarioInscricao::montar(['capacidade' => 10]);
        $cenario->inscrever();

        $grupo = $cenario->grupoParticipante;

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/catalogo/grupos-participantes/{$grupo->id}")
            ->assertSessionHasErrors('exclusao');

        expect(GrupoParticipante::whereKey($grupo->id)->exists())->toBeTrue();

        expect(session('errors')->first('exclusao'))->toContain('Desative o grupo');
    });

    it('mostra na lista quantas inscricoes dependem de cada grupo', function () {
        $cenario = CenarioInscricao::montar(['capacidade' => 10]);
        $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/catalogo/grupos-participantes')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Admin/Catalogo/GruposParticipantes')
                ->where('grupos.0.inscricoes', 1));
    });
});
