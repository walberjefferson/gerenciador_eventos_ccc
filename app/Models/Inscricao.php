<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SituacaoInscricao;
use Database\Factories\InscricaoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Inscricao de uma pessoa em um evento.
 *
 * A inscricao nunca e apagada: toda mudanca e de situacao, com o momento
 * gravado em coluna propria (confirmada_em, expirada_em, cancelada_em).
 */
class Inscricao extends Model
{
    /** @use HasFactory<InscricaoFactory> */
    use HasFactory;

    protected $table = 'inscricoes';

    protected $fillable = [
        'codigo_publico',
        'evento_id',
        'grupo_participante_id',
        'nome_completo',
        'email',
        'telefone',
        'documento',
        'documento_hash',
        'data_nascimento',
        'situacao',
        'valor_centavos',
        'versao_termos',
        'termos_aceitos_em',
        'chave_idempotencia',
        'prazo_pagamento',
        'confirmada_em',
        'expirada_em',
        'cancelada_em',
        'motivo_cancelamento',
    ];

    protected $hidden = [
        'documento',
        'documento_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $inscricao): void {
            $inscricao->codigo_publico ??= (string) Str::ulid();
        });
    }

    /**
     * Impressao digital do documento: mesmo CPF gera sempre o mesmo texto, mas
     * o caminho de volta nao existe. O segredo do servidor (pepper) impede que
     * alguem com a lista de CPFs do Brasil reconstrua a tabela por tentativa.
     */
    public static function hashDocumento(string $documento): string
    {
        $somenteDigitos = preg_replace('/\D/', '', $documento) ?? '';

        return hash('sha256', (string) config('app.documento_hash_pepper').'|'.$somenteDigitos);
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    /**
     * @return BelongsTo<GrupoParticipante, $this>
     */
    public function grupoParticipante(): BelongsTo
    {
        return $this->belongsTo(GrupoParticipante::class);
    }

    /**
     * Atividades escolhidas, sempre em ordem crescente de id — a mesma ordem em
     * que os contadores de vaga sao tocados.
     *
     * @return BelongsToMany<Atividade, $this>
     */
    public function atividades(): BelongsToMany
    {
        return $this->belongsToMany(Atividade::class, 'inscricoes_atividades')
            ->withTimestamps()
            ->orderBy('atividades.id');
    }

    /**
     * Ids das atividades escolhidas, em ordem crescente.
     *
     * @return array<int, int>
     */
    public function atividadeIdsOrdenados(): array
    {
        return $this->atividades()
            ->reorder('atividades.id')
            ->pluck('atividades.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAtivas(Builder $query): void
    {
        $query->whereIn('situacao', SituacaoInscricao::valoresAtivos());
    }

    /**
     * Inscricoes aguardando pagamento cujo prazo ja passou.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVencidas(Builder $query, ?Carbon $momento = null): void
    {
        $query->where('situacao', SituacaoInscricao::AguardandoPagamento)
            ->whereNotNull('prazo_pagamento')
            ->where('prazo_pagamento', '<', $momento ?? Carbon::now());
    }

    public function estaAtiva(): bool
    {
        return $this->situacao->estaAtiva();
    }

    public function prazoVencido(?Carbon $momento = null): bool
    {
        return $this->prazo_pagamento !== null
            && $this->prazo_pagamento < ($momento ?? Carbon::now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'situacao' => SituacaoInscricao::class,
            'valor_centavos' => 'integer',
            'documento' => 'encrypted',
            'termos_aceitos_em' => 'datetime',
            'prazo_pagamento' => 'datetime',
            'confirmada_em' => 'datetime',
            'expirada_em' => 'datetime',
            'cancelada_em' => 'datetime',
        ];
    }
}
