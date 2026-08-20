<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CidadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cidade do catalogo global de participantes.
 */
class Cidade extends Model
{
    /** @use HasFactory<CidadeFactory> */
    use HasFactory;

    protected $table = 'cidades';

    protected $fillable = [
        'nome',
        'uf',
        'ativo',
    ];

    /**
     * @return HasMany<GrupoParticipante, $this>
     */
    public function gruposParticipantes(): HasMany
    {
        return $this->hasMany(GrupoParticipante::class);
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
            'ativo' => 'boolean',
        ];
    }
}
