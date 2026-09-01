<?php

declare(strict_types=1);

namespace App\Services\Ingressos;

/**
 * O codigo que vai dentro do QR Code e que se digita na portaria.
 *
 * Tres decisoes moram aqui, e cada uma tem um motivo concreto:
 *
 * 1. O codigo e SORTEADO, nunca derivado do codigo_publico da inscricao nem
 *    de qualquer dado da pessoa. Aquele ULID ja viajou em e-mails antigos e
 *    em URLs de acompanhamento: quem tivesse uma mensagem velha entraria no
 *    evento. Aqui o ingresso e uma credencial nova, com vida propria.
 *
 * 2. O alfabeto e o base32 de Douglas Crockford — os dez digitos mais 22
 *    letras, SEM "I", "L", "O" e "U". As tres primeiras somem porque se
 *    confundem com 1 e 0 na mao de quem le um papel amassado na fila; o "U"
 *    sai porque, junto com as outras letras, forma palavra que ninguem quer
 *    ver impressa num ingresso.
 *
 * 3. Doze caracteres de cinco bits dao ~60 bits de entropia, sorteados com
 *    random_bytes (o gerador criptografico do sistema). Adivinhar um codigo
 *    valido por tentativa e inviavel mesmo sem limite de requisicao — e a
 *    rota de conferencia ainda tem limite, por cima disso.
 */
final class GeradorDeCodigo
{
    /**
     * Base32 de Crockford. A posicao de cada caractere e o valor dele: o
     * caractere na posicao 0 vale 0, o da posicao 31 vale 31.
     */
    public const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Quantos caracteres tem o codigo. Doze x 5 bits = 60 bits. */
    public const TAMANHO = 12;

    /**
     * Um codigo novo, sorteado.
     */
    public function __invoke(): string
    {
        // Oito bytes dao 64 bits; usamos 60 e descartamos os quatro que
        // sobram. Ler bit a bit e mais longo que dividir um inteiro, mas nao
        // depende do tamanho da palavra do processador nem do sinal do
        // unpack(), que em PHP transforma 64 bits em inteiro com sinal.
        $bits = '';

        foreach (str_split(random_bytes(8)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $codigo = '';

        for ($posicao = 0; $posicao < self::TAMANHO; $posicao++) {
            $codigo .= self::ALFABETO[(int) bindec(substr($bits, $posicao * 5, 5))];
        }

        return $codigo;
    }

    /**
     * O codigo do jeito que o banco guarda, a partir do que a pessoa digitou.
     *
     * Tira hifen, espaco e qualquer pontuacao, sobe para maiuscula e desfaz as
     * confusoes que o alfabeto de Crockford preve: quem digita "O" quis dizer
     * zero, e quem digita "I" ou "L" quis dizer um. Sem isso, um codigo lido
     * corretamente de um papel seria recusado por causa da fonte da impressora.
     */
    public static function normalizar(string $codigo): string
    {
        $limpo = mb_strtoupper(trim($codigo));
        $limpo = (string) preg_replace('/[^0-9A-Z]/', '', $limpo);

        return strtr($limpo, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /**
     * O codigo em grupos de quatro — "ABCD-EFGH-JKMN" — so para leitura
     * humana. O banco continua guardando sem hifen, e a validacao normaliza
     * antes de comparar.
     */
    public static function formatar(string $codigo): string
    {
        $normalizado = self::normalizar($codigo);

        if ($normalizado === '') {
            return '';
        }

        return implode('-', str_split($normalizado, 4));
    }
}
