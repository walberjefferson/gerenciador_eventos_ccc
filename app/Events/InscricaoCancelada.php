<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Anuncio interno: a organizacao cancelou a inscricao de alguem, e as vagas
 * que ela ocupava ja voltaram para a fila.
 *
 * E diferente da expiracao: aqui existe uma pessoa responsavel pela decisao e
 * um motivo escrito por ela. Os dois viajam junto com o anuncio para que a
 * fase de comunicacao possa explicar ao participante o que aconteceu.
 *
 * E disparado uma unica vez por inscricao — quem cancela e sempre a transicao
 * condicional "ativa -> cancelada", que so acontece uma vez.
 *
 * Nao tem ouvintes nesta entrega. Existe para que a fase de comunicacao
 * (e-mail de aviso) seja acrescentada sem tocar em uma linha da regra de vaga.
 */
class InscricaoCancelada
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Inscricao $inscricao,
        public readonly string $motivo,
        public readonly ?User $responsavel = null,
        public readonly bool $estavaConfirmada = false,
    ) {}
}
