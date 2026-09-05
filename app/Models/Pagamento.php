<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoPagamento;
use App\Enums\SituacaoPagamento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cobranca de uma inscricao.
 *
 * O pagamento nunca e apagado: toda mudanca e de situacao, com o momento
 * gravado em coluna propria (pago_em, cancelado_em, estornado_em).
 */
class Pagamento extends Model
{
    protected $table = 'pagamentos';

    protected $fillable = [
        'codigo_publico',
        'inscricao_id',
        'responsavel_id',
        'gateway',
        'id_externo',
        'metodo',
        'valor_centavos',
        'situacao',
        'pix_copia_e_cola',
        'expira_em',
        'pago_em',
        'cancelado_em',
        'estornado_em',
        'valor_estornado_centavos',
        'metadados',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pagamento): void {
            $pagamento->codigo_publico ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<Inscricao, $this>
     */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /**
     * Quem foi sorteado para receber ESTA cobranca (RN-R4).
     *
     * Nula em toda cobranca do modo gateway, que nao sorteia ninguem. A coluna
     * mora aqui, e nao na inscricao, porque o sorteio se repete a cada cobranca
     * emitida (RN-R5): o escolhido pertence aquela cobranca, nao a pessoa que
     * se inscreveu. E o que faz uma cobranca vencida continuar dizendo para
     * quem ela apontava, em vez de ter o passado reescrito pela cobranca nova.
     *
     * @return BelongsTo<Responsavel, $this>
     */
    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Responsavel::class, 'responsavel_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePendentes(Builder $query): void
    {
        $query->where('situacao', SituacaoPagamento::Pendente->value);
    }

    /**
     * Cobrancas ainda pendentes cujo prazo ja passou.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVencidos(Builder $query, ?Carbon $momento = null): void
    {
        $query->pendentes()
            ->whereNotNull('expira_em')
            ->where('expira_em', '<', $momento ?? Carbon::now());
    }

    public function estaAberto(): bool
    {
        return $this->situacao->estaAberta();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metodo' => MetodoPagamento::class,
            'situacao' => SituacaoPagamento::class,
            'valor_centavos' => 'integer',
            'valor_estornado_centavos' => 'integer',
            'metadados' => 'array',
            'expira_em' => 'datetime',
            'pago_em' => 'datetime',
            'cancelado_em' => 'datetime',
            'estornado_em' => 'datetime',
        ];
    }
}
