<?php

declare(strict_types=1);

namespace App\Exceptions\Inscricoes;

/**
 * A combinacao de atividades escolhida nao respeita as regras do evento
 * (RN-03 a RN-08). Carrega todas as recusas de uma vez, para que o
 * participante corrija tudo em uma unica tentativa.
 */
class SelecaoAtividadesInvalidaException extends InscricaoInvalidaException
{
    /**
     * @param  array<int, string>  $mensagens
     */
    public static function com(array $mensagens): self
    {
        return new self(['atividades' => array_values($mensagens)]);
    }
}
