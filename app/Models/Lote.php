<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um degrau de preco do evento: o lote de inscricao.
 *
 * Cada lote vale ate uma data, ate acabarem as vagas dele, ou ate o que vier
 * primeiro — e o banco cobra que pelo menos um dos dois limites exista
 * (RN-L1). Lote que nao encerra nao e lote: e o preco do evento.
 *
 * NAO EXISTE COLUNA "ATIVO" AQUI, e a falta e proposital (RN-L3). Se a
 * situacao fosse gravada, ela envelheceria no primeiro minuto em que ninguem
 * rodasse a rotina que a atualizaria — e o sistema venderia pelo preco errado
 * sem que nada tivesse quebrado. A situacao e derivada da data e do contador a
 * cada leitura, e por isso e sempre verdadeira.
 *
 * A coluna vagas_ocupadas e um contador mantido por comando atomico (ver
 * App\Actions\Inscricoes\ReservarVagas). Nunca deve ser alterada com leitura
 * seguida de gravacao — e nunca desce (RN-L6).
 */
class Lote extends Model
{
    /** @use HasFactory<LoteFactory> */
    use HasFactory;

    protected $table = 'lotes';

    /**
     * O contador fica de fora de proposito: quem o move e o UPDATE condicional
     * da Action, nunca uma atribuicao em massa vinda de um formulario.
     *
     * @var list<string>
     */
    protected $fillable = [
        'evento_id',
        'nome',
        'posicao',
        'valor_centavos',
        'disponivel_ate',
        'quantidade',
    ];

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    /**
     * As inscricoes que vieram deste lote, em qualquer situacao.
     *
     * A tela administrativa usa isto para saber, antes de aceitar uma exclusao,
     * se ha gente que entrou por aqui (RN-L12).
     *
     * @return HasMany<Inscricao, $this>
     */
    public function inscricoes(): HasMany
    {
        return $this->hasMany(Inscricao::class);
    }

    /**
     * A ordem da sucessao: e a posicao que decide qual lote sucede qual.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeEmOrdem(Builder $query): void
    {
        $query->orderBy('posicao')->orderBy('id');
    }

    /**
     * Este lote ainda pode receber inscricao?
     *
     * A UNICA definicao de "disponivel" do sistema em PHP. A mesma condicao
     * aparece escrita em SQL uma segunda vez, dentro do UPDATE condicional de
     * ReservarVagas — e ali ela nao e copia, e a trava: e o banco quem decide,
     * no instante da gravacao, se ainda havia vaga.
     */
    public function estaDisponivel(?Carbon $momento = null): bool
    {
        $momento ??= Carbon::now();

        $dentroDoPrazo = $this->disponivel_ate === null || $this->disponivel_ate > $momento;
        $temVaga = $this->quantidade === null || $this->vagas_ocupadas < $this->quantidade;

        return $dentroDoPrazo && $temVaga;
    }

    /**
     * Quantas vagas ainda cabem neste lote. Null quando ele so encerra por data.
     */
    public function vagasRestantes(): ?int
    {
        if ($this->quantidade === null) {
            return null;
        }

        return max(0, $this->quantidade - $this->vagas_ocupadas);
    }

    /**
     * A situacao deste lote na sucessao, em uma palavra.
     *
     * Precisa saber qual e o lote vigente porque "vigente" e "futuro" nao sao
     * propriedades de um lote sozinho: os dois estao disponiveis, e o que os
     * separa e a posicao de cada um na fila. Quem resolve a fila e
     * App\Actions\Inscricoes\ResolverLoteVigente — este metodo so escreve o
     * resultado.
     *
     * @return 'encerrado'|'vigente'|'futuro'
     */
    public function situacaoEm(?Carbon $momento = null, ?int $loteVigenteId = null): string
    {
        if (! $this->estaDisponivel($momento)) {
            return 'encerrado';
        }

        return (int) $this->getKey() === $loteVigenteId ? 'vigente' : 'futuro';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posicao' => 'integer',
            'valor_centavos' => 'integer',
            'disponivel_ate' => 'datetime',
            'quantidade' => 'integer',
            'vagas_ocupadas' => 'integer',
        ];
    }
}
