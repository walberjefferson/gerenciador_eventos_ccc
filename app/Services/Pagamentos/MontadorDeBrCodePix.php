<?php

declare(strict_types=1);

namespace App\Services\Pagamentos;

use Illuminate\Support\Str;

/**
 * Monta um "Pix copia e cola" estatico no formato EMV — o mesmo padrao que os
 * aplicativos de banco leem.
 *
 * Ele nasceu privado dentro do provedor simulado, onde servia so para desenhar
 * uma cobranca ficticia. Foi extraido daqui porque passou a ter um segundo
 * chamador de verdade: o evento que recebe pela chave Pix do responsavel do
 * setor monta o proprio BR Code, localmente, sem falar com provedor nenhum
 * (RN-S2). Duas implementacoes do mesmo padrao EMV seria uma a mais.
 *
 * **Este servico nao conhece cobranca, inscricao nem evento.** Ele recebe
 * texto e centavos e devolve texto. Quem decide o que vai em cada campo e quem
 * chama.
 *
 * O codigo montado aqui e ESTATICO: ele carrega a chave, o valor e uma
 * identificacao, e nada mais. Nao existe do lado do banco nenhuma cobranca
 * correspondente, ninguem e avisado quando ele e pago, e o valor gravado no
 * campo 54 e sugestao, nao trava — quem copia a chave paga o que quiser. E
 * exatamente por isso que, no modo setor, alguem precisa conferir o
 * comprovante (RN-S13).
 */
final class MontadorDeBrCodePix
{
    /** O arranjo Pix, como o Banco Central o identifica dentro do campo 26. */
    private const GUI_PIX = 'br.gov.bcb.pix';

    /**
     * Monta o payload completo, ja com o CRC16 no fim.
     *
     * @param  string  $chave  a chave Pix de quem recebe, como ela foi cadastrada
     * @param  int  $valorCentavos  o valor sugerido, em centavos inteiros (D-06)
     * @param  string  $nomeDoRecebedor  o nome que aparece no aplicativo de quem paga
     * @param  string  $cidade  a cidade do recebedor, exigida pelo padrao
     * @param  string  $identificador  o campo 62-05, ate 25 caracteres
     * @param  string|null  $descricao  o campo 26-02, ate 72 caracteres; quando
     *                                  nulo o campo NAO e emitido, e o payload
     *                                  sai identico ao de quem nunca soube dele
     */
    public function montar(
        string $chave,
        int $valorCentavos,
        string $nomeDoRecebedor,
        string $cidade,
        string $identificador,
        ?string $descricao = null,
    ): string {
        $conta = $this->campo('00', self::GUI_PIX).$this->campo('01', $chave);

        // A descricao e o que o aplicativo do banco mostra a quem paga e a quem
        // recebe. Ela e OPCIONAL de proposito: sem ela, este metodo devolve
        // byte a byte o mesmo payload que o provedor simulado devolvia antes da
        // extracao — e o teste que compara os dois existe para provar isso.
        if ($descricao !== null && $this->limpar($descricao, 72) !== '') {
            $conta .= $this->campo('02', $this->limpar($descricao, 72));
        }

        $payload = $this->campo('00', '01')
            .$this->campo('26', $conta)
            .$this->campo('52', '0000')
            .$this->campo('53', '986')
            .$this->campo('54', $this->emReais($valorCentavos))
            .$this->campo('58', 'BR')
            .$this->campo('59', $this->limpar($nomeDoRecebedor, 25))
            .$this->campo('60', $this->limpar($cidade, 15))
            .$this->campo('62', $this->campo('05', $this->limpar($identificador, 25)))
            .'6304';

        return $payload.$this->crc16($payload);
    }

    /**
     * Centavos inteiros para o texto decimal que o campo 54 espera.
     *
     * **Sem divisao com virgula flutuante.** A versao anterior deste codigo,
     * dentro do provedor simulado, escrevia `number_format($centavos / 100, 2,
     * '.', '')` — o que D-06 proibe e o que EfiPaymentGateway::emReais() ja
     * evitava. Era inofensivo enquanto o payload era ficticio; deixa de ser no
     * instante em que ele passa a cobrar pela chave real de uma pessoa. 12345
     * vira "123.45" por recorte de inteiro, e nao por 12345/100 — que, em ponto
     * flutuante, nao e exatamente 123,45.
     *
     * Esta e a UNICA diferenca de comportamento permitida entre o codigo antigo
     * e o novo.
     */
    private function emReais(int $centavos): string
    {
        $centavos = max(0, $centavos);

        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Um campo EMV: o identificador, o tamanho em dois digitos, e o conteudo.
     */
    private function campo(string $id, string $valor): string
    {
        return $id.str_pad((string) mb_strlen($valor), 2, '0', STR_PAD_LEFT).$valor;
    }

    /**
     * O formato EMV so aceita letras sem acento, numeros e espaco.
     *
     * O ULID que serve de codigo publico da inscricao ja e alfanumerico e
     * maiusculo: ele atravessa este saneamento intacto. A palavra que o
     * acompanha na descricao e escrita sem acento na origem pelo mesmo motivo.
     */
    private function limpar(string $texto, int $limite): string
    {
        $limpo = preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($texto)) ?? '';

        return Str::upper(Str::substr(trim($limpo), 0, $limite));
    }

    /**
     * O CRC16-CCITT do padrao EMV, que fecha o payload.
     */
    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($payload); $i++) {
            $crc ^= ord($payload[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return Str::upper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
