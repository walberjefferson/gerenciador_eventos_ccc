<?php

declare(strict_types=1);

namespace App\Exceptions\Inscricoes;

/**
 * O evento nao aceita esta inscricao agora (RN-01) ou os dados de origem do
 * participante nao combinam entre si (RN-02).
 */
class InscricaoIndisponivelException extends InscricaoInvalidaException
{
    public static function inscricoesAindaNaoAbriram(): self
    {
        return new self(['evento' => ['As inscrições para este evento ainda não começaram.']]);
    }

    public static function inscricoesEncerradas(): self
    {
        return new self(['evento' => ['As inscrições para este evento estão encerradas.']]);
    }

    public static function grupoNaoPertenceACidade(): self
    {
        return new self(['grupo_participante_id' => [
            'O grupo escolhido não pertence à cidade selecionada. Escolha a cidade novamente.',
        ]]);
    }

    public static function termosNaoAceitos(): self
    {
        return new self(['aceite_termos' => [
            'Você precisa aceitar o regulamento do evento para continuar.',
        ]]);
    }
}
