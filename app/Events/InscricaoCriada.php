<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Inscricao;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Anuncio interno: uma inscricao acabou de ser criada e ja prendeu as vagas.
 *
 * Nao tem ouvintes nesta entrega. Existe para que a fase de comunicacao
 * (e-mails, lembretes) seja acrescentada sem tocar nas regras de inscricao.
 */
class InscricaoCriada
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Inscricao $inscricao) {}
}
