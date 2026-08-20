<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situacoes possiveis de um evento.
 *
 * O valor gravado no banco e o texto em portugues sem acento; o rotulo e o
 * texto que aparece na tela para uma pessoa.
 */
enum SituacaoEvento: string
{
    case Rascunho = 'rascunho';
    case Publicado = 'publicado';
    case InscricoesAbertas = 'inscricoes_abertas';
    case InscricoesEncerradas = 'inscricoes_encerradas';
    case Finalizado = 'finalizado';
    case Cancelado = 'cancelado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Publicado => 'Publicado',
            self::InscricoesAbertas => 'Inscrições abertas',
            self::InscricoesEncerradas => 'Inscrições encerradas',
            self::Finalizado => 'Finalizado',
            self::Cancelado => 'Cancelado',
        };
    }

    /**
     * Situacao em que o evento pode receber novas inscricoes.
     */
    public function aceitaInscricoes(): bool
    {
        return $this === self::InscricoesAbertas;
    }
}
