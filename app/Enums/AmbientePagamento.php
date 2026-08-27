<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Os dois ambientes do provedor de pagamento.
 *
 * Sao dois cadastros independentes, e nao um so com um interruptor: as
 * credenciais, o certificado e a chave Pix mudam por completo entre eles
 * (DA-27). Manter os dois guardados ao mesmo tempo e o que permite voltar
 * para homologacao para investigar um problema sem apagar o que vale.
 *
 * Diante de valor desconhecido, o sistema cai para homologacao — o lado que
 * nao move dinheiro de verdade.
 */
enum AmbientePagamento: string
{
    case Homologacao = 'homologacao';
    case Producao = 'producao';

    public function rotulo(): string
    {
        return match ($this) {
            self::Homologacao => 'Homologação (teste)',
            self::Producao => 'Produção (dinheiro de verdade)',
        };
    }

    public function ehProducao(): bool
    {
        return $this === self::Producao;
    }

    /**
     * Le um valor vindo de fora sem nunca devolver producao por engano.
     */
    public static function deTexto(?string $valor): self
    {
        return self::tryFrom(mb_strtolower(trim((string) $valor))) ?? self::Homologacao;
    }

    /**
     * @return array<int, string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }
}
