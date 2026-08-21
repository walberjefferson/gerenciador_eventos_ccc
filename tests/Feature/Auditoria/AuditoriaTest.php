<?php

declare(strict_types=1);

use App\Actions\Inscricoes\CancelarInscricaoAdministrativa;
use App\Actions\Pagamentos\ConfirmarPagamentoManual;
use App\Enums\AcaoAuditada;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Auditoria\LogAuditoriaImutavelException;
use App\Models\Cidade;
use App\Models\LogAuditoria;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Admin\Cenario as CenarioAdmin;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricoes;

/**
 * As quatro propriedades que fazem a auditoria valer alguma coisa: ela grava,
 * ela nao pode ser alterada, ela nao guarda dado sensivel e ela nunca derruba
 * a acao que estava auditando.
 */
it('grava quem fez, o que fez, sobre o que e quando', function (): void {
    $usuario = User::factory()->create(['name' => 'Ana Organizadora']);

    $registro = app(RegistrarAcao::class)(
        AcaoAuditada::CancelouInscricao,
        'inscricao',
        123,
        ['situacao' => ['antes' => 'aguardando_pagamento', 'depois' => 'cancelada']],
        'Desistiu por telefone',
        $usuario,
    );

    expect($registro)->toBeInstanceOf(LogAuditoria::class);

    $registro->refresh();

    expect($registro->usuario_id)->toBe($usuario->id)
        ->and($registro->acao)->toBe(AcaoAuditada::CancelouInscricao)
        ->and($registro->entidade)->toBe('inscricao')
        ->and($registro->entidade_id)->toBe(123)
        ->and($registro->motivo)->toBe('Desistiu por telefone')
        ->and($registro->dados)->toBe(['situacao' => ['antes' => 'aguardando_pagamento', 'depois' => 'cancelada']])
        ->and($registro->created_at)->not->toBeNull()
        ->and($registro->responsavel())->toBe('Ana Organizadora');
});

it('aceita acao sem gente por tras, gravando o responsavel como Sistema', function (): void {
    $registro = app(RegistrarAcao::class)(AcaoAuditada::Removeu, 'atividade', 7);

    expect($registro->usuario_id)->toBeNull()
        ->and($registro->responsavel())->toBe('Sistema');
});

it('recusa alterar um registro de auditoria', function (): void {
    $registro = app(RegistrarAcao::class)(AcaoAuditada::Criou, 'evento', 1);

    expect(fn () => $registro->update(['motivo' => 'reescrevendo a historia']))
        ->toThrow(LogAuditoriaImutavelException::class);

    expect($registro->fresh()->motivo)->toBeNull();
});

it('recusa apagar um registro de auditoria', function (): void {
    $registro = app(RegistrarAcao::class)(AcaoAuditada::Criou, 'evento', 1);

    expect(fn () => $registro->delete())
        ->toThrow(LogAuditoriaImutavelException::class);

    expect(LogAuditoria::query()->whereKey($registro->getKey())->exists())->toBeTrue();
});

it('recusa apagar em lote pelo model', function (): void {
    $registro = app(RegistrarAcao::class)(AcaoAuditada::Criou, 'evento', 1);

    expect(fn () => LogAuditoria::query()->whereKey($registro->getKey())->get()->each->delete())
        ->toThrow(LogAuditoriaImutavelException::class);

    expect(LogAuditoria::query()->whereKey($registro->getKey())->exists())->toBeTrue();
});

