<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConflitoAtividadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Par de atividades que nao podem ser escolhidas juntas, mesmo sem choque de
 * horario. O par e sempre normalizado: o menor identificador vem primeiro.
 */
class ConflitoAtividade extends Model
{
    /** @use HasFactory<ConflitoAtividadeFactory> */
    use HasFactory;

    protected $table = 'conflitos_atividades';

    protected $fillable = [
        'atividade_a_id',
        'atividade_b_id',
        'motivo',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $conflito): void {
            [$conflito->atividade_a_id, $conflito->atividade_b_id] = self::normalizarPar(
                (int) $conflito->atividade_a_id,
                (int) $conflito->atividade_b_id,
            );
        });
    }

    /**
     * Coloca o par em ordem crescente, do jeito que o banco exige.
     *
     * @return array{0: int, 1: int}
     */
    public static function normalizarPar(int $primeira, int $segunda): array
    {
        return $primeira <= $segunda ? [$primeira, $segunda] : [$segunda, $primeira];
    }

    /**
     * @return BelongsTo<Atividade, $this>
     */
    public function atividadeA(): BelongsTo
    {
        return $this->belongsTo(Atividade::class, 'atividade_a_id');
    }

    /**
     * @return BelongsTo<Atividade, $this>
     */
    public function atividadeB(): BelongsTo
    {
        return $this->belongsTo(Atividade::class, 'atividade_b_id');
    }

    /**
     * Conflitos declarados entre as atividades da lista recebida.
     *
     * @param  Builder<$this>  $query
     * @param  array<int, int>  $atividadeIds
     */
    public function scopeEntreAtividades(Builder $query, array $atividadeIds): void
    {
        $query->whereIn('atividade_a_id', $atividadeIds)
            ->whereIn('atividade_b_id', $atividadeIds);
    }
}
