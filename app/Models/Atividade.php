<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AtividadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Atividade que o participante pode escolher dentro de um grupo de atividades.
 *
 * Assim como no evento, vagas_reservadas e vagas_confirmadas sao contadores
 * mantidos por comandos atomicos.
 *
 * O horário é opcional: quando ele falta, a atividade acontece no dia inteiro
 * do dia de programação a que pertence — veja data() e sobrepoe().
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
     * A atividade tem hora marcada?
     *
     * O horário é opcional EM PAR (ou os dois campos, ou nenhum): é assim que o
     * banco o guarda, em atividades_horario_check, e é assim que o formulário o
     * aceita. Por isso basta perguntar uma vez, e não campo a campo.
     */
    public function temHorario(): bool
    {
        return $this->comeca_em !== null && $this->termina_em !== null;
    }

    /**
     * O dia em que a atividade acontece.
     *
     * Com hora marcada, é o dia de `comeca_em`. Sem hora marcada, é a data do
     * dia da programação a que ela pertence — que é justamente o motivo de o
     * horário poder faltar: quem cadastra já disse "é no sábado" ao escolher o
     * grupo, e repetir isso em dois campos de data e hora não acrescenta nada.
     *
     * É esta data — e não `comeca_em` — que manda na idade (RN-08) e no choque
     * de dia inteiro (RN-06).
     */
    public function data(): Carbon
    {
        if ($this->comeca_em !== null) {
            return $this->comeca_em->copy()->startOfDay();
        }

        $this->loadMissing('grupoAtividade.diaEvento');

        $data = $this->grupoAtividade?->diaEvento?->data;

        if ($data === null) {
            throw new RuntimeException(
                "A atividade {$this->nome} não tem horário nem dia de programação: "
                .'sem um dos dois é impossível saber quando ela acontece.'
            );
        }

        return $data->copy()->startOfDay();
    }

    /**
     * Duas atividades se sobrepoem quando uma comeca antes de a outra terminar,
     * dos dois lados. Limites que apenas se encostam (uma termina exatamente
     * quando a outra comeca) NAO se sobrepoem.
     *
     * Atividade sem horário ocupa o DIA INTEIRO (RN-06): ninguém sabe a que
     * horas ela começa, então supor que sobra tempo para outra coisa no mesmo
     * dia seria adivinhar. Ela choca com qualquer atividade da mesma data — com
     * ou sem horário — e com nenhuma de outra data.
     */
    public function sobrepoe(self $outra): bool
    {
        if (! $this->temHorario() || ! $outra->temHorario()) {
            return $this->data()->isSameDay($outra->data());
        }

        return $this->comeca_em < $outra->termina_em
            && $this->termina_em > $outra->comeca_em;
    }

    /**
     * Idade que a pessoa tera no dia em que a atividade acontece.
     */
    public function idadeNaData(Carbon $dataNascimento): int
    {
        return (int) $dataNascimento->copy()->startOfDay()->diffInYears($this->data());
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
