<?php

declare(strict_types=1);

namespace App\Services\Pagamentos;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Transforma o "copia e cola" do Pix na imagem que a pessoa aponta a camera.
 *
 * O desenho sai em SVG e viaja dentro do proprio HTML: aparece mesmo com a
 * rede ruim, fica nitido em qualquer tamanho de tela e imprime bem. Nao
 * depende de imagick nem de biblioteca no navegador.
 *
 * Este servico nao decide nada sobre a cobranca: ele so redesenha o texto que
 * o provedor de pagamento ja gerou.
 */
final class GeradorQrCodePix
{
    /**
     * Lado do desenho, em unidades do SVG. O tamanho real na tela quem manda e
     * o CSS; este numero so define a resolucao interna do traco.
     */
    private const TAMANHO = 320;

    /**
     * Devolve o SVG pronto para ser embutido no HTML, ou `null` quando a
     * cobranca ainda nao tem codigo Pix.
     */
    public function svg(?string $pixCopiaECola): ?string
    {
        $conteudo = trim((string) $pixCopiaECola);

        if ($conteudo === '') {
            return null;
        }

        $escritor = new Writer(
            new ImageRenderer(
                new RendererStyle(self::TAMANHO, 1),
                new SvgImageBackEnd,
            ),
        );

        return $this->prepararParaEmbutir($escritor->writeString($conteudo));
    }

    /**
     * Tira a declaracao XML (que so faz sentido em arquivo solto) e marca o
     * desenho como imagem com nome proprio, para o leitor de tela nao anunciar
     * um amontoado de retangulos.
     */
    private function prepararParaEmbutir(string $svg): string
    {
        $svg = (string) preg_replace('/<\?xml.*?\?>\s*/s', '', $svg);

        return (string) preg_replace(
            '/<svg\b/',
            '<svg role="img" aria-label="QR Code para pagar com Pix" focusable="false"',
            $svg,
            1,
        );
    }
}
