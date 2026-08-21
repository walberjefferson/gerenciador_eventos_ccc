<?php

declare(strict_types=1);

use Database\Seeders\PapeisSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Admin\Cenario;

it('cria os dois papeis e as nove permissoes', function (): void {
    Cenario::semearPapeis();

    expect(Role::count())->toBe(2)
        ->and(Permission::count())->toBe(9)
        ->and(Role::pluck('name')->sort()->values()->all())->toBe(['administrador', 'organizador']);
});

it('roda duas vezes sem duplicar papel nem permissao', function (): void {
    Cenario::semearPapeis();
    Cenario::semearPapeis();

    expect(Role::count())->toBe(2)
        ->and(Permission::count())->toBe(9);

    $administrador = Role::findByName('administrador');

    expect($administrador->permissions()->count())->toBe(9);
});

it('da todas as permissoes ao administrador', function (): void {
    Cenario::semearPapeis();

    $administrador = Cenario::usuarioCom('administrador');

    foreach (array_keys(PapeisSeeder::PERMISSOES) as $permissao) {
        expect($administrador->can($permissao))->toBeTrue("administrador deveria poder {$permissao}");
    }
});

it('nega ao organizador confirmar pagamento na mao, gerenciar usuarios e ver auditoria', function (): void {
    Cenario::semearPapeis();

    $organizador = Cenario::usuarioCom('organizador');

    foreach (PapeisSeeder::FORA_DO_ORGANIZADOR as $permissao) {
        expect($organizador->can($permissao))->toBeFalse("organizador nao deveria poder {$permissao}");
    }
});

it('permite ao organizador o trabalho do dia a dia', function (): void {
    Cenario::semearPapeis();

    $organizador = Cenario::usuarioCom('organizador');

    foreach (PapeisSeeder::permissoesDoOrganizador() as $permissao) {
        expect($organizador->can($permissao))->toBeTrue("organizador deveria poder {$permissao}");
    }
});

it('nao da permissao nenhuma a quem nao tem papel', function (): void {
    Cenario::semearPapeis();

    $semPapel = Cenario::usuarioCom();

    foreach (array_keys(PapeisSeeder::PERMISSOES) as $permissao) {
        expect($semPapel->can($permissao))->toBeFalse("usuario sem papel nao deveria poder {$permissao}");
    }
});

it('manda o visitante para o login antes de mostrar o painel', function (): void {
    Cenario::semearPapeis();

    $this->get('/admin/painel')->assertRedirect('/login');
});

it('recusa com 403 quem esta logado mas nao tem papel nenhum', function (): void {
    Cenario::semearPapeis();

    $this->actingAs(Cenario::usuarioCom())
        ->get('/admin/painel')
        ->assertForbidden();
});

it('mantem a exigencia de e-mail confirmado no grupo administrativo', function (): void {
    // O grupo carrega "verified". Ele so passa a barrar de fato quando o model
    // User declarar MustVerifyEmail — hoje a declaracao vem comentada do pacote
    // inicial, e mexer nisso muda a autenticacao inteira, o que nao e desta
    // fase. Fica registrado como pendencia no PROGRESS.
    $painel = Route::getRoutes()->getByName('admin.painel');

    expect($painel)->not->toBeNull()
        ->and($painel->gatherMiddleware())->toContain('verified');
});

it('abre o painel para o administrador', function (): void {
    Cenario::semearPapeis();

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/painel')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->component('Admin/Painel'));
});

it('abre o painel para o organizador', function (): void {
    Cenario::semearPapeis();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get('/admin/painel')
        ->assertOk();
});

it('nao deixa nenhuma rota administrativa protegida apenas por login', function (): void {
    $rotasAdministrativas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($rota): bool => str_starts_with((string) $rota->getName(), 'admin.'));

    expect($rotasAdministrativas)->not->toBeEmpty();

    foreach ($rotasAdministrativas as $rota) {
        $middlewares = $rota->gatherMiddleware();

        $temPermissao = collect($middlewares)->contains(
            fn ($middleware): bool => is_string($middleware)
                && (str_starts_with($middleware, 'permission:') || str_starts_with($middleware, 'role:'))
        );

        expect($middlewares)->toContain('auth')
            ->and($temPermissao)->toBeTrue(sprintf('a rota %s precisa exigir uma permissao', $rota->getName()));
    }
});
