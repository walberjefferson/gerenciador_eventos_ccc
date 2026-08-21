<?php

declare(strict_types=1);

use App\Enums\AcaoAuditada;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Admin\Cenario;

/**
 * A tela que mostra o rastro.
 *
 * Ela so precisa provar quatro coisas: quem pode abrir, quem nao pode, que os
 * filtros de fato estreitam o resultado e que a lista pagina em vez de
 * despejar tudo de uma vez.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

it('deixa o administrador abrir a tela de auditoria', function (): void {
    $administrador = Cenario::usuarioCom('administrador');

    app(RegistrarAcao::class)(AcaoAuditada::CancelouInscricao, 'inscricao', 42, [], 'Desistiu', $administrador);

    $this->actingAs($administrador)
        ->get('/admin/auditoria')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->component('Admin/Auditoria/Index')
            ->has('registros.dados', 1)
            ->where('registros.dados.0.acao', AcaoAuditada::CancelouInscricao->value)
            ->where('registros.dados.0.entidade', 'inscricao')
            ->where('registros.dados.0.motivo', 'Desistiu')
            ->where('registros.dados.0.responsavel', $administrador->name)
            ->has('opcoes.acoes')
            ->has('opcoes.usuarios', 1)
        );
});

it('recusa a tela de auditoria para o organizador', function (): void {
    $organizador = Cenario::usuarioCom('organizador');

    $this->actingAs($organizador)
        ->get('/admin/auditoria')
        ->assertForbidden();
});

it('exige estar autenticado para ver a auditoria', function (): void {
    $this->get('/admin/auditoria')->assertRedirect('/login');
});

it('filtra o rastro por acao, por quem fez e por periodo', function (): void {
    $administrador = Cenario::usuarioCom('administrador');
    $outro = User::factory()->create(['name' => 'Bruno Auditado']);

    app(RegistrarAcao::class)(AcaoAuditada::CancelouInscricao, 'inscricao', 1, [], null, $administrador);
    app(RegistrarAcao::class)(AcaoAuditada::Criou, 'evento', 2, [], null, $outro);

    // Por acao.
    $this->actingAs($administrador)
        ->get('/admin/auditoria?acao='.AcaoAuditada::Criou->value)
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->has('registros.dados', 1)
            ->where('registros.dados.0.entidade', 'evento')
        );

    // Por quem fez.
    $this->actingAs($administrador)
        ->get('/admin/auditoria?usuario_id='.$outro->id)
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->has('registros.dados', 1)
            ->where('registros.dados.0.responsavel', 'Bruno Auditado')
        );

    // Por periodo: um intervalo no passado nao pode devolver nada de hoje.
    $this->actingAs($administrador)
        ->get('/admin/auditoria?de=2000-01-01&ate=2000-01-31')
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina->has('registros.dados', 0));

    // E o intervalo que contem hoje devolve os dois.
    $this->actingAs($administrador)
        ->get('/admin/auditoria?de='.now()->toDateString().'&ate='.now()->toDateString())
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina->has('registros.dados', 2));
});

it('pagina o rastro em vez de despejar tudo de uma vez', function (): void {
    $administrador = Cenario::usuarioCom('administrador');

    foreach (range(1, 30) as $numero) {
        app(RegistrarAcao::class)(AcaoAuditada::Alterou, 'atividade', $numero, [], null, $administrador);
    }

    $this->actingAs($administrador)
        ->get('/admin/auditoria')
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina
            ->has('registros.dados', 25)
            ->where('registros.total', 30)
            ->where('registros.ultima_pagina', 2)
        );

    $this->actingAs($administrador)
        ->get('/admin/auditoria?page=2')
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina->has('registros.dados', 5));
});

it('nao expoe rota para alterar nem apagar registro de auditoria', function (): void {
    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)->put('/admin/auditoria/1')->assertNotFound();
    $this->actingAs($administrador)->delete('/admin/auditoria/1')->assertNotFound();
    $this->actingAs($administrador)->post('/admin/auditoria')->assertMethodNotAllowed();
});
