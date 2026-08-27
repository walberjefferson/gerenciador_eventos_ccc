<?php

declare(strict_types=1);

namespace App\Services\Payments\Efi;

/**
 * O dicionario entre o vocabulario da Efi e o vocabulario neutro da fronteira.
 *
 * Fica separado do gateway porque e a unica parte da integracao que muda
 * quando a Efi muda: se amanha aparecer um status novo, e este arquivo que se
 * abre, e nao a emissao de cobranca.
 *
 * Uma ausencia importante: a Efi NAO tem status de cobranca vencida. Passado o
 * prazo de expiracao, a consulta continua respondendo "ATIVA". Por isso
 * "ATIVA" vira sempre "pending" — quem decide que o prazo venceu e o
 * prazo_pagamento da inscricao, aqui dentro, e nao o provedor. Traduzir
 * "ATIVA" para "expired" por conta de relogio seria fechar cobranca que a Efi
 * ainda aceita pagar: o dinheiro entraria e a vaga nao existiria mais.
 */
final class TraducaoDeStatus
{
    public const ATIVA = 'ATIVA';

    public const CONCLUIDA = 'CONCLUIDA';

    public const REMOVIDA_PELO_RECEBEDOR = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';

    public const REMOVIDA_PELO_PSP = 'REMOVIDA_PELO_PSP';

    /**
     * Traduz o status de uma cobranca. Status desconhecido devolve null, e
     * quem chama trata como "nada a aplicar" — inventar uma traducao para o
     * que nao se conhece e a forma mais rapida de cancelar cobranca boa.
     */
    public static function daCobranca(string $status): ?string
    {
        return match (mb_strtoupper(trim($status))) {
            self::ATIVA => 'pending',
            self::CONCLUIDA => 'paid',
            self::REMOVIDA_PELO_RECEBEDOR, self::REMOVIDA_PELO_PSP => 'canceled',
            default => null,
        };
    }

    /**
     * O aviso de webhook da Efi nao tem campo de situacao: ele diz apenas que
     * um Pix caiu, com o identificador da cobranca. A leitura correta e uma
     * so — se o dinheiro entrou, a cobranca esta paga.
     */
    public static function doAvisoDePixRecebido(): string
    {
        return 'paid';
    }
}
