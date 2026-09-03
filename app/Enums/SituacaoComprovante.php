<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Em que pe esta o comprovante que o participante enviou.
 *
 * Sao tres situacoes e nenhuma delas e situacao de inscricao: enviar
 * comprovante NAO confirma nada (RN-S7). A inscricao segue aguardando
 * pagamento ate uma pessoa conferir; o que muda aqui e so o que a tela do
 * participante consegue contar para ele enquanto isso.
 */
enum SituacaoComprovante: string
{
    /** Chegou e espera conferencia. E o unico estado em aberto. */
    case Enviado = 'enviado';

    /** Alguem olhou, reconheceu o dinheiro e confirmou a inscricao. */
    case Aceito = 'aceito';

    /** Alguem olhou e recusou, com o motivo escrito. */
    case Recusado = 'recusado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Enviado => 'Em conferência',
            self::Aceito => 'Aceito',
            self::Recusado => 'Recusado',
        };
    }

    /**
     * Este comprovante ainda espera alguem?
     */
    public function estaEmAberto(): bool
    {
        return $this === self::Enviado;
    }
}
