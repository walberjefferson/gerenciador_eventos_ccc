<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\DiaEvento;
use App\Models\GrupoAtividade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que precisa ser verdade para um grupo de atividades ser gravado.
 *
 * Espelha as tres restricoes que o banco cobra: minimo nao negativo, maximo
 * nunca menor que o minimo, e grupo obrigatorio com minimo de pelo menos uma
 * escolha — porque grupo obrigatorio que aceita zero escolhas nao obriga nada.
 */
class GrupoAtividadeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dia_evento_id' => [
                'required', 'integer',
                Rule::exists('dias_evento', 'id'),
            ],
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'descricao' => ['nullable', 'string'],
            'obrigatorio' => ['sometimes', 'boolean'],
            // Espelha grupos_atividades_min_check.
            'min_selecoes' => ['required', 'integer', 'min:0', 'max:32767'],
            // Espelha grupos_atividades_max_check.
            'max_selecoes' => ['nullable', 'integer', 'min:0', 'max:32767', 'gte:min_selecoes'],
            'posicao' => ['required', 'integer', 'min:1', 'max:32767'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador): void {
                // Espelha grupos_atividades_obrigatorio_check.
                if ($this->boolean('obrigatorio') && $this->integer('min_selecoes') < 1) {
                    $validador->errors()->add(
                        'min_selecoes',
                        'Grupo obrigatório precisa pedir ao menos uma escolha. '
                        .'Se ninguém precisa escolher nada, desmarque "obrigatório".'
                    );
                }

                $dia = DiaEvento::query()->find($this->integer('dia_evento_id'));
                $grupo = $this->route('grupo_atividade');

                if ($dia === null) {
                    return;
                }

                // O grupo nao pode migrar para o dia de outro evento: as
                // atividades dele ja estao ligadas a inscricoes deste.
                if ($grupo instanceof GrupoAtividade && $grupo->diaEvento?->evento_id !== $dia->evento_id) {
                    $validador->errors()->add(
                        'dia_evento_id',
                        'Um grupo não pode ser movido para o dia de outro evento.'
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
            'dia_evento_id.required' => 'Escolha o dia a que este grupo pertence.',
            'nome.required' => 'Informe o nome do grupo. Exemplo: Modalidade esportiva.',
            'min_selecoes.min' => 'O mínimo de escolhas não pode ser negativo.',
            'max_selecoes.gte' => 'O máximo de escolhas não pode ser menor que o mínimo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'dia_evento_id' => 'dia',
            'min_selecoes' => 'mínimo de escolhas',
            'max_selecoes' => 'máximo de escolhas',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDoGrupo(): array
    {
        return [
            'dia_evento_id' => $this->integer('dia_evento_id'),
            'nome' => trim((string) $this->string('nome')),
            'descricao' => $this->input('descricao'),
            'obrigatorio' => $this->boolean('obrigatorio'),
            'min_selecoes' => $this->integer('min_selecoes'),
            'max_selecoes' => $this->input('max_selecoes') === null ? null : $this->integer('max_selecoes'),
            'posicao' => $this->integer('posicao'),
            'ativo' => $this->boolean('ativo', true),
        ];
    }
}
