<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Pagamentos\CriarPagamentoDaInscricao;
use App\Enums\SituacaoInscricao;
use App\Models\Inscricao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Segunda via do Pix, a pedido do participante.
 *
 * Nao existe regra nova aqui. A emissao continua sendo da Action
 * CriarPagamentoDaInscricao, que e idempotente: havendo cobranca pendente,
 * devolve a mesma; nao havendo, emite outra com o mesmo prazo da inscricao.
 *
 * Isso fecha o buraco da decisao D-27 — a cobranca e emitida fora da
 * transacao que cria a inscricao e, se aquela chamada falhar, a inscricao fica
 * sem Pix. Este pedido resolve sem mexer em vaga, prazo ou situacao.
 *
 * A rota e assinada e tem limite de tentativas: o codigo publico sozinho nunca
 * serve de senha, e ninguem pede cobranca em serie.
 */
class SegundaViaPagamentoController extends Controller
{
    public function store(string $codigoPublico, CriarPagamentoDaInscricao $criarPagamento): RedirectResponse
    {
        $inscricao = Inscricao::query()
            ->with('evento')
            ->where('codigo_publico', $codigoPublico)
            ->firstOrFail();

        $recusa = $this->recusa($inscricao);

        if ($recusa !== null) {
            return redirect()
                ->to($this->urlAssinada($inscricao, 'inscricoes.acompanhar'))
                ->with('aviso', $recusa);
        }

        $criarPagamento($inscricao);

        return redirect()->to($this->urlAssinada($inscricao, 'inscricoes.pagamento'));
    }

    /**
     * O motivo, em linguagem simples, de nao dar para emitir o Pix agora.
     * Null quando da.
     */
    private function recusa(Inscricao $inscricao): ?string
    {
        if ($inscricao->situacao !== SituacaoInscricao::AguardandoPagamento) {
            return match ($inscricao->situacao) {
                SituacaoInscricao::Confirmada => 'Sua inscrição já está confirmada: não há nada a pagar.',
                SituacaoInscricao::Cancelada => 'Esta inscrição foi cancelada e não pode mais ser paga.',
                default => 'O prazo desta inscrição venceu e não é mais possível pagar.',
            };
        }

        if ($inscricao->prazoVencido()) {
            return 'O prazo desta inscrição venceu e não é mais possível pagar.';
        }

        return null;
    }

    private function urlAssinada(Inscricao $inscricao, string $rota): string
    {
        $prazo = $inscricao->prazo_pagamento ?? Carbon::now()->addDay();

        return URL::temporarySignedRoute(
            $rota,
            $prazo->copy()->addDay(),
            ['codigo_publico' => $inscricao->codigo_publico],
        );
    }
}
