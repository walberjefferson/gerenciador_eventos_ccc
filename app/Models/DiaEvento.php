<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DiaEventoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um dia de programacao do evento.
 */
class DiaEvento extends Model
{
    /** @use HasFactory<DiaEventoFactory> */
    use HasFactory;

    protected $table = 'dias_evento';

    protected $fillable = [
        'evento_id',
        'nome',
        'descricao',
        'data',
        'posicao',
        'ativo',
    ];

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    /**
     * @return HasMany<GrupoAtividade, $this>
     */
    public function gruposAtividades(): HasMany
    {
        return $this->hasMany(GrupoAtividade::class)->orderBy('posicao');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('ativo', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'posicao' => 'integer',
            'ativo' => 'boolean',
        ];
    }
}
