<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GrupoAtividadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conjunto de atividades de um dia que compartilham a mesma regra de escolha.
 *
 * Nao confundir com GrupoParticipante, que e o grupo de pessoas de uma cidade.
 */
class GrupoAtividade extends Model
{
    /** @use HasFactory<GrupoAtividadeFactory> */
    use HasFactory;

    protected $table = 'grupos_atividades';

    protected $fillable = [
        'dia_evento_id',
        'nome',
        'descricao',
        'obrigatorio',
        'min_selecoes',
        'max_selecoes',
        'posicao',
        'ativo',
    ];

    /**
     * @return BelongsTo<DiaEvento, $this>
     */
    public function diaEvento(): BelongsTo
    {
        return $this->belongsTo(DiaEvento::class);
    }

    /**
     * @return HasMany<Atividade, $this>
     */
    public function atividades(): HasMany
    {
        return $this->hasMany(Atividade::class)->orderBy('posicao');
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
    public function scopeObrigatorios(Builder $query): void
    {
        $query->where('obrigatorio', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDoEvento(Builder $query, int $eventoId): void
    {
        $query->whereHas('diaEvento', fn (Builder $dia) => $dia->where('evento_id', $eventoId));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'obrigatorio' => 'boolean',
            'min_selecoes' => 'integer',
            'max_selecoes' => 'integer',
            'posicao' => 'integer',
            'ativo' => 'boolean',
        ];
    }
}
