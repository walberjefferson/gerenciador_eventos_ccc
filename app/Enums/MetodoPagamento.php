<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Como o participante paga a inscricao.
 */
enum MetodoPagamento: string
{
    case Pix = 'pix';
    case CartaoCredito = 'cartao_credito';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pix => 'Pix',
            self::CartaoCredito => 'Cartao de credito',
        };
    }
}
