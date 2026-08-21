<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Atividade;
use App\Models\GrupoAtividade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que precisa ser verdade para uma atividade ser gravada.
 *
 * Espelha as restricoes do banco (horario que termina depois de comecar,
 * capacidade que nao fica abaixo do ocupado) e acrescenta as travas de bom
 * senso: idade minima nao passa da maxima, e atividade que ja tem gente
 * escolhida nao muda de grupo.
 */
class AtividadeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grupo_atividade_id' => ['required', 'integer', Rule::exists('grupos_atividades', 'id')],
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'descricao' => ['nullable', 'string'],
            'comeca_em' => ['required', 'date'],
            // Espelha atividades_horario_check.
            'termina_em' => ['required', 'date', 'after:comeca_em'],
            'capacidade' => ['nullable', 'integer', 'min:0'],
            'idade_minima' => ['nullable', 'integer', 'min:0', 'max:120'],
            'idade_maxima' => ['nullable', 'integer', 'min:0', 'max:120', 'gte:idade_minima'],
            'posicao' => ['required', 'integer', 'min:1', 'max:32767'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador): void {
                $atividade = $this->route('atividade');

                if (! $atividade instanceof Atividade) {
                    return;
                }

                $this->recusarCapacidadeAbaixoDoOcupado($validador, $atividade);
                $this->recusarTrocaDeEvento($validador, $atividade);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grupo_atividade_id.required' => 'Escolha o grupo a que esta atividade pertence.',
            'nome.required' => 'Informe o nome da atividade.',
            'comeca_em.required' => 'Informe a hora de início.',
            'termina_em.after' => 'A atividade precisa terminar depois de começar.',
            'capacidade.min' => 'A capacidade não pode ser negativa. Deixe em branco para atividade sem limite.',
            'idade_maxima.gte' => 'A idade máxima não pode ser menor que a mínima.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'grupo_atividade_id' => 'grupo',
            'comeca_em' => 'hora de início',
            'termina_em' => 'hora de término',
            'idade_minima' => 'idade mínima',
            'idade_maxima' => 'idade máxima',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDaAtividade(): array
    {
        return [
            'grupo_atividade_id' => $this->integer('grupo_atividade_id'),
            'nome' => trim((string) $this->string('nome')),
            'descricao' => $this->input('descricao'),
            'comeca_em' => $this->date('comeca_em'),
            'termina_em' => $this->date('termina_em'),
            'capacidade' => $this->input('capacidade') === null ? null : $this->integer('capacidade'),
            'idade_minima' => $this->input('idade_minima') === null ? null : $this->integer('idade_minima'),
            'idade_maxima' => $this->input('idade_maxima') === null ? null : $this->integer('idade_maxima'),
            'posicao' => $this->integer('posicao'),
            'ativo' => $this->boolean('ativo', true),
        ];
    }

    /**
     * Espelha atividades_capacidade_check: reduzir abaixo do ocupado tiraria
     * vaga de quem ja escolheu esta atividade.
     */
    private function recusarCapacidadeAbaixoDoOcupado(Validator $validador, Atividade $atividade): void
    {
        $nova = $this->input('capacidade');

        if ($nova === null) {
            return;
        }

        $ocupadas = $atividade->vagasOcupadas();

        if ((int) $nova < $ocupadas) {
            $validador->errors()->add(
                'capacidade',
                "Esta atividade já tem {$ocupadas} vaga(s) ocupada(s) entre reservas e confirmações. "
                .'A capacidade não pode ficar abaixo disso: cancele inscrições antes de reduzir.'
            );
        }
    }

    /**
     * A atividade pode trocar de grupo dentro do mesmo evento, mas nunca pular
     * para outro evento: as escolhas ja feitas apontam para ela.
     */
    private function recusarTrocaDeEvento(Validator $validador, Atividade $atividade): void
    {
        $destino = GrupoAtividade::query()->with('diaEvento')->find($this->integer('grupo_atividade_id'));
        $origem = $atividade->grupoAtividade()->with('diaEvento')->first();

        if ($destino === null || $origem === null) {
            return;
        }

        if ($destino->diaEvento?->evento_id !== $origem->diaEvento?->evento_id) {
            $validador->errors()->add(
                'grupo_atividade_id',
                'Uma atividade não pode ser movida para outro evento.'
            );
        }
    }
}
