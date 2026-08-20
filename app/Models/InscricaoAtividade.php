<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligacao entre uma inscricao e uma atividade escolhida.
 *
 * Existe como model proprio (e nao apenas como tabela de ligacao) porque a
 * linha tem vida propria: e ela que justifica cada vaga reservada em uma
 * atividade.
 */
class InscricaoAtividade extends Model
{
    protected $table = 'inscricoes_atividades';

    protected $fillable = [
        'inscricao_id',
        'atividade_id',
    ];

    /**
     * @return BelongsTo<Inscricao, $this>
     */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /**
     * @return BelongsTo<Atividade, $this>
     */
    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }
}
