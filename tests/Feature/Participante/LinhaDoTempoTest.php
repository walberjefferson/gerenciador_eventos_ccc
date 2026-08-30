<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\Inscricao;
use App\Services\Inscricoes\LinhaDoTempoDaInscricao;
use Illuminate\Support\Carbon;
use Tests\Feature\Inscricoes\Cenario;

/**
 * A linha do tempo da inscricao.
 *
 * Todos os marcos vem de carimbo de tempo que o dominio ja grava. Nenhum e
 * inventado, nenhum e gravado: se o campo esta vazio, o marco nao aparece.
 */

/**
 * @return list<array{chave: string, titulo: string, descricao: string, momento: string|null, estado: string}>
 */
function marcosDa(Inscricao $inscricao): array
{
    return app(LinhaDoTempoDaInscricao::class)($inscricao->fresh(['evento', 'pagamentos']));
}

/**
 * @param  list<array{chave: string, titulo: string, descricao: string, momento: string|null, estado: string}>  $marcos
 * @return list<string>
 */
function chavesDos(array $marcos): array
{
    return array_column($marcos, 'chave');
}

/**
 * @param  list<array{chave: string, titulo: string, descricao: string, momento: string|null, estado: string}>  $marcos
 * @return list<string>
 */
function marcosAtuais(array $marcos): array
{
    return array_values(array_map(
        fn (array $marco): string => $marco['chave'],
        array_filter($marcos, fn (array $marco): bool => $marco['estado'] === LinhaDoTempoDaInscricao::ATUAL),
    ));
}

it('conta a historia de uma inscricao aguardando pagamento', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $marcos = marcosDa($inscricao);

    expect(chavesDos($marcos))->toBe(['inscricao_feita', 'cobranca_emitida', 'prazo_pagamento'])
        ->and(marcosAtuais($marcos))->toBe(['prazo_pagamento'])
        ->and($marcos[0]['estado'])->toBe(LinhaDoTempoDaInscricao::CONCLUIDO)
        ->and($marcos[1]['estado'])->toBe(LinhaDoTempoDaInscricao::CONCLUIDO);
});

it('ordena os marcos do mais antigo para o mais recente', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $momentos = array_values(array_filter(
        array_column(marcosDa($inscricao), 'momento'),
        fn (?string $momento): bool => $momento !== null,
    ));

    $ordenados = $momentos;
    sort($ordenados);

    expect($momentos)->toBe($ordenados);
});

it('leva para o fim o marco que ainda nao tem data', function (): void {
    $inscricao = Cenario::montar()->inscrever();
    $inscricao->update(['prazo_pagamento' => null]);

    $marcos = marcosDa($inscricao);

    expect(end($marcos)['chave'])->toBe('prazo_pagamento')
        ->and(end($marcos)['momento'])->toBeNull()
        ->and(marcosAtuais($marcos))->toBe(['prazo_pagamento']);
});

it('marca pagamento recebido e inscricao confirmada, sem nenhum passo atual', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $pago = Carbon::now();
    $inscricao->pagamentoPendente()?->update(['situacao' => SituacaoPagamento::Pago, 'pago_em' => $pago]);
    $inscricao->update(['situacao' => SituacaoInscricao::Confirmada, 'confirmada_em' => $pago]);

    $marcos = marcosDa($inscricao);

    expect(chavesDos($marcos))->toBe([
        'inscricao_feita',
        'cobranca_emitida',
        'pagamento_recebido',
        'inscricao_confirmada',
    ])->and(marcosAtuais($marcos))->toBe([]);
});

it('encerra a linha do tempo quando o prazo vence, sem nenhum passo atual', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->pagamentoPendente()?->update(['situacao' => SituacaoPagamento::Expirado]);
    $inscricao->update([
        'situacao' => SituacaoInscricao::Expirada,
        'expirada_em' => Carbon::now(),
    ]);

    $marcos = marcosDa($inscricao);

    expect(chavesDos($marcos))->toContain('prazo_vencido')
        ->and(marcosAtuais($marcos))->toBe([])
        ->and(end($marcos)['estado'])->toBe(LinhaDoTempoDaInscricao::ENCERRADO);
});

