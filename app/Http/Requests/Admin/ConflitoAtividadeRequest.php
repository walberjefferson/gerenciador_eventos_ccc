<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Atividade;
use App\Models\ConflitoAtividade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que precisa ser verdade para um conflito entre atividades ser gravado.
 *
 * Conflito e um par de atividades que ninguem pode escolher junto. O par e
 * sempre normalizado — o menor identificador primeiro — porque senao (3, 7) e
 * (7, 3) seriam duas linhas para o mesmo conflito e a unicidade nao protegeria
 * nada. O banco cobra isso; aqui a recusa chega em portugues antes.
 */
class ConflitoAtividadeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'atividade_a_id' => ['required', 'integer', 'different:atividade_b_id', Rule::exists('atividades', 'id')],
            'atividade_b_id' => ['required', 'integer', Rule::exists('atividades', 'id')],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador): void {
                [$a, $b] = $this->parNormalizado();

                if ($a === $b) {
                    // "different" ja pega este caso; a mensagem daqui e a que
                    // explica o porque.
                    return;
                }

                $primeira = Atividade::query()->with('grupoAtividade.diaEvento')->find($a);
                $segunda = Atividade::query()->with('grupoAtividade.diaEvento')->find($b);

                if ($primeira === null || $segunda === null) {
                    return;
                }

                $eventoA = $primeira->grupoAtividade?->diaEvento?->evento_id;
                $eventoB = $segunda->grupoAtividade?->diaEvento?->evento_id;

                if ($eventoA !== $eventoB) {
                    $validador->errors()->add(
                        'atividade_b_id',
                        'As duas atividades precisam ser do mesmo evento.'
                    );

                    return;
                }

                $jaExiste = ConflitoAtividade::query()
                    ->where('atividade_a_id', $a)
                    ->where('atividade_b_id', $b)
                    ->exists();

                if ($jaExiste) {
                    $validador->errors()->add(
                        'atividade_b_id',
                        'Este conflito já está cadastrado. A ordem das duas atividades não importa: o par é o mesmo.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'atividade_a_id.required' => 'Escolha a primeira atividade.',
            'atividade_b_id.required' => 'Escolha a segunda atividade.',
            'atividade_a_id.different' => 'Uma atividade não conflita consigo mesma. Escolha duas atividades diferentes.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'atividade_a_id' => 'primeira atividade',
            'atividade_b_id' => 'segunda atividade',
        ];
    }

    /**
     * O par na ordem que o banco exige: menor identificador primeiro.
     *
     * @return array{0: int, 1: int}
     */
    public function parNormalizado(): array
    {
        return ConflitoAtividade::normalizarPar(
            $this->integer('atividade_a_id'),
            $this->integer('atividade_b_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDoConflito(): array
    {
        [$a, $b] = $this->parNormalizado();

        return [
            'atividade_a_id' => $a,
            'atividade_b_id' => $b,
            'motivo' => $this->input('motivo'),
        ];
    }
}
