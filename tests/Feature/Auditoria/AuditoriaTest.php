<?php

declare(strict_types=1);

use App\Enums\AcaoAuditada;
use App\Exceptions\Auditoria\LogAuditoriaImutavelException;
use App\Models\LogAuditoria;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    expect(LogAuditoria::query()->count())->toBe(1);
});

it('recusa apagar em lote pelo model', function (): void {
    app(RegistrarAcao::class)(AcaoAuditada::Criou, 'evento', 1);

    expect(fn () => LogAuditoria::query()->get()->each->delete())
        ->toThrow(LogAuditoriaImutavelException::class);

    expect(LogAuditoria::query()->count())->toBe(1);
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
