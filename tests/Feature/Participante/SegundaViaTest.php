<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\Inscricao;
use App\Models\Pagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Inscricoes\Cenario;

/**
 * A segunda via do Pix.
 *
 * Nao ha regra nova: o pedido apenas chama a Action idempotente que ja emite a
 * cobranca. Havendo cobranca pendente, e a mesma que volta. Fora do prazo, ou
 * com a inscricao ja resolvida, nada e criado e o participante recebe a
 * explicacao em linguagem simples.
 */
function pedidoDeSegundaVia(Inscricao $inscricao): string
{
    return URL::temporarySignedRoute(
        'inscricoes.segunda-via',
        Carbon::now()->addDay(),
        ['codigo_publico' => $inscricao->codigo_publico],
    );
}

it('recusa o pedido quando a URL nao esta assinada', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->post('/inscricoes/'.$inscricao->codigo_publico.'/segunda-via')->assertForbidden();

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(1);
});

it('devolve a mesma cobranca quando ja existe uma pendente', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $pendente = $inscricao->pagamentoPendente();

    $this->post(pedidoDeSegundaVia($inscricao))
        ->assertRedirect()
        ->assertRedirectContains('/pagamento');

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(1)
        ->and($inscricao->fresh()?->pagamentoPendente()?->id)->toBe($pendente?->id);
});

it('emite uma nova cobranca quando a inscricao ficou sem nenhuma aberta', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    // Como se a emissao da inscricao tivesse falhado (decisao D-27) ou a
    // cobranca anterior tivesse sido cancelada.
    $inscricao->pagamentos()->delete();

    $this->post(pedidoDeSegundaVia($inscricao))
        ->assertRedirect()
        ->assertRedirectContains('/pagamento');

    $nova = $inscricao->fresh()?->pagamentoPendente();

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(1)
        ->and($nova?->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($nova?->valor_centavos)->toBe($inscricao->valor_centavos)
        ->and($nova?->expira_em?->toIso8601String())->toBe($inscricao->prazo_pagamento?->toIso8601String())
        ->and($nova?->pix_copia_e_cola)->not->toBeEmpty();
});

it('recusa e explica quando o prazo ja venceu', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $inscricao->pagamentos()->delete();
    $inscricao->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

    $this->post(pedidoDeSegundaVia($inscricao->fresh()))
        ->assertRedirect()
        ->assertRedirectContains('/acompanhar')
        ->assertSessionHas('aviso', 'O prazo desta inscrição venceu e não é mais possível pagar.');

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(0);
});

it('recusa e explica quando a inscricao ja esta confirmada', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $inscricao->pagamentos()->delete();
    $inscricao->update([
        'situacao' => SituacaoInscricao::Confirmada,
        'confirmada_em' => Carbon::now(),
    ]);

    $this->post(pedidoDeSegundaVia($inscricao->fresh()))
        ->assertRedirect()
        ->assertRedirectContains('/acompanhar')
        ->assertSessionHas('aviso', 'Sua inscrição já está confirmada: não há nada a pagar.');

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(0);
});

it('recusa e explica quando a inscricao foi cancelada', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $inscricao->pagamentos()->delete();
    $inscricao->update([
        'situacao' => SituacaoInscricao::Cancelada,
        'cancelada_em' => Carbon::now(),
    ]);

    $this->post(pedidoDeSegundaVia($inscricao->fresh()))
        ->assertRedirect()
        ->assertSessionHas('aviso', 'Esta inscrição foi cancelada e não pode mais ser paga.');

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(0);
});

it('leva a explicacao da recusa para a pagina de acompanhamento', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $inscricao->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

    $destino = $this->post(pedidoDeSegundaVia($inscricao->fresh()))->headers->get('Location');

    $this->get((string) $destino)
        ->assertOk()
        ->assertSee('venceu', false);
});

it('para de aceitar pedidos depois do limite de tentativas', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $limite = (int) explode(',', (string) config('inscricoes.limites.segunda_via'))[0];

    for ($tentativa = 0; $tentativa < $limite; $tentativa++) {
        $this->post(pedidoDeSegundaVia($inscricao))->assertRedirect();
    }

    $this->post(pedidoDeSegundaVia($inscricao))->assertStatus(429);

    expect(Pagamento::query()->where('inscricao_id', $inscricao->id)->count())->toBe(1);
});
