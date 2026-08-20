<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GrupoParticipanteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grupo de participantes vinculado a uma cidade.
 *
 * Nao confundir com GrupoAtividade, que reune atividades de um dia do evento.
 */
class GrupoParticipante extends Model
{
    /** @use HasFactory<GrupoParticipanteFactory> */
    use HasFactory;

    protected $table = 'grupos_participantes';

    protected $fillable = [
        'cidade_id',
        'nome',
        'ativo',
    ];

    /**
     * @return BelongsTo<Cidade, $this>
     */
    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('ativo', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDaCidade(Builder $query, int $cidadeId): void
    {
        $query->where('cidade_id', $cidadeId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }
}