it('nao guarda CPF, hash de documento, senha, token nem Pix completo', function (): void {
    $registro = app(RegistrarAcao::class)(
        AcaoAuditada::Alterou,
        'inscricao',
        9,
        [
            'alteracoes' => [
                'cpf' => ['antes' => '111.444.777-35', 'depois' => '529.982.247-25'],
                'documento_hash' => ['antes' => 'abc', 'depois' => 'def'],
                'nome_completo' => ['antes' => 'Joao', 'depois' => 'Joao da Silva'],
            ],
            'pix_copia_e_cola' => '00020126580014BR.GOV.BCB.PIX',
            'token_acesso' => 'segredo-que-nao-pode-vazar',
            'senha' => 'senha-em-texto-puro',
        ],
    );

    $gravado = json_encode($registro->fresh()->dados, JSON_UNESCAPED_UNICODE);

    expect($gravado)->not->toContain('111.444.777-35')
        ->and($gravado)->not->toContain('529.982.247-25')
        ->and($gravado)->not->toContain('00020126580014')
        ->and($gravado)->not->toContain('segredo-que-nao-pode-vazar')
        ->and($gravado)->not->toContain('senha-em-texto-puro')
        // O nome do campo continua la: saber que o CPF mudou e auditoria
        // legitima; saber qual e o CPF nao e.
        ->and($gravado)->toContain('cpf')
        ->and($gravado)->toContain('documento_hash')
        // Campo que nao e sensivel passa inteiro.
        ->and($registro->fresh()->dados['alteracoes']['nome_completo']['depois'])->toBe('Joao da Silva');
});

it('nao derruba nada quando a gravacao da auditoria falha', function (): void {
    Log::spy();

    // A forma mais honesta de simular "a gravacao falhou" e tirar a tabela do
    // caminho: qualquer insercao vai estourar de verdade, e nao por causa de
    // um dublê que finge falhar.
    Schema::drop('logs_auditoria');

    $registro = app(RegistrarAcao::class)(AcaoAuditada::CancelouInscricao, 'inscricao', 5);

    expect($registro)->toBeNull();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'Falha ao registrar acao'))
        ->once();
});

it('deixa o efeito da acao de pe mesmo sem conseguir auditar', function (): void {
    Log::spy();

    $usuario = User::factory()->create(['name' => 'Antes da falha']);

    Schema::drop('logs_auditoria');

    // A acao administrativa acontece; a auditoria e chamada logo depois e
    // falha. O que importa e que o efeito da acao continua no banco.
    DB::transaction(function () use ($usuario): void {
        $usuario->update(['name' => 'Depois da acao']);

        app(RegistrarAcao::class)(AcaoAuditada::PromoveuUsuario, 'usuario', (int) $usuario->getKey());
    });

    expect($usuario->fresh()->name)->toBe('Depois da acao');
});

/*
|--------------------------------------------------------------------------
| As acoes administrativas que precisam deixar rastro
|--------------------------------------------------------------------------
|
| Aqui nao se testa o servico de auditoria — isso ja foi feito acima. Testa-se
| se cada porta administrativa do sistema de fato chama o servico, porque uma
| auditoria perfeita que ninguem aciona nao registra nada.
*/

it('registra o cancelamento administrativo com quem, o que e por que', function (): void {
    $cenario = CenarioInscricoes::montar(['capacidade' => 10]);
    $inscricao = $cenario->inscrever();
    $organizador = User::factory()->create(['name' => 'Bruno Organizador']);

    app(CancelarInscricaoAdministrativa::class)($inscricao, 'Desistiu por telefone', $organizador);

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::CancelouInscricao->value)
        ->where('entidade_id', $inscricao->id)
        ->sole();

    expect($registro->usuario_id)->toBe($organizador->id)
        ->and($registro->entidade)->toBe('inscricao')
        ->and($registro->entidade_id)->toBe((int) $inscricao->id)
        ->and($registro->motivo)->toBe('Desistiu por telefone')
        ->and($registro->dados['situacao']['depois'])->toBe(SituacaoInscricao::Cancelada->value);
});

it('nao registra um cancelamento que nao aconteceu', function (): void {
    $cenario = CenarioInscricoes::montar(['capacidade' => 10]);
    $inscricao = $cenario->inscrever();
    $organizador = User::factory()->create();

    app(CancelarInscricaoAdministrativa::class)($inscricao, 'Primeira vez', $organizador);
    // A segunda chamada nao muda nada: a inscricao ja esta cancelada.
    app(CancelarInscricaoAdministrativa::class)($inscricao->fresh(), 'Segunda vez', $organizador);

    expect(
        LogAuditoria::query()
            ->where('acao', AcaoAuditada::CancelouInscricao->value)
            ->where('entidade_id', $inscricao->id)
            ->count()
    )->toBe(1);
});

