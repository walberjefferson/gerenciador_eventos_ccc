<?php

declare(strict_types=1);

use App\Models\Evento;
use App\Models\Ingresso;
use App\Models\Inscricao;
use Database\Seeders\PapeisSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;

/*
|--------------------------------------------------------------------------
| O que a portaria alcanca — e, sobretudo, o que ela NAO alcanca
|--------------------------------------------------------------------------
|
| O papel "portaria" e o mais estreito do sistema: uma permissao, uma tela.
| Quem esta no portao costuma ser voluntario, com o celular emprestado, no meio
| de uma fila — e nao tem por que alcancar lista de inscritos, dado pessoal,
| dinheiro nem auditoria.
|
| Este arquivo percorre CADA porta esperando 403. Um teste que so verificasse a
| tela da portaria funcionando deixaria passar exatamente o erro que importa:
| uma rota nova nascendo sem permissao e ficando aberta para o portao.
|
*/

beforeEach(function (): void {
    Cenario::semearPapeis();

    $this->portaria = Cenario::usuarioCom('portaria');
    $this->organizador = Cenario::usuarioCom('organizador');
    $this->administrador = Cenario::usuarioCom('administrador');
});

it('da a portaria uma unica permissao, e nao a de desfazer', function (): void {
    expect($this->portaria->can('presenca.registrar'))->toBeTrue()
        ->and($this->portaria->can('presenca.desfazer'))->toBeFalse()
        ->and($this->portaria->getAllPermissions()->pluck('name')->all())->toBe(['presenca.registrar']);
});

it('da as duas permissoes de presenca ao administrador e ao organizador', function (): void {
    // Desfazer fica com quem organiza porque e quem esta la no dia e conserta o
    // engano do portao. Tirar isso do organizador deixaria a fila parada
    // esperando o administrador aparecer.
    foreach ([$this->administrador, $this->organizador] as $pessoa) {
        expect($pessoa->can('presenca.registrar'))->toBeTrue()
            ->and($pessoa->can('presenca.desfazer'))->toBeTrue();
    }
});

it('fecha para a portaria todas as outras telas do painel', function (): void {
    $portas = [
        '/admin/painel',
        '/admin/inscricoes',
        '/admin/eventos',
        '/admin/usuarios',
        '/admin/papeis',
        '/admin/auditoria',
        '/admin/catalogo/setores',
        '/admin/pagamentos/avisos',
        '/admin/pagamentos/credenciais',
    ];

    foreach ($portas as $porta) {
        $this->actingAs($this->portaria)
            ->get($porta)
            ->assertForbidden("a portaria nao pode alcancar {$porta}");
    }
});

it('abre a tela da portaria para quem tem a permissao', function (): void {
    Evento::factory()->create();

    $this->actingAs($this->portaria)
        ->get('/admin/portaria')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Admin/Portaria/Index')
            // O botao de desfazer nem chega ao navegador de quem nao pode
            // desfazer: oferecer um caminho que o servidor recusaria com 403
            // ensina a pessoa a ignorar a tela.
            ->where('pode_desfazer', false)
        );
});

it('leva quem so tem o portao para a portaria, e nao para um 403 do painel', function (): void {
    Evento::factory()->create();

    // O defeito que este teste guarda: antes, "/admin" redirecionava sempre
    // para o painel, que exige "painel.ver". O voluntario entrava pelo endereco
    // mais obvio do sistema e levava 403 na cara, sem nenhuma pista de que
    // existe uma tela para ele.
    $this->actingAs($this->portaria)
        ->get('/admin')
        ->assertRedirect('/admin/portaria');

    $this->actingAs($this->organizador)
        ->get('/admin')
        ->assertRedirect('/admin/painel');

    $this->actingAs($this->administrador)
        ->get('/admin')
        ->assertRedirect('/admin/painel');
});

it('recusa a entrada do painel para quem nao tem nenhum dos dois destinos', function (): void {
    $this->actingAs(Cenario::usuarioCom())
        ->get('/admin')
        ->assertForbidden();
});

