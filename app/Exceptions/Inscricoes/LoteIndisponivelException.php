<?php

declare(strict_types=1);

namespace App\Exceptions\Inscricoes;

use App\Models\Lote;

/**
 * O lote pelo qual a pessoa tentou entrar nao vale mais (RN-L5, RN-L9).
 *
 * Sao tres recusas diferentes, e a diferenca entre elas importa para quem esta
 * do outro lado da tela: o lote VIROU enquanto ela preenchia (ha outro preco
 * agora), o lote ESGOTOU no instante do envio (a ultima vaga foi de outra
 * pessoa), ou NAO HA MAIS LOTE NENHUM (o evento acabou de fechar as inscricoes).
 *
 * Nenhuma delas e corrigida em silencio. Aceitar pelo lote novo cobraria um
 * preco que a pessoa nao viu; aceitar pelo lote velho venderia abaixo do
 * combinado. As duas saidas sao piores do que recusar e explicar.
 */
class LoteIndisponivelException extends InscricaoInvalidaException
{
    /**
     * RN-L5 — o lote virou entre a tela e o envio.
     */
    public static function oLoteVirou(Lote $vigente): self
    {
        return new self(['lote_id' => [
            'O lote de inscrição mudou enquanto você preenchia o formulário. '
            ."Agora vale o {$vigente->nome}, por ".self::emReais($vigente->valor_centavos).'. '
            .'Confira o novo valor e envie a inscrição novamente.',
        ]]);
    }

    /**
     * RN-L5 — a ultima vaga do lote foi tomada no mesmo instante.
     */
    public static function esgotouAgora(Lote $lote): self
    {
        return new self(['lote_id' => [
            "As vagas do {$lote->nome} acabaram neste instante. "
            .'Recarregue a página para ver por qual lote a inscrição continua.',
        ]]);
    }

    /**
     * RN-L9 — o evento tem lotes e nenhum deles vale mais.
     */
    public static function naoHaMaisLote(): self
    {
        return new self(['lote_id' => [
            'Os lotes de inscrição se esgotaram.',
        ]]);
    }

    /**
     * "R$ 150,00" — o valor como a pessoa o le.
     *
     * A conta e feita em inteiros do comeco ao fim: dinheiro em ponto flutuante
     * erra centavo, e centavo errado numa frase sobre preco e o pior lugar
     * possivel para um erro de arredondamento (D-06).
     */
    private static function emReais(int $centavos): string
    {
        $reais = intdiv($centavos, 100);
        $resto = abs($centavos % 100);

        return 'R$ '.number_format($reais, 0, ',', '.').','.str_pad((string) $resto, 2, '0', STR_PAD_LEFT);
    }
}
