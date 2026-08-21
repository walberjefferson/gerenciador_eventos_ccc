<?php

declare(strict_types=1);

use Database\Seeders\PapeisSeeder;
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
