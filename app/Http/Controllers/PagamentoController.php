<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoInscricao;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Pagamentos\GeradorQrCodePix;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cobranca Pix de uma inscricao.
 *
 * As duas rotas sao assinadas: o codigo publico sozinho nunca serve de senha.
 * Quem chega sem assinatura valida — ou com o link vencido — recebe 403 do
 * proprio middleware, antes de o controller existir.
 *
 * O controller nao decide nada sobre dinheiro. Ele le a situacao que o
 * dominio ja gravou (mudada so por aviso do provedor ou por reconciliacao) e
 * a entrega para a tela. Nenhum parametro vindo do navegador muda situacao.
 */
class PagamentoController extends Controller
{
    /**
     * A tela da cobranca: valor, QR Code, copia e cola, prazo e o estado atual.
     */
    public function show(string $codigoPublico, GeradorQrCodePix $gerador): Response
    {
        $inscricao = $this->inscricao($codigoPublico);
        $pagamento = $this->pagamento($inscricao);
        $estado = $this->estado($inscricao, $pagamento);

        return Inertia::render('Inscricoes/Pagamento', [
            'codigo_publico' => $inscricao->codigo_publico,
            'nome_completo' => $inscricao->nome_completo,
            'evento' => [
                'nome' => $inscricao->evento?->nome,
                'slug' => $inscricao->evento?->slug,
            ],
            'estado' => $estado,
            'situacao' => $inscricao->situacao->value,
            'situacao_rotulo' => $inscricao->situacao->rotulo(),
            'valor_centavos' => $inscricao->valor_centavos,
            'moeda' => $inscricao->evento?->moeda ?? 'BRL',
            'prazo_pagamento' => $inscricao->prazo_pagamento?->toIso8601String(),
            'confirmada_em' => $inscricao->confirmada_em?->toIso8601String(),
            'pagamento' => $pagamento === null ? null : [
                'situacao' => $pagamento->situacao->value,
                'situacao_rotulo' => $pagamento->situacao->rotulo(),
                // O copia e cola so faz sentido enquanto ainda da para pagar.
                'pix_copia_e_cola' => $estado === 'aguardando' ? $pagamento->pix_copia_e_cola : null,
                'qr_code_svg' => $estado === 'aguardando' ? $gerador->svg($pagamento->pix_copia_e_cola) : null,
                'expira_em' => $pagamento->expira_em?->toIso8601String(),
                'pago_em' => $pagamento->pago_em?->toIso8601String(),
            ],
            // A tela pergunta por aqui, de tempos em tempos, se o dinheiro
            // chegou. Assinada tambem: consultar situacao e ler dado de
            // inscricao alheia.
            'url_situacao' => $this->urlDaSituacao($inscricao),
        ]);
    }

    /**
     * Resposta curta para a tela saber se algo mudou, sem recarregar a pagina.
     */
    public function situacao(string $codigoPublico): JsonResponse
    {
        $inscricao = $this->inscricao($codigoPublico);
        $pagamento = $this->pagamento($inscricao);

        return response()->json([
            'situacao' => $inscricao->situacao->value,
            'situacao_rotulo' => $inscricao->situacao->rotulo(),
            'estado' => $this->estado($inscricao, $pagamento),
            'pago_em' => $pagamento?->pago_em?->toIso8601String(),
        ]);
    }

    private function inscricao(string $codigoPublico): Inscricao
    {
        return Inscricao::query()
            ->with('evento')
            ->where('codigo_publico', $codigoPublico)
            ->firstOrFail();
    }

    /**
     * A cobranca que interessa: a que ainda pode ser paga; na falta dela, a
     * ultima emitida — e ela que explica por que nao da mais para pagar.
     */
    private function pagamento(Inscricao $inscricao): ?Pagamento
    {
        return $inscricao->pagamentoPendente()
            ?? $inscricao->pagamentos()->orderByDesc('id')->first();
    }

    /**
     * Qual das tres telas o participante vai ver.
     *
     * A confirmacao vem sempre do dominio: a inscricao so fica confirmada
     * quando o pagamento e reconhecido por fonte confiavel. Aqui apenas
     * lemos o que ja esta gravado.
     */
    private function estado(Inscricao $inscricao, ?Pagamento $pagamento): string
    {
        if ($inscricao->situacao === SituacaoInscricao::Confirmada) {
            return 'confirmada';
        }

        if ($inscricao->situacao !== SituacaoInscricao::AguardandoPagamento) {
            return 'expirada';
        }

        if ($pagamento === null || ! $pagamento->estaAberto()) {
            return 'expirada';
        }

        // O prazo pode ter vencido sem que a rotina de expiracao tenha passado
        // ainda. Para quem esta olhando a tela, ja acabou.
        if ($inscricao->prazoVencido() || ($pagamento->expira_em !== null && $pagamento->expira_em < Carbon::now())) {
            return 'expirada';
        }

        return 'aguardando';
    }

    /**
     * A validade acompanha a da tela: prazo de pagamento com 24 horas de
     * folga, para a consulta nao morrer antes da pagina que a usa.
     */
    private function urlDaSituacao(Inscricao $inscricao): string
    {
        $prazo = $inscricao->prazo_pagamento ?? Carbon::now()->addDay();

        return URL::temporarySignedRoute(
            'inscricoes.situacao',
            $prazo->copy()->addDay(),
            ['codigo_publico' => $inscricao->codigo_publico],
        );
    }
}
