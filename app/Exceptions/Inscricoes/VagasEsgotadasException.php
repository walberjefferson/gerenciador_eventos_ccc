<?php

declare(strict_types=1);

namespace App\Exceptions\Inscricoes;

use App\Models\Atividade;

/**
 * Nao ha mais vaga no evento (RN-10) ou na atividade escolhida (RN-09).
 *
 * Levantada quando a atualizacao atomica do contador nao altera nenhuma linha:
 * sinal de que a ultima vaga foi tomada por outra pessoa no mesmo instante.
 */
class VagasEsgotadasException extends InscricaoInvalidaException
{
    public static function doEvento(): self
    {
        return new self(['evento' => ['As vagas para este evento acabaram.']]);
    }

    public static function daAtividade(Atividade $atividade): self
    {
        return new self(['atividades' => [
            "As vagas de {$atividade->nome} acabaram. Escolha outra opção.",
        ]]);
    }
}
