<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PapeisSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Semeia papeis e permissoes e limpa o cache do pacote.
 *
 * Sem limpar, a asserção seguinte le o retrato antigo e falha sem explicacao
 * nenhuma na tela.
 */
function semearPapeis(): void
{
    (new PapeisSeeder)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * Cria um usuario ja verificado com o papel pedido.
 */
function usuarioCom(?string $papel = null): User
{
    $usuario = User::factory()->create();

    if ($papel !== null) {
        $usuario->assignRole($papel);
    }

    return $usuario->fresh();
}

it('cria os dois papeis e as nove permissoes', function (): void {
    semearPapeis();

    expect(Role::count())->toBe(2)
        ->and(Permission::count())->toBe(9)
        ->and(Role::pluck('name')->sort()->values()->all())->toBe(['administrador', 'organizador']);
});

it('roda duas vezes sem duplicar papel nem permissao', function (): void {
    semearPapeis();
    semearPapeis();

    expect(Role::count())->toBe(2)
        ->and(Permission::count())->toBe(9);

    $administrador = Role::findByName('administrador');

    expect($administrador->permissions()->count())->toBe(9);
});

it('da todas as permissoes ao administrador', function (): void {
    semearPapeis();

    $administrador = usuarioCom('administrador');

    foreach (array_keys(PapeisSeeder::PERMISSOES) as $permissao) {
        expect($administrador->can($permissao))->toBeTrue("administrador deveria poder {$permissao}");
    }
});

it('nega ao organizador confirmar pagamento na mao, gerenciar usuarios e ver auditoria', function (): void {
    semearPapeis();

    $organizador = usuarioCom('organizador');

    foreach (PapeisSeeder::FORA_DO_ORGANIZADOR as $permissao) {
        expect($organizador->can($permissao))->toBeFalse("organizador nao deveria poder {$permissao}");
    }
});

it('permite ao organizador o trabalho do dia a dia', function (): void {
    semearPapeis();

    $organizador = usuarioCom('organizador');

    foreach (PapeisSeeder::permissoesDoOrganizador() as $permissao) {
        expect($organizador->can($permissao))->toBeTrue("organizador deveria poder {$permissao}");
    }
});

it('nao da permissao nenhuma a quem nao tem papel', function (): void {
    semearPapeis();

    $semPapel = usuarioCom();

    foreach (array_keys(PapeisSeeder::PERMISSOES) as $permissao) {
        expect($semPapel->can($permissao))->toBeFalse("usuario sem papel nao deveria poder {$permissao}");
    }
});
