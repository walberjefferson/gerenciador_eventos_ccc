<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use RuntimeException;
use Throwable;

/**
 * Erro vindo da Efi, ja traduzido para o nosso vocabulario.
 *
 * Existe por dois motivos. O primeiro e que a excecao do SDK carrega o corpo
 * inteiro da resposta, e corpo inteiro de resposta de instituicao financeira
 * costuma trazer cabecalho, token e caminho de arquivo junto — coisa que nao
 * pode acabar em log nem em tela. O segundo e que quem chama o gateway nao
 * deveria precisar conhecer o SDK para tratar um erro.
 *
 * A mensagem e curta, em portugues e escrita para ser lida por gente. O que
 * identifica o erro de verdade — o codigo HTTP e o nome que a Efi da a ele —
 * fica em campos proprios, para o gateway decidir o que fazer (por exemplo,
 * tentar de novo diante de um txid duplicado).
 */
class EfiException extends RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly ?int $codigoHttp = null,
        public readonly ?string $identificador = null,
        ?Throwable $anterior = null,
    ) {
        parent::__construct($mensagem, 0, $anterior);
    }

    /**
     * A cobranca ja existe com este identificador (HTTP 409, "txid_duplicado").
     *
     * Vale a pena distinguir: e o unico erro da emissao que se resolve
     * sozinho, gerando outro identificador e tentando mais uma vez.
     */
    public function ehTxidDuplicado(): bool
    {
        return $this->codigoHttp === 409 && $this->identificador === 'txid_duplicado';
    }

    /**
     * Excesso de requisicoes (HTTP 429). Nao e defeito nosso nem da cobranca:
     * e ritmo. Quem chama pode tentar de novo mais tarde.
     */
    public function ehExcessoDeRequisicoes(): bool
    {
        return $this->codigoHttp === 429;
    }
}
