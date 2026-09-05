<?php

declare(strict_types=1);

use App\Console\Commands\LembrarPrazoPagamento;
use App\Enums\TipoComunicacao;
use App\Mail\LembretePrazoMail;
use App\Models\ComunicacaoEnviada;
use App\Models\Evento;
use App\Models\Inscricao;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| O lembrete de prazo
|--------------------------------------------------------------------------
|
| Nada acontece no dominio quando o tempo passa, entao quem repara na
| aproximacao do prazo e uma rotina agendada. O momento do aviso e uma FRACAO
| do prazo que aquela inscricao recebeu, e nao um numero fixo de horas: quem
| teve 24 horas e avisado faltando 12, quem teve uma hora e avisado faltando
| 30 minutos.
|
| A rotina precisa acertar quatro coisas: avisar quem ja gastou metade do
| proprio prazo, calar-se para quem ainda tem folga, nao incomodar quem nao
| deve receber, e nunca mandar o mesmo lembrete duas vezes.
|
*/

beforeEach(function (): void {
    Mail::fake();
    $this->evento = Evento::factory()->create(['nome' => 'Retiro de Carnaval']);
});

/**
 * Inscricao aguardando pagamento que recebeu $prazo horas de prazo e das quais
 * ja gastou $gastas.
 *
 * As duas pontas sao explicitas de proposito: o que decide o lembrete nao e o
 * quanto falta, e sim o quanto falta EM RELACAO ao que foi dado.
 */
function aguardando(Evento $evento, float $prazo, float $gastas, array $atributos = []): Inscricao
{
    $criada = Carbon::now()->subMinutes((int) round($gastas * 60));

    return Inscricao::factory()->for($evento)->create(array_merge([
        'created_at' => $criada,
        'updated_at' => $criada,
        'prazo_pagamento' => $criada->copy()->addMinutes((int) round($prazo * 60)),
    ], $atributos));
}

it('avisa quem ja gastou metade do prazo', function (): void {
    // 24 horas de prazo, 13 ja gastas: restam 11, menos que a metade.
    $inscricao = aguardando($this->evento, 24, 13, ['nome_completo' => 'Maria da Silva']);

    $this->artisan('inscricoes:lembrar-prazo')
        ->expectsOutputToContain('Lembretes enviados nesta execucao: 1.')
        ->assertSuccessful();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(LembretePrazoMail::class, function (LembretePrazoMail $email) use ($inscricao): bool {
        expect($email->hasTo($inscricao->email))->toBeTrue()
            ->and($email->nome)->toBe('Maria')
            ->and($email->tempoRestante)->toBe('Faltam cerca de 11 horas')
            ->and($email->link)->toContain('signature=')
            ->and($email->render())->toContain('Retiro de Carnaval');

        return true;
    });
});

it('nao avisa quem ainda nao gastou metade do prazo', function (): void {
    // 24 horas de prazo, 11 gastas: restam 13, mais que a metade.
    aguardando($this->evento, 24, 11);

    $this->artisan('inscricoes:lembrar-prazo')
        ->expectsOutputToContain('Nenhum lembrete a enviar')
        ->assertSuccessful();

    Mail::assertNothingQueued();
});

it('mede a metade pelo prazo de cada inscricao, e nao por um numero fixo de horas', function (): void {
    // Prazo curto ja na segunda metade: 1 hora de prazo, 40 minutos gastos.
    $curto = aguardando($this->evento, 1, 40 / 60);

    // Prazo longo ainda na primeira metade, embora falte MENOS tempo em horas
    // do que a janela fixa de 24 horas que existia antes: 30 dias de prazo,
    // 10 gastos.
    $longo = aguardando($this->evento, 30 * 24, 10 * 24);

    $this->artisan('inscricoes:lembrar-prazo')
        ->expectsOutputToContain('Lembretes enviados nesta execucao: 1.')
        ->assertSuccessful();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(LembretePrazoMail::class, fn (LembretePrazoMail $email): bool => $email->hasTo($curto->email));
    Mail::assertNotQueued(LembretePrazoMail::class, fn (LembretePrazoMail $email): bool => $email->hasTo($longo->email));
});

