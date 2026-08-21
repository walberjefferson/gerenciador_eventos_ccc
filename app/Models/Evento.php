<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SituacaoEvento;
use Database\Factories\EventoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Evento: a unidade que recebe inscricoes.
 *
 * As colunas vagas_reservadas e vagas_confirmadas sao contadores mantidos por
 * comandos atomicos (ver App\Actions\Inscricoes\ReservarVagas). Nunca devem ser
 * alteradas com leitura seguida de gravacao.
 */
class Evento extends Model
{
    /** @use HasFactory<EventoFactory> */
    use HasFactory;

    protected $table = 'eventos';

    protected $fillable = [
        'codigo_publico',
        'nome',
        'slug',
        'descricao',
        'banner_caminho',
        'data_inicio',
        'data_fim',
        'inscricoes_abrem_em',
        'inscricoes_fecham_em',
        'capacidade',
        'valor_centavos',
        'moeda',
        'prazo_pagamento_minutos',
        'situacao',
        'regulamento',
        'versao_termos',
        'contato_email',
        'contato_telefone',
        'configuracoes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $evento): void {
            $evento->codigo_publico ??= (string) Str::ulid();
        });
    }

    /**
     * @return HasMany<DiaEvento, $this>
     */
    public function diasEvento(): HasMany
    {
        return $this->hasMany(DiaEvento::class)->orderBy('posicao');
    }

    /**
     * As inscricoes feitas neste evento, em qualquer situacao.
     *
     * A tela de cadastro usa isso para saber, antes de aceitar uma mudanca de
     * estrutura, se ha gente inscrita para se preocupar.
     *
     * @return HasMany<Inscricao, $this>
     */
    public function inscricoes(): HasMany
    {
        return $this->hasMany(Inscricao::class);
    }

    /**
     * @return HasManyThrough<GrupoAtividade, DiaEvento, $this>
     */
    public function gruposAtividades(): HasManyThrough
    {
        return $this->hasManyThrough(GrupoAtividade::class, DiaEvento::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeComInscricoesAbertas(Builder $query, ?Carbon $momento = null): void
    {
        $momento ??= Carbon::now();

        $query->where('situacao', SituacaoEvento::InscricoesAbertas)
            ->where('inscricoes_abrem_em', '<=', $momento)
            ->where('inscricoes_fecham_em', '>=', $momento);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePeloSlug(Builder $query, string $slug): void
    {
        $query->where('slug', $slug);
    }

    /**
     * O periodo de inscricao esta aberto neste instante?
     */
    public function inscricoesEstaoAbertas(?Carbon $momento = null): bool
    {
        $momento ??= Carbon::now();

        return $this->situacao->aceitaInscricoes()
            && $this->inscricoes_abrem_em <= $momento
            && $this->inscricoes_fecham_em >= $momento;
    }

    /**
     * Vagas ja ocupadas: as presas aguardando pagamento mais as pagas.
     */
    public function vagasOcupadas(): int
    {
        return $this->vagas_reservadas + $this->vagas_confirmadas;
    }

    /**
     * Vagas ainda disponiveis. Devolve null quando o evento nao tem limite.
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
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'inscricoes_abrem_em' => 'datetime',
            'inscricoes_fecham_em' => 'datetime',
            'capacidade' => 'integer',
            'valor_centavos' => 'integer',
            'prazo_pagamento_minutos' => 'integer',
            'situacao' => SituacaoEvento::class,
            'configuracoes' => 'array',
            'vagas_reservadas' => 'integer',
            'vagas_confirmadas' => 'integer',
        ];
    }
}
