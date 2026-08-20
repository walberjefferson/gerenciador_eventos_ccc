<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Events\InscricaoExpirada;
use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Inscricoes\Cenario;

/**
 * A vaga volta para a fila quando o prazo vence.
 *
 * O que se prova aqui: a devolucao alcanca o evento E cada atividade escolhida;
 * a cobranca e fechada junto, para que ninguem pague um Pix de uma vaga que ja
 * voltou; nada e apagado do banco; e rodar o comando duas vezes seguidas nao
 * muda absolutamente nada na segunda.
 */

/**
 * @return array{0: Cenario, 1: Inscricao}
 */
function inscricaoComPrazoVencido(int $minutos = 30): array
{
    $cenario = Cenario::montar(['prazo_pagamento_minutos' => $minutos]);

    $inscricao = $cenario->inscrever([
        'atividades' => [$cenario->futebol->id, $cenario->trilha->id],
    ]);

    test()->travel($minutos + 1)->minutes();

    return [$cenario, $inscricao];
}

it('devolve a vaga do evento e a de cada atividade escolhida', function () {
    [$cenario, $inscricao] = inscricaoComPrazoVencido();

    expect($cenario->evento->fresh()->vagas_reservadas)->toBe(1)
        ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(1)
        ->and($cenario->trilha->fresh()->vagas_reservadas)->toBe(1);

    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();

    expect($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->evento->fresh()->vagas_confirmadas)->toBe(0)
        ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->trilha->fresh()->vagas_reservadas)->toBe(0)
        ->and($cenario->natacao->fresh()->vagas_reservadas)->toBe(0);

    $inscricao->refresh();

    expect($inscricao->situacao)->toBe(SituacaoInscricao::Expirada)
        ->and($inscricao->expirada_em)->not->toBeNull();
});

it('fecha a cobranca junto com a inscricao expirada', function () {
    [, $inscricao] = inscricaoComPrazoVencido();

    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();

    $pagamento = Pagamento::query()->where('inscricao_id', $inscricao->getKey())->sole();

    expect($pagamento->situacao)->toBe(SituacaoPagamento::Expirado)
        ->and($pagamento->estaAberto())->toBeFalse()
        ->and($inscricao->fresh()->pagamentoPendente())->toBeNull();
});

it('nao apaga nenhum registro ao expirar', function () {
    [, $inscricao] = inscricaoComPrazoVencido();

    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();

    expect(Inscricao::query()->count())->toBe(1)
        ->and(Pagamento::query()->count())->toBe(1)
        ->and(DB::table('inscricoes_atividades')->where('inscricao_id', $inscricao->getKey())->count())->toBe(2);
});

it('anuncia a expiracao uma unica vez, mesmo rodando o comando duas vezes', function () {
    [, $inscricao] = inscricaoComPrazoVencido();

    Event::fake([InscricaoExpirada::class]);

    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();
    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();

    Event::assertDispatchedTimes(InscricaoExpirada::class, 1);
    Event::assertDispatched(
        InscricaoExpirada::class,
        fn (InscricaoExpirada $anuncio) => $anuncio->inscricao->is($inscricao)
            && $anuncio->inscricao->situacao === SituacaoInscricao::Expirada
    );
});

it('rodar duas vezes seguidas nao muda nada na segunda', function () {
    [$cenario, $inscricao] = inscricaoComPrazoVencido();

    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();

    $depoisDaPrimeira = [
        'inscricao' => Inscricao::query()->whereKey($inscricao->getKey())->sole()->only([
            'situacao', 'expirada_em', 'updated_at',
        ]),
        'pagamento' => Pagamento::query()->where('inscricao_id', $inscricao->getKey())->sole()->only([
            'situacao', 'updated_at',
        ]),
        'evento' => $cenario->evento->fresh()->only(['vagas_reservadas', 'vagas_confirmadas']),
        'futebol' => $cenario->futebol->fresh()->only(['vagas_reservadas', 'vagas_confirmadas']),
        'trilha' => $cenario->trilha->fresh()->only(['vagas_reservadas', 'vagas_confirmadas']),
    ];

    $this->travel(5)->minutes();

    $this->artisan('inscricoes:expirar-vencidas')
        ->expectsOutputToContain('Nenhuma inscricao vencida')
        ->assertSuccessful();

    expect(Inscricao::query()->whereKey($inscricao->getKey())->sole()->only([
        'situacao', 'expirada_em', 'updated_at',
    ]))->toEqual($depoisDaPrimeira['inscricao'])
        ->and(Pagamento::query()->where('inscricao_id', $inscricao->getKey())->sole()->only([
            'situacao', 'updated_at',
        ]))->toEqual($depoisDaPrimeira['pagamento'])
        ->and($cenario->evento->fresh()->only(['vagas_reservadas', 'vagas_confirmadas']))->toEqual($depoisDaPrimeira['evento'])
        ->and($cenario->futebol->fresh()->only(['vagas_reservadas', 'vagas_confirmadas']))->toEqual($depoisDaPrimeira['futebol'])
        ->and($cenario->trilha->fresh()->only(['vagas_reservadas', 'vagas_confirmadas']))->toEqual($depoisDaPrimeira['trilha']);
});

it('nao toca em inscricao que ainda esta dentro do prazo, nem em outro evento', function () {
    $vencido = Cenario::montar(['prazo_pagamento_minutos' => 10]);
    $emDia = Cenario::montar(['prazo_pagamento_minutos' => 600]);

    $expirada = $vencido->inscrever();
    $intacta = $emDia->inscrever();

    $this->travel(11)->minutes();

    $this->artisan('inscricoes:expirar-vencidas')->assertSuccessful();

    expect($expirada->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
        ->and($intacta->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($emDia->evento->fresh()->vagas_reservadas)->toBe(1);
});

it('limita a varredura ao evento informado', function () {
    $primeiro = Cenario::montar(['prazo_pagamento_minutos' => 10]);
    $segundo = Cenario::montar(['prazo_pagamento_minutos' => 10]);

    $desteEvento = $primeiro->inscrever();
    $doOutroEvento = $segundo->inscrever();

    $this->travel(11)->minutes();

    $this->artisan('inscricoes:expirar-vencidas', ['--evento' => $primeiro->evento->codigo_publico])
        ->assertSuccessful();

    expect($desteEvento->fresh()->situacao)->toBe(SituacaoInscricao::Expirada)
        ->and($doOutroEvento->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($primeiro->evento->fresh()->vagas_reservadas)->toBe(0)
        ->and($segundo->evento->fresh()->vagas_reservadas)->toBe(1);
});
