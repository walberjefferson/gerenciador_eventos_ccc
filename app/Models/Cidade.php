<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CidadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cidade do catalogo global de participantes — o "setor", no vocabulario que a
 * comunidade usa e que a tela mostra.
 *
 * **O setor nao guarda mais chave Pix nem responsavel.** Ate a RN-R2 ele
 * guardava quatro campos que descreviam uma pessoa — conta, chave, titular e
 * telefone —, e eles couberam enquanto o responsavel era um so. Com varios,
 * esses campos passaram para o cadastro proprio de `Responsavel`, e o que sobra
 * aqui e o vinculo: quem atende este setor.
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
     * As pessoas que atendem este setor (RN-R2).
     *
     * Sao elas que recebem o Pix de quem se inscreve por aqui e que conferem
     * os comprovantes deste setor — QUALQUER uma delas, e nao so a que foi
     * sorteada para uma cobranca (RN-R6). Responsavel ausente nao trava a fila.
     *
     * @return BelongsToMany<Responsavel, $this>
     */
    public function responsaveis(): BelongsToMany
    {
        return $this->belongsToMany(Responsavel::class, 'responsaveis_setores', 'cidade_id', 'responsavel_id')
            ->orderBy('responsaveis.nome');
    }

    /**
     * Este setor consegue receber pagamento?
     *
     * Sim quando existe ao menos um responsavel APTO vinculado a ele — ativo e
     * com chave (RN-R3). Um vinculo sozinho nao basta: responsavel sem chave
     * nao teria o que mostrar ao participante, e desativado nao entra em
     * sorteio nenhum. Um evento so pode ser gravado no modo "setor" quando todo
     * setor ativo responde sim aqui (RN-S4).
     *
     * Quando a relacao ja veio carregada a resposta sai da memoria: a lista de
     * setores da tela de catalogo pergunta isto uma vez por linha, e uma
     * consulta por linha seria o mesmo trabalho feito N vezes.
     */
    public function estaPreparadaParaReceber(): bool
    {
        if ($this->relationLoaded('responsaveis')) {
            return $this->responsaveis
                ->contains(fn (Responsavel $responsavel): bool => $responsavel->estaApto());
        }

        return $this->responsaveis()->aptos()->exists();
    }

    /**
     * Os setores ativos que ainda nao conseguem receber, pelo nome.
     *
     * A lista existe para a mensagem de recusa NOMEAR quem falta: "ajuste os
     * setores" manda a pessoa procurar; "faltam Setor Norte e Setor Sul" manda
     * ela resolver.
     *
     * @return array<int, string>
     */
    public static function ativasDespreparadasParaReceber(): array
    {
        return static::query()
            ->ativos()
            ->whereDoesntHave(
                'responsaveis',
                fn (Builder $consulta) => $consulta->aptos(),
            )
            ->orderBy('nome')
            ->pluck('nome')
            ->map(fn (mixed $nome): string => (string) $nome)
            ->all();
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
