<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Em que ponto esta o aviso automatico recebido do provedor de pagamento.
 *
 * "Ignorado" nao e erro: e o aviso que chegou sem assinatura valida, ou que
 * fala de uma cobranca que nao existe aqui, ou que repete algo ja resolvido.
 */
enum SituacaoWebhook: string
{
    case Recebido = 'recebido';
    case Processado = 'processado';
    case Ignorado = 'ignorado';
    case Falhou = 'falhou';

    public function rotulo(): string
    {
        return match ($this) {
            self::Recebido => 'Recebido',
            self::Processado => 'Processado',
            self::Ignorado => 'Ignorado',
            self::Falhou => 'Falhou',
        };
    }
}
