<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situacoes possiveis de uma cobranca.
 *
 * "Pago" existe apenas aqui: a inscricao nunca fica "paga", ela fica
 * "confirmada" quando o dinheiro e reconhecido por fonte confiavel.
 */
enum SituacaoPagamento: string
{
    case Pendente = 'pendente';
    case Pago = 'pago';
    case Falhou = 'falhou';
    case Expirado = 'expirado';
    case Cancelado = 'cancelado';
    case Estornado = 'estornado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'Aguardando pagamento',
            self::Pago => 'Pago',
            self::Falhou => 'Nao concluido',
            self::Expirado => 'Prazo vencido',
            self::Cancelado => 'Cancelado',
            self::Estornado => 'Estornado',
        };
    }

    /**
     * Cobranca que ainda pode ser paga.
     */
    public function estaAberta(): bool
    {
        return $this === self::Pendente;
    }

    /**
     * Traduz o vocabulario neutro usado na fronteira com o provedor
     * (createPayment, getPayment, parseWebhook) para o vocabulario do dominio.
     *
     * A fronteira fala ingles porque espelha a API de quem esta do outro lado;
     * daqui para dentro tudo e portugues.
     */
    public static function deStatusExterno(string $status): ?self
    {
        return match ($status) {
            'pending' => self::Pendente,
            'paid' => self::Pago,
            'failed' => self::Falhou,
            'expired' => self::Expirado,
            'canceled' => self::Cancelado,
            'refunded' => self::Estornado,
            default => null,
        };
    }

    /**
     * O caminho inverso: usado apenas pelo provedor simulado.
     */
    public function paraStatusExterno(): string
    {
        return match ($this) {
            self::Pendente => 'pending',
            self::Pago => 'paid',
            self::Falhou => 'failed',
            self::Expirado => 'expired',
            self::Cancelado => 'canceled',
            self::Estornado => 'refunded',
        };
    }
}
