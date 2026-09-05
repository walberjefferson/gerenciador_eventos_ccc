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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'lote_id',
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
     * O lote de onde esta inscricao veio.
     *
     * Nulo quando o evento nao trabalha com lotes. Serve a relatorio e a
     * conferencia — nunca a calculo de cobranca: quanto esta pessoa deve
     * continua sendo valor_centavos, fotografado no instante da inscricao.
     *
     * @return BelongsTo<Lote, $this>
     */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
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
     * O ingresso desta inscricao, quando ela ja foi confirmada.
     *
     * E sempre um so — a unicidade de "inscricao_id" na tabela "ingressos"
     * garante isso no banco. Fica nulo enquanto o pagamento nao e reconhecido:
     * quem nao pagou nao tem o que apresentar na entrada.
     *
     * @return HasOne<Ingresso, $this>
     */
    public function ingresso(): HasOne
    {
        return $this->hasOne(Ingresso::class);
    }

    /**
     * Cobrancas emitidas para esta inscricao. Normalmente e uma so; podem ser
     * mais se a primeira vencer e outra for emitida no lugar.
     *
     * @return HasMany<Pagamento, $this>
     */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(Pagamento::class);
    }

    /**
     * A cobranca que ainda pode ser paga, se existir.
     */
    public function pagamentoPendente(): ?Pagamento
    {
        return $this->pagamentos()->pendentes()->orderByDesc('id')->first();
    }

    /**
     * Os comprovantes que esta pessoa mandou, do mais recente para o mais
     * antigo.
     *
     * Costuma ser um so. Sao varios quando um comprovante foi recusado e ela
     * mandou outro: a recusa fica no historico, porque apagar a tentativa
     * apagaria tambem o motivo pelo qual ela nao valeu.
     *
     * @return HasMany<ComprovantePagamento, $this>
     */
    public function comprovantes(): HasMany
    {
        return $this->hasMany(ComprovantePagamento::class)->orderByDesc('id');
    }

    /**
     * O comprovante que ainda espera conferencia, se houver.
     *
     * E no maximo um — o indice unico parcial do banco garante isso (RN-S6).
     */
    public function comprovanteEmAberto(): ?ComprovantePagamento
    {
        return $this->comprovantes()->emAberto()->first();
    }

    /**
     * O comprovante mais recente, em qualquer situacao.
     *
     * E o que a tela do participante mostra: aceito, recusado com o motivo, ou
     * esperando. Nada disso e situacao de inscricao (RN-S7).
     */
    public function comprovanteMaisRecente(): ?ComprovantePagamento
    {
        return $this->comprovantes()->first();
    }

    /**
     * O setor desta inscricao.
     *
     * Ele nao esta na inscricao: vem pelo grupo de participantes, que foi o que
     * a pessoa escolheu no formulario. E o mesmo caminho que o filtro da lista
     * administrativa percorre — e o mesmo pelo qual o escopo de quem confere
     * comprovante e aplicado (RN-S9).
     */
    public function setor(): ?Cidade
    {
        $grupo = $this->relationLoaded('grupoParticipante')
            ? $this->grupoParticipante
            : $this->grupoParticipante()->first();

        if (! $grupo instanceof GrupoParticipante) {
            return null;
        }

        return $grupo->relationLoaded('cidade')
            ? $grupo->cidade
            : $grupo->cidade()->first();
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
