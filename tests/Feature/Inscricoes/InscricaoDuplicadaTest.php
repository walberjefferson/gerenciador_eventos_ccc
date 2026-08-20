<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Exceptions\Inscricoes\InscricaoDuplicadaException;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Inscricoes\Cenario;

/**
 * RN-11 — uma inscricao ativa por pessoa por evento.
 *
 * A regra e garantida por unicidade parcial no banco (vale so para
 * "aguardando pagamento" e "confirmada"). A aplicacao traduz a recusa do banco
 * em mensagem para o participante — nunca em erro tecnico.
 */
describe('mesmo e-mail', function () {
    it('bloqueia segunda inscricao enquanto a primeira aguarda pagamento', function () {
        $cenario = Cenario::montar();

        $cenario->inscrever();

        expect(fn () => $cenario->inscrever(['chave_idempotencia' => (string) Str::uuid(), 'documento' => Cenario::cpfValido(77)]))
            ->toThrow(InscricaoDuplicadaException::class, 'Já existe uma inscrição ativa com este e-mail neste evento.');

        expect(Inscricao::count())->toBe(1);
    });

    it('bloqueia segunda inscricao quando a primeira ja esta confirmada', function () {
        $cenario = Cenario::montar();

        $primeira = $cenario->inscrever();
        Inscricao::whereKey($primeira->id)->update([
            'situacao' => SituacaoInscricao::Confirmada->value,
            'confirmada_em' => Carbon::now(),
        ]);

        expect(fn () => $cenario->inscrever(['chave_idempotencia' => (string) Str::uuid(), 'documento' => Cenario::cpfValido(78)]))
            ->toThrow(InscricaoDuplicadaException::class, 'Já existe uma inscrição ativa com este e-mail neste evento.');
    });

    it('bloqueia mesmo com o e-mail escrito em outra caixa', function () {
        $cenario = Cenario::montar();

        $cenario->inscrever(['email' => 'Maria.Silva@Example.com']);

        expect(fn () => $cenario->inscrever([
            'email' => 'MARIA.SILVA@EXAMPLE.COM',
            'chave_idempotencia' => (string) Str::uuid(),
            'documento' => Cenario::cpfValido(79),
        ]))->toThrow(InscricaoDuplicadaException::class);
    });

    it('libera nova inscricao depois que a anterior expirou', function () {
        $cenario = Cenario::montar();

        $primeira = $cenario->inscrever();
        Inscricao::whereKey($primeira->id)->update([
            'situacao' => SituacaoInscricao::Expirada->value,
            'expirada_em' => Carbon::now(),
        ]);

        $segunda = $cenario->inscrever(['chave_idempotencia' => (string) Str::uuid()]);

        expect($segunda->id)->not->toBe($primeira->id)
            ->and($segunda->email)->toBe($primeira->email)
            ->and(Inscricao::count())->toBe(2)
            ->and($primeira->fresh()->situacao)->toBe(SituacaoInscricao::Expirada);
    });

    it('libera nova inscricao depois que a anterior foi cancelada', function () {
        $cenario = Cenario::montar();

        $primeira = $cenario->inscrever();
        Inscricao::whereKey($primeira->id)->update([
            'situacao' => SituacaoInscricao::Cancelada->value,
            'cancelada_em' => Carbon::now(),
            'motivo_cancelamento' => 'Desistência do participante.',
        ]);

        $segunda = $cenario->inscrever(['chave_idempotencia' => (string) Str::uuid()]);

        expect($segunda->exists)->toBeTrue()->and(Inscricao::count())->toBe(2);
    });

    it('permite o mesmo e-mail em eventos diferentes', function () {
        $cenario = Cenario::montar();
        $outro = Cenario::montar();

        $cenario->inscrever();
        $noOutro = $outro->inscrever();

        expect($noOutro->exists)->toBeTrue()->and(Inscricao::count())->toBe(2);
    });
});

describe('mesmo CPF', function () {
    it('bloqueia segunda inscricao com o mesmo CPF, mesmo escrito de outro jeito', function () {
        $cenario = Cenario::montar();

        $cenario->inscrever(['documento' => '52998224725']);

        expect(fn () => $cenario->inscrever([
            'documento' => '529.982.247-25',
            'email' => 'outro.email@example.com',
            'chave_idempotencia' => (string) Str::uuid(),
        ]))->toThrow(InscricaoDuplicadaException::class, 'Já existe uma inscrição ativa com este CPF neste evento.');

        expect(Inscricao::count())->toBe(1);
    });

    it('libera o mesmo CPF depois da expiracao', function () {
        $cenario = Cenario::montar();

        $primeira = $cenario->inscrever(['documento' => '52998224725']);
        Inscricao::whereKey($primeira->id)->update([
            'situacao' => SituacaoInscricao::Expirada->value,
            'expirada_em' => Carbon::now(),
        ]);

        $segunda = $cenario->inscrever([
            'documento' => '529.982.247-25',
            'email' => 'outro.email@example.com',
            'chave_idempotencia' => (string) Str::uuid(),
        ]);

        expect($segunda->exists)->toBeTrue();
    });
});

it('nao deixa vaga presa quando a inscricao e recusada por duplicidade', function () {
    $cenario = Cenario::montar();

    $cenario->inscrever();

    try {
        $cenario->inscrever(['chave_idempotencia' => (string) Str::uuid(), 'documento' => Cenario::cpfValido(80)]);
    } catch (InscricaoDuplicadaException) {
        // esperado
    }

    expect($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
        ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1);
});

it('devolve a duplicidade como erro do campo e-mail no formulario', function () {
    $cenario = Cenario::montar();

    $this->postJson('/inscricoes', $cenario->payload())->assertCreated();

    $this->postJson('/inscricoes', $cenario->payload([
        'chave_idempotencia' => (string) Str::uuid(),
        'documento' => Cenario::cpfValido(81),
    ]))->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Já existe uma inscrição ativa com este e-mail neste evento.');
});