it('nao avisa quem acabou de se inscrever com prazo curto', function (): void {
    // O defeito que a regra proporcional conserta: com janela fixa de 24 horas,
    // esta inscricao receberia o "o prazo esta acabando" na primeira execucao
    // do agendador, junto com o e-mail de inscricao recebida.
    aguardando($this->evento, 24, 0.1);

    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('respeita a fracao informada na linha de comando', function (): void {
    // 24 horas de prazo, 8 gastas: restam 16 (dois tercos).
    aguardando($this->evento, 24, 8);

    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();
    Mail::assertNothingQueued();

    $this->artisan('inscricoes:lembrar-prazo', ['--fracao' => 0.75])->assertSuccessful();
    Mail::assertQueuedCount(1);
});

it('recusa uma fracao sem sentido', function (): void {
    $this->artisan('inscricoes:lembrar-prazo', ['--fracao' => 0])->assertFailed();
    $this->artisan('inscricoes:lembrar-prazo', ['--fracao' => 1.5])->assertFailed();
    $this->artisan('inscricoes:lembrar-prazo', ['--fracao' => 'metade'])->assertFailed();

    Mail::assertNothingQueued();
});

it('nao avisa quem ja confirmou, expirou ou foi cancelado', function (): void {
    aguardando($this->evento, 24, 20, ['situacao' => 'confirmada', 'confirmada_em' => Carbon::now()]);
    aguardando($this->evento, 24, 20, ['situacao' => 'expirada', 'expirada_em' => Carbon::now()]);
    aguardando($this->evento, 24, 20, ['situacao' => 'cancelada', 'cancelada_em' => Carbon::now()]);

    $this->artisan('inscricoes:lembrar-prazo')
        ->expectsOutputToContain('Nenhum lembrete a enviar')
        ->assertSuccessful();

    Mail::assertNothingQueued();
});

it('nao avisa quem ja perdeu o prazo: para esse, o aviso e outro', function (): void {
    aguardando($this->evento, 24, 26);

    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('rodado duas vezes seguidas nao manda nada na segunda', function (): void {
    aguardando($this->evento, 24, 19);

    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();
    $this->artisan('inscricoes:lembrar-prazo')
        ->expectsOutputToContain('Nenhum lembrete a enviar')
        ->assertSuccessful();

    Mail::assertQueuedCount(1);
    expect(ComunicacaoEnviada::query()
        ->where('tipo', TipoComunicacao::LembretePrazo->value)
        ->count())->toBe(1);
});

it('percorre em lotes e avisa todo mundo uma vez so', function (): void {
    $criada = Carbon::now()->subHours(20);

    Inscricao::factory()->count(7)->for($this->evento)->create([
        'created_at' => $criada,
        'updated_at' => $criada,
        'prazo_pagamento' => $criada->copy()->addHours(24),
    ]);

    $this->artisan('inscricoes:lembrar-prazo', ['--lote' => 2])
        ->expectsOutputToContain('Lembretes enviados nesta execucao: 7.')
        ->assertSuccessful();

    Mail::assertQueuedCount(7);
    expect(ComunicacaoEnviada::query()->count())->toBe(7);
});

it('esta agendado a cada quinze minutos', function (): void {
    $agendamentos = collect(app(Schedule::class)->events())
        ->filter(fn ($evento): bool => str_contains((string) $evento->command, LembrarPrazoPagamento::class)
            || str_contains((string) $evento->command, 'inscricoes:lembrar-prazo'));

    expect($agendamentos)->toHaveCount(1)
        ->and($agendamentos->first()->expression)->toBe('*/15 * * * *');
});
