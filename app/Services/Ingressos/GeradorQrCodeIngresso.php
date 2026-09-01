<?php

declare(strict_types=1);

namespace App\Services\Ingressos;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Desenha o codigo do ingresso como QR Code, em dois formatos.
 *
 * O QUE VAI DENTRO DO DESENHO: apenas o codigo, como texto puro. Nao vai URL,
 * nao vai nome, nao vai CPF, nao vai e-mail. Se carregasse endereco, qualquer
 * camera de celular transformaria o ingresso em link clicavel, e um print
 * compartilhado no grupo da familia viraria convite. Como texto puro, o
 * desenho so serve dentro da tela da portaria, que e onde a permissao e
 * conferida.
 *
 * POR QUE DOIS FORMATOS, E POR QUE ESTA BIBLIOTECA:
 *
 * - SVG para a TELA: fica nitido em qualquer tamanho, imprime bem e viaja
 *   dentro do proprio HTML, como ja acontece com o QR do Pix.
 * - PNG para o E-MAIL e para o PDF: Gmail e Outlook NAO exibem SVG, nem
 *   embutido nem em <img>. Um ingresso que nao aparece no e-mail e um
 *   ingresso que nao existe. O dompdf, por sua vez, nao e confiavel com SVG.
 *
 * O QR do Pix continua no bacon/bacon-qr-code e continua intocado
 * (GeradorQrCodePix): ele so precisa de SVG, e mexer no desenho de uma
 * cobranca para arrumar a vida do ingresso seria risco desproporcional. Esta
 * classe usa endroid/qr-code porque e ela que entrega PNG — o que exige a
 * extensao "gd" do PHP, declarada no Dockerfile.
 */
final class GeradorQrCodeIngresso
{
    /**
     * Lado do desenho, em pixels. No SVG e so a resolucao interna do traco (o
     * tamanho na tela quem manda e o CSS); no PNG e o tamanho de verdade, e
     * 320 e o suficiente para uma camera ler da tela de um celular.
     */
    private const TAMANHO = 320;

    /**
     * A borda branca em volta. Leitor de QR precisa dela para achar as bordas
     * do desenho: sem margem, a leitura falha justamente no papel impresso.
     */
    private const MARGEM = 16;

    /**
     * O desenho pronto para ser embutido no HTML da tela.
     */
    public function svg(string $codigo): string
    {
        $resultado = $this->construtor($codigo)->build(
            writer: new SvgWriter,
            writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true],
        );

        // Marcado como imagem com nome proprio, para o leitor de tela anunciar
        // "QR Code do ingresso" em vez de um amontoado de retangulos.
        return (string) preg_replace(
            '/<svg\b/',
            '<svg role="img" aria-label="QR Code do ingresso" focusable="false"',
            $resultado->getString(),
            1,
        );
    }

    /**
     * Os bytes do PNG: e o que vai como anexo embutido no e-mail e o que o PDF
     * carrega dentro de si.
     */
    public function png(string $codigo): string
    {
        return $this->construtor($codigo)->build(writer: new PngWriter)->getString();
    }

    private function construtor(string $codigo): Builder
    {
        return new Builder(
            data: GeradorDeCodigo::normalizar($codigo),
            // Nivel medio de correcao: o desenho continua legivel com parte
            // dele suja, dobrada ou refletindo a luz do portao — situacao
            // normal de um papel que passou o dia no bolso.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::TAMANHO,
            margin: self::MARGEM,
        );
    }
}
