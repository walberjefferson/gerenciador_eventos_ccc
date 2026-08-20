<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Inscricoes\Cenario;

/**
 * A tela da cobranca Pix.
 *
 * Duas coisas sao inegociaveis aqui: o codigo publico sozinho nao abre nada
 * (a assinatura da URL e obrigatoria) e a tela nunca declara pagamento — ela
 * so mostra o que o dominio ja gravou.
 */
function linkAssinado(Inscricao $inscricao, string $rota = 'inscricoes.pagamento'): string
{
    return URL::temporarySignedRoute(
        $rota,
        Carbon::now()->addDay(),
        ['codigo_publico' => $inscricao->codigo_publico],
    );
}

it('recusa a tela da cobranca quando a URL nao esta assinada', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->get('/inscricoes/'.$inscricao->codigo_publico.'/pagamento')->assertForbidden();
});

it('recusa a consulta de situacao quando a URL nao esta assinada', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->getJson('/inscricoes/'.$inscricao->codigo_publico.'/situacao')->assertForbidden();
});

it('mostra valor, QR Code, copia e cola e prazo enquanto ha o que pagar', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->get(linkAssinado($inscricao))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inscricoes/Pagamento')
            ->where('estado', 'aguardando')
            ->where('codigo_publico', $inscricao->codigo_publico)
            ->where('valor_centavos', $inscricao->valor_centavos)
            ->where('situacao_rotulo', SituacaoInscricao::AguardandoPagamento->rotulo())
            ->whereNot('prazo_pagamento', null)
            ->where('pagamento.pix_copia_e_cola', fn (?string $codigo): bool => is_string($codigo) && $codigo !== '')
            ->where('pagamento.qr_code_svg', fn (?string $svg): bool => is_string($svg) && str_starts_with($svg, '<svg'))
            ->where('url_situacao', fn (string $url): bool => str_contains($url, '/situacao') && str_contains($url, 'signature='))
        );
});

it('nao vaza documento nem hash do documento nos props', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $conteudo = $this->get(linkAssinado($inscricao))->assertOk()->getContent();

    expect($conteudo)
        ->not->toContain('documento')
        ->not->toContain('52998224725');
});

it('mostra a tela de inscricao confirmada quando o dominio ja reconheceu o pagamento', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->pagamentoPendente()?->update([
        'situacao' => SituacaoPagamento::Pago,
        'pago_em' => Carbon::now(),
    ]);

    $inscricao->update([
        'situacao' => SituacaoInscricao::Confirmada,
        'confirmada_em' => Carbon::now(),
    ]);

    $this->get(linkAssinado($inscricao->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inscricoes/Pagamento')
            ->where('estado', 'confirmada')
            ->where('situacao_rotulo', SituacaoInscricao::Confirmada->rotulo())
            // Cobranca paga nao mostra mais como pagar.
            ->where('pagamento.pix_copia_e_cola', null)
            ->where('pagamento.qr_code_svg', null)
            ->etc()
        );
});

it('mostra a tela de prazo vencido quando a cobranca expirou', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->pagamentoPendente()?->update(['situacao' => SituacaoPagamento::Expirado]);
    $inscricao->update([
        'situacao' => SituacaoInscricao::Expirada,
        'expirada_em' => Carbon::now(),
    ]);

    $this->get(linkAssinado($inscricao->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('estado', 'expirada')
            ->where('pagamento.pix_copia_e_cola', null)
            ->etc()
        );
});

it('trata prazo vencido como expirado mesmo antes de a rotina de expiracao passar', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $vencido = Carbon::now()->subMinute();
    $inscricao->pagamentoPendente()?->update(['expira_em' => $vencido]);
    $inscricao->update(['prazo_pagamento' => $vencido]);

    $this->get(linkAssinado($inscricao->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->where('estado', 'expirada')->etc());
});

it('responde a situacao atual em JSON enxuto pela URL assinada', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->getJson(linkAssinado($inscricao, 'inscricoes.situacao'))
        ->assertOk()
        ->assertExactJson([
            'situacao' => SituacaoInscricao::AguardandoPagamento->value,
            'situacao_rotulo' => SituacaoInscricao::AguardandoPagamento->rotulo(),
            'estado' => 'aguardando',
            'pago_em' => null,
        ]);
});

it('a consulta de situacao acompanha a confirmacao vinda do dominio', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $pago = Carbon::now();
    $inscricao->pagamentoPendente()?->update(['situacao' => SituacaoPagamento::Pago, 'pago_em' => $pago]);
    $inscricao->update(['situacao' => SituacaoInscricao::Confirmada, 'confirmada_em' => $pago]);

    $this->getJson(linkAssinado($inscricao->fresh(), 'inscricoes.situacao'))
        ->assertOk()
        ->assertJsonPath('estado', 'confirmada')
        ->assertJsonPath('situacao', SituacaoInscricao::Confirmada->value);
});