it('deixa a portaria registrar uma entrada pela tela', function (): void {
    $evento = Evento::factory()->create();
    $inscricao = Inscricao::factory()->confirmada()->create(['evento_id' => $evento->id]);
    $ingresso = Ingresso::factory()->create(['inscricao_id' => $inscricao->id]);

    $this->actingAs($this->portaria)
        ->post('/admin/portaria/validar', ['evento_id' => $evento->id, 'codigo' => $ingresso->codigo])
        ->assertRedirect()
        ->assertSessionHas('resultado', fn (array $resultado): bool => $resultado['aceito'] === true);

    expect($ingresso->fresh()->usado_em)->not->toBeNull();
});

it('recusa a portaria no desfazer, e deixa o organizador desfazer', function (): void {
    $evento = Evento::factory()->create();
    $inscricao = Inscricao::factory()->confirmada()->create(['evento_id' => $evento->id]);
    $ingresso = Ingresso::factory()->usado($this->portaria)->create(['inscricao_id' => $inscricao->id]);

    $this->actingAs($this->portaria)
        ->post("/admin/portaria/ingressos/{$ingresso->id}/desfazer")
        ->assertForbidden();

    // E nada mudou: a recusa nao pode ter efeito colateral nenhum.
    expect($ingresso->fresh()->usado_em)->not->toBeNull();

    $this->actingAs($this->organizador)
        ->post("/admin/portaria/ingressos/{$ingresso->id}/desfazer")
        ->assertRedirect();

    expect($ingresso->fresh()->usado_em)->toBeNull();
});

it('recusa quem nao esta logado antes de qualquer regra', function (): void {
    $this->get('/admin/portaria')->assertRedirect('/login');
    $this->post('/admin/portaria/validar', ['evento_id' => 1, 'codigo' => 'ABCD2345JKMN'])->assertRedirect('/login');
});

it('exige permissao e limite de tentativas nas rotas da portaria', function (): void {
    $index = Route::getRoutes()->getByName('admin.portaria.index');
    $validar = Route::getRoutes()->getByName('admin.portaria.validar');
    $desfazer = Route::getRoutes()->getByName('admin.portaria.desfazer');

    expect($index->gatherMiddleware())->toContain('permission:presenca.registrar')
        ->and($validar->gatherMiddleware())->toContain('permission:presenca.registrar')
        ->and($desfazer->gatherMiddleware())->toContain('permission:presenca.desfazer');

    // Sessenta bits de entropia tornam a adivinhacao inviavel, mas rota de
    // conferencia sem limite nenhum e convite a varredura.
    $temThrottle = collect($validar->gatherMiddleware())
        ->contains(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

    expect($temThrottle)->toBeTrue('a rota de validacao precisa de throttle');
});

it('mostra as duas permissoes novas na tela de papeis, com a explicacao', function (): void {
    $this->actingAs($this->administrador)
        ->get('/admin/papeis')
        ->assertOk()
        ->assertInertia(function (Assert $pagina): void {
            $permissoes = collect($pagina->toArray()['props']['permissoes']);

            expect($permissoes->firstWhere('nome', 'presenca.registrar')['explicacao'])
                ->toBe(PapeisSeeder::PERMISSOES['presenca.registrar'])
                ->and($permissoes->firstWhere('nome', 'presenca.desfazer')['explicacao'])
                ->toBe(PapeisSeeder::PERMISSOES['presenca.desfazer']);

            $papeis = collect($pagina->toArray()['props']['papeis']);

            expect($papeis->pluck('nome')->all())->toContain('portaria');
        });
});

it('recusa codigo com tamanho ou caractere impossivel antes de ir ao banco', function (): void {
    $evento = Evento::factory()->create();

    $this->actingAs($this->portaria)
        ->post('/admin/portaria/validar', ['evento_id' => $evento->id, 'codigo' => 'ABC'])
        ->assertSessionHasErrors('codigo');
});
