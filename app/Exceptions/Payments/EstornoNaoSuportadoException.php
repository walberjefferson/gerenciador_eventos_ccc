<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * O provedor em uso nao devolve dinheiro por este sistema.
 *
 * Nao e limitacao tecnica: e decisao. A politica de reembolso ainda nao foi
 * definida pelo dono do produto (pendencia P-02), e devolucao de Pix e
 * irreversivel. Enquanto nao houver regra escrita sobre quem pode devolver, em
 * que prazo e sob que condicao, o sistema recusa a operacao em voz alta em vez
 * de oferece-la sem regra.
 *
 * O identificador que a devolucao exigira no futuro — o da transferencia, e
 * nao o da cobranca — ja esta sendo guardado desde agora, em
 * pagamentos.metadados. Ele chega uma unica vez, no aviso de pagamento; nao
 * grava-lo hoje custaria caro no dia da decisao.
 */
class EstornoNaoSuportadoException extends RuntimeException
{
    public static function paraProvedor(string $provedor): self
    {
        return new self(
            "A devolucao de Pix nao esta disponivel pelo provedor \"{$provedor}\". ".
            'Enquanto a politica de reembolso nao for definida, a devolucao e combinada fora do sistema.'
        );
    }
}
