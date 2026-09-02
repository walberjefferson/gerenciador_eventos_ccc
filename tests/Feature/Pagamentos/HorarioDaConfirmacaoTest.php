<?php

declare(strict_types=1);

use App\Actions\Pagamentos\ConfirmarPagamento;
use App\Services\Payments\MomentoDoProvedor;
use Illuminate\Support\Carbon;
use Tests\Feature\Inscricoes\Cenario;

/**
 * O horario em que o dinheiro entrou.
 *
 * "pago_em" e "confirmada_em" sao a prova de quando a inscricao foi garantida:
 * e o que a organizacao mostra a quem reclama, e o que o e-mail de confirmacao
 * repete para a pessoa. Errar nele por tres horas transforma um pagamento
 * feito dentro do prazo em um pagamento aparentemente feito depois.
 *
 * O caminho que errava: o Laravel grava data e hora SEM o fuso escrito
 * ("Y-m-d H:i:s"), e quem interpreta o que esta escrito e o PostgreSQL, pelo
 * fuso da sessao. Um horario que chega marcado em UTC — como a Efi devolve,
 * terminado em "Z" — ia para o banco com os numeros de Londres e voltava lido
 * como se fossem numeros de Maceio.
 */
it('grava o instante certo quando o provedor informa o horario em UTC', function (): void {
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => 60]);
    $inscricao = $cenario->inscrever();
    $pagamento = $inscricao->pagamentoPendente();

    // Meio-dia em Maceio e 15h em UTC. O provedor manda a versao UTC.
    $emMaceio = Carbon::parse('2026-09-02 12:00:00', 'America/Maceio');
    $comoOProvedorManda = '2026-09-02T15:00:00.000Z';

    app(ConfirmarPagamento::class)($pagamento, MomentoDoProvedor::deTexto($comoOProvedorManda));

    $pagamento->refresh();
    $inscricao->refresh();

    expect($pagamento->pago_em->equalTo($emMaceio))->toBeTrue()
        ->and($pagamento->pago_em->format('H:i'))->toBe('12:00')
        ->and($inscricao->confirmada_em->equalTo($emMaceio))->toBeTrue();
});

it('nao desloca o horario quando o provedor ja informa o fuso local', function (): void {
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => 60]);
    $inscricao = $cenario->inscrever();
    $pagamento = $inscricao->pagamentoPendente();

    app(ConfirmarPagamento::class)($pagamento, MomentoDoProvedor::deTexto('2026-09-02T12:00:00-03:00'));

    expect($pagamento->refresh()->pago_em->format('H:i'))->toBe('12:00');
});

it('trata como ausente o horario que nao da para entender', function (): void {
    expect(MomentoDoProvedor::deTexto(null))->toBeNull()
        ->and(MomentoDoProvedor::deTexto(''))->toBeNull()
        ->and(MomentoDoProvedor::deTexto('ontem de tarde'))->toBeNull();
});

it('a aplicacao roda no fuso de Maceio mesmo sem variavel de ambiente', function (): void {
    expect(config('app.timezone'))->toBe('America/Maceio')
        ->and(Carbon::now()->getTimezone()->getName())->toBe('America/Maceio');
});
