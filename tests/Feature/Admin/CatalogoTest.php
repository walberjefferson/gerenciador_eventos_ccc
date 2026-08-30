<?php

declare(strict_types=1);

use App\Models\Cidade;
use App\Models\GrupoParticipante;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * O catalogo global: setores e grupos de participantes.
 *
 * A tela e a URL dizem "setor"; o Model e a tabela continuam sendo
 * `Cidade`/`cidades`. Por isso os testes montam `Cidade::factory()` e batem em
 * `/admin/catalogo/setores`.
 *
 * O que precisa ficar provado e sempre o mesmo: cadastro que ja esta em uso
 * nao some do banco, e quem tenta apagar recebe uma frase em portugues
 * explicando o que fazer — nunca um erro de banco de dados na tela.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

describe('setores', function () {
    it('abre a lista para quem gerencia o catalogo', function () {
        Cidade::factory()->create(['nome' => 'Setor Batalha', 'uf' => 'AL']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/catalogo/setores')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Admin/Catalogo/Setores')
                ->has('cidades', 1)
                ->where('cidades.0.nome', 'Setor Batalha'));
    });

    it('recusa com 403 quem nao tem papel nenhum', function () {
        $this->actingAs(Cenario::usuarioCom())
            ->get('/admin/catalogo/setores')
            ->assertForbidden();
    });

    // O endereco antigo saiu de circulacao sem redirecionamento: o sistema nao
    // esta publicado e ninguem tem esse link guardado.
    it('nao responde mais no endereco antigo', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->get('/admin/catalogo/cidades')
            ->assertNotFound();
    });

    it('cadastra um setor', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/setores', ['nome' => 'Setor Palmeira', 'uf' => 'al'])
            ->assertRedirect();

        expect(Cidade::where('nome', 'Setor Palmeira')->where('uf', 'AL')->exists())->toBeTrue();
    });

    // O binding de rota casa pelo NOME do parametro (`{setor}`), nao pelo da
    // classe: e o que permite a URL dizer "setor" e o type-hint continuar
    // sendo `Cidade`. Esta prova edita um setor pela rota nova de ponta a ponta.
    it('edita um setor pela rota nova, com o binding resolvendo {setor}', function () {
        $setor = Cidade::factory()->create(['nome' => 'Setor Santana', 'uf' => 'AL']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put(route('admin.catalogo.setores.update', ['setor' => $setor->id]), [
                'nome' => 'Setor Santana do Ipanema',
                'uf' => 'AL',
                'ativo' => true,
            ])
            ->assertSessionHasNoErrors();

        expect($setor->fresh()->nome)->toBe('Setor Santana do Ipanema');
    });

    it('recusa nome repetido no mesmo estado antes de o banco reclamar', function () {
        Cidade::factory()->create(['nome' => 'Setor Batalha', 'uf' => 'AL']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/setores', ['nome' => 'Setor Batalha', 'uf' => 'AL'])
            ->assertSessionHasErrors('nome');

        expect(Cidade::count())->toBe(1);
    });

    it('aceita o mesmo nome em estados diferentes', function () {
        Cidade::factory()->create(['nome' => 'Setor Batalha', 'uf' => 'AL']);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/setores', ['nome' => 'Setor Batalha', 'uf' => 'SE'])
            ->assertSessionHasNoErrors();

        expect(Cidade::count())->toBe(2);
    });

    it('recusa sigla de estado que nao existe', function () {
        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/setores', ['nome' => 'Setor Novo', 'uf' => 'XX'])
            ->assertSessionHasErrors('uf');
    });

    it('recusa apagar setor em uso, explicando o caminho certo', function () {
        $setor = Cidade::factory()->create();
        GrupoParticipante::factory()->for($setor)->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/catalogo/setores/{$setor->id}")
            ->assertSessionHasErrors('exclusao');

        expect(Cidade::whereKey($setor->id)->exists())->toBeTrue();

        $erro = session('errors')->first('exclusao');

        expect($erro)->toContain('Desative o setor');
    });

    it('apaga setor que ninguem usa', function () {
        $setor = Cidade::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->delete("/admin/catalogo/setores/{$setor->id}")
            ->assertSessionHasNoErrors();

        expect(Cidade::whereKey($setor->id)->exists())->toBeFalse();
    });

    it('desativa em vez de apagar, quando e esse o caminho', function () {
        $setor = Cidade::factory()->create(['ativo' => true]);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->put("/admin/catalogo/setores/{$setor->id}", [
                'nome' => $setor->nome,
                'uf' => $setor->uf,
                'ativo' => false,
            ])
            ->assertSessionHasNoErrors();

        expect($setor->fresh()->ativo)->toBeFalse();
    });
});

describe('grupos de participantes', function () {
    it('cadastra um grupo num setor', function () {
        $cidade = Cidade::factory()->create();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post('/admin/catalogo/grupos-participantes', [
                'cidade_id' => $cidade->id,
                'nome' => 'Grupo do Centro',
            ])
            ->assertSessionHasNoErrors();

        expect(GrupoParticipante::where('nome', 'Grupo do Centro')->exists())->toBeTrue();
    });

    it('recusa nome repetido no mesmo setor', function () {
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
