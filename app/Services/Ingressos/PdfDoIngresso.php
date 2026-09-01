<?php

declare(strict_types=1);

namespace App\Services\Ingressos;

use App\Models\Ingresso;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * O ingresso em papel: o arquivo que a pessoa imprime e leva no bolso.
 *
 * O motor e o dompdf — PHP puro, sem binario externo. A alternativa mais
 * popular (spatie/laravel-pdf) desenha a pagina num Chromium de verdade, o que
 * significa colocar um navegador inteiro dentro do conteiner de producao para
 * imprimir meia folha de papel.
 *
 * DUAS CONFIGURACOES QUE PARECEM DETALHE:
 *
 * - O QR vai como PNG embutido em "data:" URI, e nao como SVG: o suporte a SVG
 *   do dompdf e parcial e falha em silencio — o desenho simplesmente nao
 *   aparece, e so alguem imprimindo perceberia.
 * - Nada de rede: "isRemoteEnabled" fica DESLIGADO. O HTML do ingresso e
 *   nosso, mas ligar busca remota num gerador de PDF e abrir uma porta para o
 *   servidor buscar endereco que alguem escolheu (SSRF). O "data:" URI nao
 *   depende dessa permissao.
 */
final class PdfDoIngresso
{
    public function __construct(private readonly GeradorQrCodeIngresso $qrCode) {}

    /**
     * Os bytes do PDF, prontos para virar download.
     */
    public function __invoke(Ingresso $ingresso): string
    {
        $ingresso->loadMissing('inscricao.evento');

        $png = $this->qrCode->png((string) $ingresso->codigo);

        $html = View::make('pdf.ingresso', [
            'ingresso' => $ingresso,
            'inscricao' => $ingresso->inscricao,
            'evento' => $ingresso->inscricao?->evento,
            'codigoFormatado' => $ingresso->codigoFormatado(),
            'qrCode' => 'data:image/png;base64,'.base64_encode($png),
        ])->render();

        $opcoes = new Options;
        $opcoes->set('isRemoteEnabled', false);
        // DejaVu Sans vem junto com o dompdf e tem os acentos do portugues. A
        // fonte padrao (Helvetica) troca "inscrição" por "inscri??o" no papel.
        $opcoes->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($opcoes);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
