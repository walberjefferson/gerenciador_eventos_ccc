<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Como o ingresso se apresenta a quem olha para ele.
 *
 * NAO e coluna do banco: e derivada de "usado_em" mais a situacao da
 * inscricao. Existe para que a tela tenha um rotulo e uma cor sem espalhar
 * "if" por toda parte — o mesmo motivo pelo qual SituacaoInscricao existe.
 *
 * Um ingresso vale enquanto a inscricao dele estiver confirmada e ninguem
 * tiver entrado com ele. Uma inscricao cancelada depois de paga derruba o
 * ingresso junto: a vaga ja voltou para a fila.
 */
enum SituacaoIngresso: string
{
    case Emitido = 'emitido';
    case Usado = 'usado';
    case Invalido = 'invalido';

    public function rotulo(): string
    {
        return match ($this) {
            self::Emitido => 'Válido',
            self::Usado => 'Já utilizado',
            self::Invalido => 'Não vale mais',
        };
    }
}
