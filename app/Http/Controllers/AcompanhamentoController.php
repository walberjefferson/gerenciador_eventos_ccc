<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoInscricao;
use App\Http\Resources\InscricaoAcompanhamentoResource;
use App\Http\Resources\PagamentoHistoricoResource;
use App\Models\Inscricao;
use App\Services\Ingressos\GeradorQrCodeIngresso;
use App\Services\Inscricoes\LinhaDoTempoDaInscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A pagina do participante: o que ja aconteceu com a inscricao, o que falta e
 * o historico da cobranca.
 *
 * A rota e assinada. O codigo publico sozinho nunca serve de senha: sem
 * assinatura valida, o middleware responde 403 antes de o controller existir.
 *
 * Aqui so se le. A pagina mostra o que o dominio ja gravou e nao decide nada
 * sobre dinheiro, vaga ou situacao.
 */
class AcompanhamentoController extends Controller
{
    public function __construct(private readonly GeradorQrCodeIngresso $qrCodeIngresso) {}

    public function show(string $codigoPublico, LinhaDoTempoDaInscricao $linhaDoTempo): Response
    {
        $inscricao = Inscricao::query()
            ->with([
                'evento',
                'grupoParticipante.cidade',
                'atividades.grupoAtividade.diaEvento',
                'pagamentos',
                'ingresso',
            ])
            ->where('codigo_publico', $codigoPublico)
            ->firstOrFail();

        $podePagar = $inscricao->situacao === SituacaoInscricao::AguardandoPagamento
            && ! $inscricao->prazoVencido();

        // O ingresso so aparece para quem esta confirmado. Nao e questao de
        // tela: quem ainda deve nao pode sair daqui com um codigo que a
        // portaria recusaria depois, na frente da fila.
        $mostraIngresso = $inscricao->situacao === SituacaoInscricao::Confirmada
            && $inscricao->ingresso !== null;

        return Inertia::render('Inscricoes/Acompanhar', [
            'inscricao' => new InscricaoAcompanhamentoResource($inscricao),
            'linha_do_tempo' => $linhaDoTempo($inscricao),
            // Do mais recente para o mais antigo: a segunda via pode ter
            // gerado mais de uma cobranca, e a ultima e a que interessa.
            // resolve() entrega uma lista simples: colecao de Resource viria
            // embrulhada em "data", e o navegador nao precisa desse embrulho.
            'pagamentos' => PagamentoHistoricoResource::collection(
                $inscricao->pagamentos->sortByDesc('id')->values()
            )->resolve(),
            'pode_pagar' => $podePagar,
            'url_pagamento' => $podePagar ? $this->urlAssinada($inscricao, 'inscricoes.pagamento') : null,
            'url_segunda_via' => $podePagar ? $this->urlAssinada($inscricao, 'inscricoes.segunda-via') : null,
            // O desenho do QR vem pronto do servidor, em SVG, como ja acontece
            // com o QR do Pix: aparece mesmo com a rede ruim e nao depende de
            // biblioteca nenhuma no navegador.
            'qr_ingresso' => $mostraIngresso
                ? $this->qrCodeIngresso->svg((string) $inscricao->ingresso->codigo)
                : null,
            'url_ingresso_pdf' => $mostraIngresso ? $this->urlDoIngresso($inscricao) : null,
            // Explicacao deixada por quem redirecionou para ca — por exemplo,
            // um pedido de segunda via fora do prazo.
            'aviso' => session('aviso'),
        ]);
    }

    /**
     * Link assinado para outra tela do participante, com a mesma validade
     * usada na cobranca: o prazo de pagamento com 24 horas de folga, para a
     * pessoa ainda ver a explicacao em vez de um 403 sem motivo.
     */
    private function urlAssinada(Inscricao $inscricao, string $rota): string
    {
        $prazo = $inscricao->prazo_pagamento ?? Carbon::now()->addDay();

        return URL::temporarySignedRoute(
            $rota,
            $prazo->copy()->addDay(),
            ['codigo_publico' => $inscricao->codigo_publico],
        );
    }

    /**
     * Link assinado do ingresso em PDF.
     *
     * A validade NAO e a do prazo de pagamento: esse prazo ja passou para quem
     * esta confirmado, e um link vencido no mesmo instante em que nasce nao
     * serve a ninguem. Vale ate uma semana depois do fim do evento — tempo de
     * imprimir de novo o papel que ficou na impressora de casa — com um piso de
     * uma semana a partir de agora, para o caso de o evento ja ter acontecido.
     */
    private function urlDoIngresso(Inscricao $inscricao): string
    {
        $piso = Carbon::now()->addWeek();
        $fimDoEvento = $inscricao->evento?->data_fim?->copy()->endOfDay()->addWeek();

        return URL::temporarySignedRoute(
            'inscricoes.ingresso',
            $fimDoEvento !== null && $fimDoEvento->greaterThan($piso) ? $fimDoEvento : $piso,
            ['codigo_publico' => $inscricao->codigo_publico],
        );
    }
}
