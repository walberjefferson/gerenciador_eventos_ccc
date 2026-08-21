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
 * A pagina de acompanhamento da inscricao.
 *
 * Duas coisas mandam aqui: sem assinatura na URL nao se ve nada, e nenhum
 * dado da conversa com a instituicao financeira — nem o documento do
 * participante — chega ao navegador.
 */
function linkDoParticipante(Inscricao $inscricao, string $rota = 'inscricoes.acompanhar'): string
{
    return URL::temporarySignedRoute(
        $rota,
        Carbon::now()->addDay(),
        ['codigo_publico' => $inscricao->codigo_publico],
    );
}

it('recusa a pagina de acompanhamento quando a URL nao esta assinada', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $this->get('/inscricoes/'.$inscricao->codigo_publico.'/acompanhar')->assertForbidden();
});

it('recusa a pagina quando a assinatura ja venceu', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $url = URL::temporarySignedRoute(
        'inscricoes.acompanhar',
        Carbon::now()->subMinute(),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    $this->get($url)->assertForbidden();
});

it('mostra evento, situacao, valor, atividades, linha do tempo e historico da cobranca', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever(['atividades' => [$cenario->futebol->id, $cenario->trilha->id]]);

    $this->get(linkDoParticipante($inscricao))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inscricoes/Acompanhar')
            ->where('inscricao.codigo_publico', $inscricao->codigo_publico)
            ->where('inscricao.nome_completo', $inscricao->nome_completo)
            ->where('inscricao.situacao', SituacaoInscricao::AguardandoPagamento->value)
            ->where('inscricao.situacao_rotulo', SituacaoInscricao::AguardandoPagamento->rotulo())
            ->where('inscricao.valor_centavos', $inscricao->valor_centavos)
            ->where('inscricao.evento.nome', $cenario->evento->nome)
            ->where('inscricao.grupo_participante.nome', $cenario->grupoParticipante->nome)
            ->count('inscricao.atividades', 2)
            ->where('inscricao.atividades.0.nome', $cenario->futebol->nome)
            ->count('pagamentos', 1)
            ->where('pagamentos.0.situacao', SituacaoPagamento::Pendente->value)
            ->where('pagamentos.0.metodo_rotulo', 'Pix')
            ->count('linha_do_tempo', 3)
            ->where('linha_do_tempo.2.chave', 'prazo_pagamento')
            ->where('linha_do_tempo.2.estado', 'atual')
            ->where('pode_pagar', true)
            ->where('url_pagamento', fn (?string $url): bool => is_string($url)
                && str_contains($url, '/pagamento')
                && str_contains($url, 'signature='))
        );
});

it('nao vaza documento, hash, identificador externo, gateway nem metadados', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $pagamento = $inscricao->pagamentoPendente();

    $conteudo = $this->get(linkDoParticipante($inscricao))->assertOk()->getContent();

    expect($conteudo)
        ->not->toContain('documento')
        ->not->toContain('52998224725')
        ->not->toContain('id_externo')
        ->not->toContain('metadados')
        ->not->toContain('pix_copia_e_cola')
        ->not->toContain((string) $pagamento?->id_externo)
        ->not->toContain((string) $pagamento?->gateway);
});

it('nao oferece o caminho de volta ao Pix depois de a inscricao ser confirmada', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->pagamentoPendente()?->update([
        'situacao' => SituacaoPagamento::Pago,
        'pago_em' => Carbon::now(),
    ]);
    $inscricao->update([
        'situacao' => SituacaoInscricao::Confirmada,
        'confirmada_em' => Carbon::now(),
    ]);

    $this->get(linkDoParticipante($inscricao->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('pode_pagar', false)
            ->where('url_pagamento', null)
            ->where('inscricao.situacao_rotulo', SituacaoInscricao::Confirmada->rotulo())
            ->etc()
        );
});

it('nao oferece o caminho de volta ao Pix quando o prazo ja venceu', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

    $this->get(linkDoParticipante($inscricao->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('pode_pagar', false)
            ->where('url_pagamento', null)
            ->etc()
        );
});

it('mostra o motivo do cancelamento e nenhuma cobranca a pagar', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->update([
        'situacao' => SituacaoInscricao::Cancelada,
        'cancelada_em' => Carbon::now(),
        'motivo_cancelamento' => 'Pedido da organização',
    ]);

    $this->get(linkDoParticipante($inscricao->fresh()))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('pode_pagar', false)
            ->where('inscricao.motivo_cancelamento', 'Pedido da organização')
            ->etc()
        );
});
