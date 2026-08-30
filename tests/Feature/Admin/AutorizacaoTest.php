<?php

declare(strict_types=1);

use Database\Seeders\PapeisSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Admin\Cenario;

/**
 * Quantas permissoes o sistema tem hoje.
 *
 * O numero e escrito a mao de proposito: contar `PapeisSeeder::PERMISSOES`
 * faria o teste concordar com qualquer coisa que alguem acrescentasse. Ele
 * subiu de 10 para 11 na tela dos avisos do provedor (permissao
 * "pagamentos.avisos-ver", so do administrador). Quem mexer neste numero esta
 * dizendo, por escrito, que criou ou tirou uma permissao.
 */
const TOTAL_DE_PERMISSOES = 11;

it('cria os dois papeis e as onze permissoes', function (): void {
    Cenario::semearPapeis();

    expect(Role::count())->toBe(2)
        ->and(Permission::count())->toBe(TOTAL_DE_PERMISSOES)
        ->and(Role::pluck('name')->sort()->values()->all())->toBe(['administrador', 'organizador']);
});

it('roda duas vezes sem duplicar papel nem permissao', function (): void {
    Cenario::semearPapeis();
    Cenario::semearPapeis();

    expect(Role::count())->toBe(2)
        ->and(Permission::count())->toBe(TOTAL_DE_PERMISSOES);

    $administrador = Role::findByName('administrador');

    expect($administrador->permissions()->count())->toBe(TOTAL_DE_PERMISSOES);
});

it('da todas as permissoes ao administrador', function (): void {
    Cenario::semearPapeis();

    $administrador = Cenario::usuarioCom('administrador');

    foreach (array_keys(PapeisSeeder::PERMISSOES) as $permissao) {
        expect($administrador->can($permissao))->toBeTrue("administrador deveria poder {$permissao}");
    }
});

it('nega ao organizador confirmar pagamento na mao, gerenciar usuarios, ver auditoria e ler os avisos do provedor', function (): void {
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

it('fecha a tela dos avisos do provedor para quem organiza o evento', function (): void {
    Cenario::semearPapeis();

    // Porta fechada, e nao tela vazia: o aviso do provedor e conversa entre o
    // sistema e a instituicao financeira, e ela nao passa por quem toca o
    // evento no dia a dia.
    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get('/admin/pagamentos/avisos')
        ->assertForbidden();

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos')
        ->assertOk();
});

it('fecha a tela de usuarios e a de papeis para quem organiza o evento', function (): void {
    Cenario::semearPapeis();

    // "usuarios.gerenciar" existia desde a Fase 6a sem nenhuma rota que a
    // exigisse. A partir daqui ela e a porta destas duas telas — e continua
    // fora do alcance de quem organiza o evento.
    $organizador = Cenario::usuarioCom('organizador');

    $this->actingAs($organizador)->get('/admin/usuarios')->assertForbidden();
    $this->actingAs($organizador)->get('/admin/papeis')->assertForbidden();

    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)->get('/admin/usuarios')->assertOk();
    $this->actingAs($administrador)->get('/admin/papeis')->assertOk();
});

it('nao cria permissao nenhuma ao ganhar a tela de usuarios', function (): void {
    Cenario::semearPapeis();

    // A tela de usuarios NAO trouxe permissao nova: ela passou a usar uma que
    // ja existia. Se este numero mudar por causa desta feature, algo saiu
    // errado — e a resposta certa e voltar atras, nao ajustar o numero.
    expect(Permission::count())->toBe(TOTAL_DE_PERMISSOES)
        ->and(Role::findByName('administrador')->permissions()->count())->toBe(TOTAL_DE_PERMISSOES)
        ->and(Permission::query()->where('name', 'usuarios.gerenciar')->exists())->toBeTrue();
});

it('exige a permissao de gerenciar usuarios nas quatro rotas novas', function (): void {
    foreach (['admin.usuarios.index', 'admin.usuarios.papel', 'admin.usuarios.situacao', 'admin.papeis'] as $nome) {
        $rota = Route::getRoutes()->getByName($nome);

        expect($rota)->not->toBeNull("a rota {$nome} precisa existir")
            ->and($rota->gatherMiddleware())->toContain('permission:usuarios.gerenciar');
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
