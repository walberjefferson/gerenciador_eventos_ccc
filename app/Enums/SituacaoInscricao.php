<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situacoes possiveis de uma inscricao.
 *
 * Nao existe situacao "pago": pagamento e fato do dinheiro e vive no dominio
 * Pagamento. A inscricao apenas fica "confirmada" quando o pagamento e
 * reconhecido por fonte confiavel.
 */
enum SituacaoInscricao: string
{
    case AguardandoPagamento = 'aguardando_pagamento';
    case Confirmada = 'confirmada';
    case Expirada = 'expirada';
    case Cancelada = 'cancelada';
    case ListaEspera = 'lista_espera';

    public function rotulo(): string
    {
        return match ($this) {
            self::AguardandoPagamento => 'Aguardando pagamento',
            self::Confirmada => 'Confirmada',
            self::Expirada => 'Expirada',
            self::Cancelada => 'Cancelada',
            self::ListaEspera => 'Lista de espera',
        };
    }

    /**
     * Situacao que ocupa vaga e bloqueia nova inscricao da mesma pessoa.
     */
    public function estaAtiva(): bool
    {
        return $this === self::AguardandoPagamento || $this === self::Confirmada;
    }

    /**
     * As situacoes ativas, na mesma ordem usada pelas unicidades parciais do
     * banco.
     *
     * @return array<int, self>
     */
    public static function ativas(): array
    {
        return [self::AguardandoPagamento, self::Confirmada];
    }

    /**
     * Os valores gravados das situacoes ativas.
     *
     * @return array<int, string>
     */
    public static function valoresAtivos(): array
    {
        return array_map(fn (self $situacao): string => $situacao->value, self::ativas());
    }
}
