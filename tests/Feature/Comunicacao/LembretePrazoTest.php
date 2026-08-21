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
| aproximacao do prazo e uma rotina agendada. Ela precisa acertar tres coisas:
| avisar quem esta dentro da janela, nao incomodar quem nao deve receber, e
| nunca mandar o mesmo lembrete duas vezes.
|
*/

beforeEach(function (): void {
    Mail::fake();
    $this->evento = Evento::factory()->create(['nome' => 'Retiro de Carnaval']);
});

/** Inscricao aguardando pagamento com o prazo vencendo daqui a X horas. */
function aguardando(Evento $evento, float $horas, array $atributos = []): Inscricao
{
    return Inscricao::factory()->for($evento)->create(array_merge([
        'prazo_pagamento' => Carbon::now()->addMinutes((int) round($horas * 60)),
    ], $atributos));
}

it('avisa quem tem prazo dentro da janela', function (): void {
    $inscricao = aguardando($this->evento, 6, ['nome_completo' => 'Maria da Silva']);

    $this->artisan('inscricoes:lembrar-prazo')
        ->expectsOutputToContain('Lembretes enviados nesta execucao: 1.')
        ->assertSuccessful();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(LembretePrazoMail::class, function (LembretePrazoMail $email) use ($inscricao): bool {
        expect($email->hasTo($inscricao->email))->toBeTrue()
            ->and($email->nome)->toBe('Maria')
            ->and($email->tempoRestante)->toBe('Faltam cerca de 6 horas')
            ->and($email->link)->toContain('signature=')
            ->and($email->render())->toContain('Retiro de Carnaval');

        return true;
    });
});

it('nao avisa quem ainda esta longe do prazo', function (): void {
    aguardando($this->evento, 72);

    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('respeita a janela informada na linha de comando', function (): void {
    aguardando($this->evento, 40);

    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();
    Mail::assertNothingQueued();

    $this->artisan('inscricoes:lembrar-prazo', ['--janela' => 48])->assertSuccessful();
    Mail::assertQueuedCount(1);
});

it('recusa uma janela sem sentido', function (): void {
    $this->artisan('inscricoes:lembrar-prazo', ['--janela' => 0])->assertFailed();
    $this->artisan('inscricoes:lembrar-prazo', ['--janela' => 'amanha'])->assertFailed();

    Mail::assertNothingQueued();
});

it('nao avisa quem ja confirmou, expirou ou foi cancelado', function (): void {
    aguardando($this->evento, 3, ['situacao' => 'confirmada', 'confirmada_em' => Carbon::now()]);
    aguardando($this->evento, 3, ['situacao' => 'expirada', 'expirada_em' => Carbon::now()]);
    aguardando($this->evento, 3, ['situacao' => 'cancelada', 'cancelada_em' => Carbon::now()]);

    $this->artisan('inscricoes:lembrar-prazo')
        ->expectsOutputToContain('Nenhum lembrete a enviar')
        ->assertSuccessful();

    Mail::assertNothingQueued();
});

it('nao avisa quem ja perdeu o prazo: para esse, o aviso e outro', function (): void {
    aguardando($this->evento, -2);

    $this->artisan('inscricoes:lembrar-prazo')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('rodado duas vezes seguidas nao manda nada na segunda', function (): void {
    aguardando($this->evento, 5);

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
    Inscricao::factory()->count(7)->for($this->evento)->create([
        'prazo_pagamento' => Carbon::now()->addHours(4),
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
