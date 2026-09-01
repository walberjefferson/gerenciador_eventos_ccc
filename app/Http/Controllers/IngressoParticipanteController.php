<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoInscricao;
use App\Models\Inscricao;
use App\Services\Ingressos\PdfDoIngresso;
use Symfony\Component\HttpFoundation\Response;

/**
 * O ingresso em PDF, para o participante imprimir.
 *
 * A rota e assinada, como todas as do participante: o codigo publico sozinho
 * nunca serve de senha, e sem assinatura valida o middleware responde 403
 * antes de este controller existir.
 *
 * Aqui ha uma segunda tranca, e ela e a que importa: SO INSCRICAO CONFIRMADA
 * baixa o ingresso. Quem ainda deve, quem expirou e quem foi cancelado recebem
 * 403 — nao um PDF com um codigo que a portaria recusaria depois, na frente da
 * fila. A assinatura prova quem esta pedindo; a situacao decide se ha o que
 * entregar.
 */
class IngressoParticipanteController extends Controller
{
    public function show(string $codigoPublico, PdfDoIngresso $pdfDoIngresso): Response
    {
        $inscricao = Inscricao::query()
            ->with(['evento', 'ingresso'])
            ->where('codigo_publico', $codigoPublico)
            ->firstOrFail();

        abort_unless($inscricao->situacao === SituacaoInscricao::Confirmada, 403);
        abort_unless($inscricao->ingresso !== null, 403);

        $pdf = $pdfDoIngresso($inscricao->ingresso);

        // Nome de arquivo com o codigo publico, e nao com o codigo do
        // ingresso: quem baixa dois ingressos da familia nao pode acabar com
        // dois arquivos de mesmo nome na pasta de downloads, e o codigo do
        // ingresso nao precisa aparecer no nome de um arquivo que circula.
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ingresso-'.$inscricao->codigo_publico.'.pdf"',
            // O ingresso e de uma pessoa so: nenhum intermediario guarda copia.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
