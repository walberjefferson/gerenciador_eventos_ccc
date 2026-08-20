<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AtividadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Atividade que o participante pode escolher dentro de um grupo de atividades.
 *
 * Assim como no evento, vagas_reservadas e vagas_confirmadas sao contadores
 * mantidos por comandos atomicos.
 */
class Atividade extends Model
{
    /** @use HasFactory<AtividadeFactory> */
    use HasFactory;

    protected $table = 'atividades';

    protected $fillable = [
        'grupo_atividade_id',
        'nome',
        'descricao',
        'comeca_em',
        'termina_em',
        'capacidade',
        'idade_minima',
        'idade_maxima',
        'posicao',
        'ativo',
        'configuracoes',
    ];

    /**
     * @return BelongsTo<GrupoAtividade, $this>
     */
    public function grupoAtividade(): BelongsTo
    {
        return $this->belongsTo(GrupoAtividade::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('ativo', true);
    }

    /**
     * Atividades que pertencem a um evento, atravessando grupo e dia.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDoEvento(Builder $query, int $eventoId): void
    {
        $query->whereHas(
            'grupoAtividade.diaEvento',
            fn (Builder $dia) => $dia->where('evento_id', $eventoId)
        );
    }

    /**
     * Duas atividades se sobrepoem quando uma comeca antes de a outra terminar,
     * dos dois lados. Limites que apenas se encostam (uma termina exatamente
     * quando a outra comeca) NAO se sobrepoem.
     */
    public function sobrepoe(self $outra): bool
    {
        return $this->comeca_em < $outra->termina_em
            && $this->termina_em > $outra->comeca_em;
    }

    /**
     * Idade que a pessoa tera no dia em que a atividade comeca.
     */
    public function idadeNaData(Carbon $dataNascimento): int
    {
        return (int) $dataNascimento->copy()->startOfDay()->diffInYears(
            $this->comeca_em->copy()->startOfDay()
        );
    }

    public function aceitaIdade(Carbon $dataNascimento): bool
    {
        $idade = $this->idadeNaData($dataNascimento);

        if ($this->idade_minima !== null && $idade < $this->idade_minima) {
            return false;
        }

        return ! ($this->idade_maxima !== null && $idade > $this->idade_maxima);
    }

    public function vagasOcupadas(): int
    {
        return $this->vagas_reservadas + $this->vagas_confirmadas;
    }

    /**
     * Devolve null quando a atividade nao tem limite de vagas.
     */
    public function vagasDisponiveis(): ?int
    {
        if ($this->capacidade === null) {
            return null;
        }

        return max(0, $this->capacidade - $this->vagasOcupadas());
    }

    public function temVagaDisponivel(): bool
    {
        return $this->capacidade === null || $this->vagasOcupadas() < $this->capacidade;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'comeca_em' => 'datetime',
            'termina_em' => 'datetime',
            'capacidade' => 'integer',
            'idade_minima' => 'integer',
            'idade_maxima' => 'integer',
            'posicao' => 'integer',
            'ativo' => 'boolean',
            'configuracoes' => 'array',
            'vagas_reservadas' => 'integer',
            'vagas_confirmadas' => 'integer',
        ];
    }
}