it('registra a confirmacao manual de pagamento sem guardar dado do provedor', function (): void {
    $cenario = CenarioInscricoes::montar(['capacidade' => 10]);
    $inscricao = $cenario->inscrever();
    $administrador = User::factory()->create(['name' => 'Carla Administradora']);

    app(ConfirmarPagamentoManual::class)(
        $inscricao,
        $administrador,
        MetodoPagamento::Dinheiro,
        'Recebido em dinheiro na secretaria',
    );

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::ConfirmouPagamentoManual->value)
        ->where('entidade_id', $inscricao->id)
        ->sole();

    expect($registro->usuario_id)->toBe($administrador->id)
        ->and($registro->entidade_id)->toBe((int) $inscricao->id)
        ->and($registro->motivo)->toBe('Recebido em dinheiro na secretaria')
        ->and($registro->dados['metodo'])->toBe(MetodoPagamento::Dinheiro->value)
        ->and($registro->dados['valor_centavos'])->toBeInt();
});

it('registra o cadastro, a alteracao e a remocao feitos pelo painel', function (): void {
    CenarioAdmin::semearPapeis();
    $administrador = CenarioAdmin::usuarioCom('administrador');

    $this->actingAs($administrador)
        ->post(route('admin.catalogo.cidades.store'), ['nome' => 'Ribeirão Preto', 'uf' => 'SP', 'ativo' => true])
        ->assertRedirect();

    $cidade = Cidade::query()->where('nome', 'Ribeirão Preto')->sole();

    $this->actingAs($administrador)
        ->put(route('admin.catalogo.cidades.update', $cidade), ['nome' => 'Ribeirão Preto', 'uf' => 'SP', 'ativo' => false])
        ->assertRedirect();

    $this->actingAs($administrador)
        ->delete(route('admin.catalogo.cidades.destroy', $cidade))
        ->assertRedirect();

    $registros = LogAuditoria::query()->daEntidade('cidade', (int) $cidade->id)->orderBy('id')->get();

    expect($registros->pluck('acao')->all())->toBe([
        AcaoAuditada::Criou,
        AcaoAuditada::Alterou,
        AcaoAuditada::Removeu,
    ]);

    expect($registros->every(fn (LogAuditoria $linha): bool => $linha->usuario_id === $administrador->id))->toBeTrue();
    expect($registros[1]->dados['alteracoes'])->toHaveKey('ativo');
});

it('nao registra alteracao quando o formulario foi enviado sem mudar nada', function (): void {
    CenarioAdmin::semearPapeis();
    $administrador = CenarioAdmin::usuarioCom('administrador');
    $cidade = Cidade::factory()->create(['nome' => 'Bauru', 'uf' => 'SP', 'ativo' => true]);

    $this->actingAs($administrador)
        ->put(route('admin.catalogo.cidades.update', $cidade), ['nome' => 'Bauru', 'uf' => 'SP', 'ativo' => true])
        ->assertRedirect();

    expect(
        LogAuditoria::query()
            ->where('acao', AcaoAuditada::Alterou->value)
            ->daEntidade('cidade', (int) $cidade->id)
            ->count()
    )->toBe(0);
});

it('registra a criacao de conta administrativa pela linha de comando', function (): void {
    CenarioAdmin::semearPapeis();

    $this->artisan('usuario:criar-administrador', [
        'email' => 'novo@exemplo.test',
        '--nome' => 'Novo Administrador',
        '--papel' => 'organizador',
    ])->expectsQuestion('Senha (nao aparece na tela, minimo de 8 caracteres)', 'senha-bem-comprida-123')
        ->assertSuccessful();

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::CriouUsuarioAdministrativo->value)
        ->where('entidade_id', User::query()->where('email', 'novo@exemplo.test')->value('id'))
        ->sole();

    expect($registro->entidade)->toBe('usuario')
        ->and($registro->dados['email'])->toBe('novo@exemplo.test')
        ->and($registro->dados['papel'])->toBe('organizador')
        ->and(json_encode($registro->dados))->not->toContain('senha-bem-comprida-123');
});
