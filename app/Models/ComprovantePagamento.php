<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SituacaoComprovante;
use Database\Factories\ComprovantePagamentoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O comprovante que o participante enviou dizendo ter pago.
 *
 * Ele e a PROVA de uma afirmacao, nao a afirmacao em si: existir um
 * comprovante nao significa que o dinheiro entrou, e por isso este model nao
 * mexe em situacao de inscricao nenhuma (RN-S7). Quem transforma "a pessoa
 * disse que pagou" em "o dinheiro entrou" e uma pessoa, olhando o arquivo, e o
 * caminho que ela usa e o mesmo ConfirmarPagamentoManual de sempre (RN-S10).
 *
 * Duas coisas que valem para todo registro daqui:
 *
 * 1. **O arquivo mora em disco privado.** A coluna `caminho` e um caminho
 *    dentro do disco "comprovantes", que nao tem URL. Servir o arquivo e
 *    trabalho de uma rota autenticada, com escopo de setor (RN-S11).
 * 2. **O nome que a pessoa deu ao arquivo fica na coluna, nunca no caminho.**
 *    Nome vindo de terceiro nao entra em nome de arquivo do servidor; ele volta
 *    para a tela apenas como texto.
 *
 * @property int $id
 * @property int $inscricao_id
 * @property string $caminho
 * @property string $nome_original
 * @property string $mime
 * @property int $tamanho_bytes
 * @property SituacaoComprovante $situacao
 * @property Carbon $enviado_em
 * @property int|null $conferido_por_id
 * @property Carbon|null $conferido_em
 * @property string|null $motivo_recusa
 */
class ComprovantePagamento extends Model
{
    /** @use HasFactory<ComprovantePagamentoFactory> */
    use HasFactory;

    protected $table = 'comprovantes_pagamento';

    protected $fillable = [
        'inscricao_id',
        'caminho',
        'nome_original',
        'mime',
        'tamanho_bytes',
        'situacao',
        'enviado_em',
        'conferido_por_id',
        'conferido_em',
        'motivo_recusa',
    ];

    /**
     * O caminho no disco nao volta para a tela em lugar nenhum. Ele nao e
     * segredo — o disco e privado e nao tem URL —, mas expo-lo so ensinaria a
     * estrutura de pastas do servidor a quem nao precisa dela.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'caminho',
    ];

    /**
     * @return BelongsTo<Inscricao, $this>
     */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function conferidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conferido_por_id');
    }

    /**
     * Os comprovantes que ainda esperam alguem olhar.
     *
     * Por inscricao existe no maximo um deles, e quem garante isso e o indice
     * unico parcial do banco, nao esta consulta.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeEmAberto(Builder $query): void
    {
        $query->where('situacao', SituacaoComprovante::Enviado->value);
    }

    public function estaEmAberto(): bool
    {
        return $this->situacao->estaEmAberto();
    }

    /**
     * O retrato que vai para a tela — do participante ou de quem confere.
     *
     * @return array<string, mixed>
     */
    public function paraTela(): array
    {
        return [
            'id' => (int) $this->id,
            'nome_original' => $this->nome_original,
            'mime' => $this->mime,
            'tamanho_bytes' => (int) $this->tamanho_bytes,
            'situacao' => $this->situacao->value,
            'situacao_rotulo' => $this->situacao->rotulo(),
            'enviado_em' => $this->enviado_em?->toIso8601String(),
            'conferido_em' => $this->conferido_em?->toIso8601String(),
            'motivo_recusa' => $this->motivo_recusa,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'situacao' => SituacaoComprovante::class,
            'tamanho_bytes' => 'integer',
            'enviado_em' => 'datetime',
            'conferido_em' => 'datetime',
        ];
    }
}
