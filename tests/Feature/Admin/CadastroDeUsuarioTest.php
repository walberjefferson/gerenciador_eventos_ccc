<?php

declare(strict_types=1);

use App\Enums\AcaoAuditada;
use App\Models\LogAuditoria;
use App\Models\User;
use Database\Seeders\PapeisSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Admin\Cenario;

/**
 * Cadastrar, editar e trocar a senha de uma conta administrativa PELA TELA.
 *
 * Ate a feature anterior a conta so nascia por `usuario:criar-administrador`,
 * dentro do container (D-51). O dono do produto reverteu essa parte: quem
 * responde pelo sistema monta a equipe sozinho.
 *
 * O que estes cenarios existem para vigiar nao e "o formulario salva" — e que
 * as travas da tela anterior NAO ficaram para tras num caminho novo. Um
 * formulario maior, que manda nome, e-mail e papel juntos, e exatamente o tipo
 * de porta por onde uma regra escapa sem ninguem perceber.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

it('cadastra uma conta e ela ja entra', function (): void {
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $this->actingAs($administrador)
        ->post('/admin/usuarios', [
            'name' => 'Marta da Silva',
            'email' => 'marta@example.com',
            'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
            'password' => 'senha-bem-comprida-123',
            'password_confirmation' => 'senha-bem-comprida-123',
        ])
        ->assertSessionHasNoErrors();

    $criada = User::query()->where('email', 'marta@example.com')->firstOrFail();

    expect($criada->hasRole(PapeisSeeder::PAPEL_ORGANIZADOR))->toBeTrue()
        ->and($criada->ativo)->toBeTrue()
        // Sem isto ela cairia na exigencia de e-mail confirmado do grupo /admin
        // e nao entraria em lugar nenhum, sem o sistema dizer por que.
        ->and($criada->email_verified_at)->not->toBeNull()
        ->and(Hash::check('senha-bem-comprida-123', $criada->password))->toBeTrue();
});

it('recusa cadastrar duas contas com o mesmo e-mail', function (): void {
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $this->actingAs($administrador)
        ->post('/admin/usuarios', [
            'name' => 'Outra Pessoa',
            'email' => $administrador->email,
            'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
            'password' => 'senha-bem-comprida-123',
            'password_confirmation' => 'senha-bem-comprida-123',
        ])
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', $administrador->email)->count())->toBe(1);
});

it('recusa cadastrar quando as duas senhas nao batem', function (): void {
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $this->actingAs($administrador)
        ->post('/admin/usuarios', [
            'name' => 'Marta da Silva',
            'email' => 'marta@example.com',
            'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
            'password' => 'senha-bem-comprida-123',
            'password_confirmation' => 'outra-coisa-qualquer',
        ])
        ->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'marta@example.com')->exists())->toBeFalse();
});

it('corrige nome e e-mail, e o antes e o depois ficam na auditoria', function (): void {
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

    $emailAntigo = $alvo->email;

    $this->actingAs($administrador)
        ->put("/admin/usuarios/{$alvo->id}", [
            'name' => 'Nome Corrigido',
            'email' => 'corrigido@example.com',
            'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
        ])
        ->assertSessionHasNoErrors();

    $alvo->refresh();

    expect($alvo->name)->toBe('Nome Corrigido')
        ->and($alvo->email)->toBe('corrigido@example.com');

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::AlterouDadosDoUsuario->value)
        ->latest('id')
        ->firstOrFail();

    expect($registro->dados['email']['antes'])->toBe($emailAntigo)
        ->and($registro->dados['email']['depois'])->toBe('corrigido@example.com');
});

it('deixa a pessoa corrigir os proprios dados', function (): void {
    // Corrigir o proprio nome nao tranca ninguem para fora — diferente de
    // mexer no proprio papel ou desativar a si mesmo, que continuam barrados.
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $this->actingAs($administrador)
        ->put("/admin/usuarios/{$administrador->id}", [
            'name' => 'Meu Nome Certo',
            'email' => $administrador->email,
            'papel' => PapeisSeeder::PAPEL_ADMINISTRADOR,
        ])
        ->assertSessionHasNoErrors();

    expect($administrador->fresh()->name)->toBe('Meu Nome Certo');
});

it('nao deixa a edicao virar atalho para mudar o proprio papel', function (): void {
    // A porta nova nao pode ser mais fraca que a antiga: o papel viaja no mesmo
    // formulario, mas passa pela mesma trava da rota dedicada.
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $this->actingAs($administrador)
        ->put("/admin/usuarios/{$administrador->id}", [
            'name' => $administrador->name,
            'email' => $administrador->email,
            'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
        ])
        ->assertSessionHasErrors('papel');

    expect($administrador->fresh()->hasRole(PapeisSeeder::PAPEL_ADMINISTRADOR))->toBeTrue();
});

it('define a senha na hora, e ela nao aparece na auditoria', function (): void {
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

    $this->actingAs($administrador)
        ->put("/admin/usuarios/{$alvo->id}", [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
            'password' => 'senha-nova-bem-comprida',
            'password_confirmation' => 'senha-nova-bem-comprida',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('senha-nova-bem-comprida', $alvo->fresh()->password))->toBeTrue();

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::RedefiniuSenhaDeUsuario->value)
        ->latest('id')
        ->firstOrFail();

    // Nem a senha, nem o hash dela. O registro diz QUE aconteceu e por qual
    // caminho — e mais que isso seria guardar o que ninguem deveria reler.
    $conteudo = json_encode($registro->dados, JSON_THROW_ON_ERROR);

    expect($conteudo)->not->toContain('senha-nova-bem-comprida')
        ->and($conteudo)->not->toContain('$2y$')
        ->and($registro->dados['caminho'])->toBe('senha definida na tela');
});

it('nao mexe na senha quando o campo vem em branco', function (): void {
    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

    $hashAntes = $alvo->password;

    $this->actingAs($administrador)
        ->put("/admin/usuarios/{$alvo->id}", [
            'name' => 'So O Nome Mudou',
            'email' => $alvo->email,
            'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($alvo->fresh()->password)->toBe($hashAntes);
});

it('manda o link de redefinicao sem ninguem saber a senha', function (): void {
    Notification::fake();

    $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

    $this->actingAs($administrador)
        ->post("/admin/usuarios/{$alvo->id}/redefinir-senha")
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($alvo, ResetPassword::class);

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::RedefiniuSenhaDeUsuario->value)
        ->latest('id')
        ->firstOrFail();

    expect($registro->dados['caminho'])->toBe('link enviado por e-mail');
});

it('recusa ao organizador cadastrar, editar e redefinir senha', function (): void {
    $organizador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);
    $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

    $this->actingAs($organizador)->post('/admin/usuarios', [
        'name' => 'Nao Deveria Nascer',
        'email' => 'nao@example.com',
        'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
        'password' => 'senha-bem-comprida-123',
        'password_confirmation' => 'senha-bem-comprida-123',
    ])->assertForbidden();

    $this->actingAs($organizador)->put("/admin/usuarios/{$alvo->id}", [
        'name' => 'Nao Deveria Mudar',
        'email' => $alvo->email,
        'papel' => PapeisSeeder::PAPEL_ORGANIZADOR,
    ])->assertForbidden();

    $this->actingAs($organizador)
        ->post("/admin/usuarios/{$alvo->id}/redefinir-senha")
        ->assertForbidden();

    expect(User::query()->where('email', 'nao@example.com')->exists())->toBeFalse();
});
