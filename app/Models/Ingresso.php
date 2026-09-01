<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SituacaoIngresso;
use App\Enums\SituacaoInscricao;
use App\Services\Ingressos\GeradorDeCodigo;
use Database\Factories\IngressoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O ingresso de uma inscricao confirmada: o que a pessoa apresenta na entrada.
 *
 * Vive em tabela propria, e nao em colunas de "inscricoes", porque tem ciclo
 * de vida proprio — nasce, e usado, pode ser desfeito — e a inscricao e a
 * tabela mais quente do sistema: ela nao precisa crescer por um motivo que
 * nao e dela.
 *
 * O ingresso nao e apagado nunca. Desfazer uma entrada registrada por engano
 * limpa "usado_em" e "usado_por"; o que aconteceu fica na trilha de auditoria.
 */
class Ingresso extends Model
{
    /** @use HasFactory<IngressoFactory> */
    use HasFactory;

    protected $table = 'ingressos';

    protected $fillable = [
        'inscricao_id',
        'codigo',
        'emitido_em',
        'usado_em',
        'usado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emitido_em' => 'datetime',
            'usado_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Inscricao, $this>
     */
    public function inscricao(): BelongsTo
    {
        return $this->belongsTo(Inscricao::class);
    }

    /**
     * Quem registrou a entrada. Fica nulo enquanto ninguem entrou — e volta a
     * ficar quando a entrada e desfeita.
     *
     * @return BelongsTo<User, $this>
     */
    public function usadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usado_por');
    }

    /**
     * Alguem ja entrou com este ingresso?
     */
    public function estaUsado(): bool
    {
        return $this->usado_em !== null;
    }

    /**
     * A situacao que a tela mostra.
     *
     * Nao ha coluna: a resposta e sempre calculada a partir do estado de agora
     * — se a inscricao continua confirmada e se alguem ja entrou. Guardar isso
     * numa coluna criaria uma segunda fonte da verdade, que um dia discordaria
     * da primeira (o cancelamento de uma inscricao paga nao passa por aqui).
     */
    public function situacao(): SituacaoIngresso
    {
        $inscricao = $this->relationLoaded('inscricao') ? $this->inscricao : $this->inscricao()->first();

        if ($inscricao?->situacao !== SituacaoInscricao::Confirmada) {
            return SituacaoIngresso::Invalido;
        }

        return $this->estaUsado() ? SituacaoIngresso::Usado : SituacaoIngresso::Emitido;
    }

    /**
     * O codigo em grupos de quatro, para quem le e digita. O banco continua
     * guardando sem hifen.
     */
    public function codigoFormatado(): string
    {
        return GeradorDeCodigo::formatar((string) $this->codigo);
    }
}
