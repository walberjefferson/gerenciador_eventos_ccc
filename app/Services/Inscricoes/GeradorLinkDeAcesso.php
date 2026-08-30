<?php

declare(strict_types=1);

namespace App\Services\Inscricoes;

use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * O link assinado que devolve o participante para a propria inscricao.
 *
 * A validade e curta de proposito (config inscricoes.validade_link_acesso, em
 * dias) e nao acompanha a vida util da inscricao: link que fica parado em
 * caixa de entrada precisa envelhecer sozinho. Vencido, a pessoa pede outro.
 */
class GeradorLinkDeAcesso
{
    public function para(Inscricao $inscricao): string
    {
        return URL::temporarySignedRoute(
            'inscricoes.acompanhar',
            Carbon::now()->addDays($this->validadeEmDias()),
            ['codigo_publico' => $inscricao->codigo_publico],
        );
    }

    public function validadeEmDias(): int
    {
        return max(1, (int) config('inscricoes.validade_link_acesso', 7));
    }
}