it('encerra o prazo, sem passo atual, mesmo antes de a rotina de expiracao passar', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->update(['prazo_pagamento' => Carbon::now()->subMinute()]);

    $marcos = marcosDa($inscricao);
    $prazo = collect($marcos)->firstWhere('chave', 'prazo_pagamento');

    expect($prazo['estado'])->toBe(LinhaDoTempoDaInscricao::ENCERRADO)
        ->and(marcosAtuais($marcos))->toBe([]);
});

it('conta o cancelamento com o motivo gravado', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $inscricao->update([
        'situacao' => SituacaoInscricao::Cancelada,
        'cancelada_em' => Carbon::now(),
        'motivo_cancelamento' => 'Pedido da organização',
    ]);

    $marcos = marcosDa($inscricao);
    $cancelamento = collect($marcos)->firstWhere('chave', 'inscricao_cancelada');

    expect($cancelamento['descricao'])->toContain('Pedido da organização')
        ->and($cancelamento['estado'])->toBe(LinhaDoTempoDaInscricao::ENCERRADO)
        ->and(marcosAtuais($marcos))->toBe([]);
});

it('conta o estorno com o valor devolvido', function (): void {
    $inscricao = Cenario::montar()->inscrever();

    $agora = Carbon::now();
    $inscricao->pagamentoPendente()?->update([
        'situacao' => SituacaoPagamento::Estornado,
        'pago_em' => $agora->copy()->subHour(),
        'estornado_em' => $agora,
        'valor_estornado_centavos' => $inscricao->valor_centavos,
    ]);
    $inscricao->update([
        'situacao' => SituacaoInscricao::Cancelada,
        'cancelada_em' => $agora,
    ]);

    $marcos = marcosDa($inscricao);
    $estorno = collect($marcos)->firstWhere('chave', 'valor_estornado');

    expect($estorno)->not->toBeNull()
        ->and($estorno['descricao'])->toContain('R$ ')
        ->and(chavesDos($marcos))->toContain('pagamento_recebido')
        ->and(marcosAtuais($marcos))->toBe([]);
});

it('mostra os oito marcos possiveis ao longo dos quatro finais', function (): void {
    $possiveis = [
        'inscricao_feita',
        'cobranca_emitida',
        'prazo_pagamento',
        'pagamento_recebido',
        'inscricao_confirmada',
        'prazo_vencido',
        'inscricao_cancelada',
        'valor_estornado',
    ];

    $cenario = Cenario::montar();
    $vistos = [];

    // Aguardando: inscricao feita, cobranca emitida e prazo para pagar.
    $pendente = $cenario->inscrever();
    $vistos = array_merge($vistos, chavesDos(marcosDa($pendente)));

    // Confirmada: pagamento recebido e inscricao confirmada.
    $agora = Carbon::now();
    $pendente->pagamentoPendente()?->update(['situacao' => SituacaoPagamento::Pago, 'pago_em' => $agora]);
    $pendente->update(['situacao' => SituacaoInscricao::Confirmada, 'confirmada_em' => $agora]);
    $vistos = array_merge($vistos, chavesDos(marcosDa($pendente)));

    // Expirada e cancelada, com estorno.
    $outra = $cenario->inscrever($cenario->outraPessoa(7));
    $outra->pagamentoPendente()?->update([
        'situacao' => SituacaoPagamento::Estornado,
        'estornado_em' => $agora,
        'valor_estornado_centavos' => $outra->valor_centavos,
    ]);
    $outra->update([
        'situacao' => SituacaoInscricao::Expirada,
        'expirada_em' => $agora,
        'cancelada_em' => $agora,
    ]);
    $vistos = array_merge($vistos, chavesDos(marcosDa($outra)));

    expect(array_values(array_unique($vistos)))->toEqualCanonicalizing($possiveis);
});
