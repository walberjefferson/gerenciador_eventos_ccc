<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Como o participante paga a inscricao.
 *
 * Os dois primeiros sao cobrancas emitidas pelo provedor. Os tres ultimos so
 * aparecem quando o dinheiro entra por fora do sistema e um administrador
 * declara isso na mao — dinheiro na secretaria, transferencia direta ou
 * qualquer outro arranjo combinado com a organizacao.
 */
enum MetodoPagamento: string
{
    case Pix = 'pix';
    case CartaoCredito = 'cartao_credito';
    case Dinheiro = 'dinheiro';
    case Transferencia = 'transferencia';
    case Outro = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pix => 'Pix',
            self::CartaoCredito => 'Cartão de crédito',
            self::Dinheiro => 'Dinheiro',
            self::Transferencia => 'Transferência',
            self::Outro => 'Outro',
        };
    }

    /**
     * Os metodos que um administrador pode declarar na mao.
     *
     * Pix e cartao ficam de fora de proposito: quando o pagamento passa pelo
     * provedor, quem reconhece o dinheiro e o provedor, nao uma pessoa.
     *
     * @return array<int, self>
     */
    public static function manuais(): array
    {
        return [self::Dinheiro, self::Transferencia, self::Outro];
    }

    /**
     * O metodo veio de uma declaracao manual?
     */
    public function ehManual(): bool
    {
        return in_array($this, self::manuais(), true);
    }

    /**
     * Os valores gravados dos metodos declarados na mao.
     *
     * @return array<int, string>
     */
    public static function valoresManuais(): array
    {
        return array_map(fn (self $metodo): string => $metodo->value, self::manuais());
    }
}
