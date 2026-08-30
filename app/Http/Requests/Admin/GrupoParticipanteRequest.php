<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\GrupoParticipante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que precisa ser verdade para um grupo de participantes ser gravado.
 *
 * Espelha as restricoes do banco: o grupo pertence a um setor e o nome nao se
 * repete dentro dele. O campo continua se chamando `cidade_id`, como a coluna —
 * o renome para "setor" vale para o que a pessoa le, nao para o contrato.
 */
class GrupoParticipanteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $grupo = $this->route('grupo_participante');
        $id = $grupo instanceof GrupoParticipante ? $grupo->getKey() : null;

        return [
            'cidade_id' => ['required', 'integer', Rule::exists('cidades', 'id')],
            'nome' => [
                'required', 'string', 'min:2', 'max:120',
                // O nome do grupo nao se repete dentro do mesmo setor.
                Rule::unique('grupos_participantes', 'nome')
                    ->where(fn ($consulta) => $consulta->where('cidade_id', $this->integer('cidade_id')))
                    ->ignore($id),
            ],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cidade_id' => 'setor',
            'nome' => 'nome do grupo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cidade_id.required' => 'Escolha o setor do grupo.',
            'cidade_id.exists' => 'Esse setor não está no catálogo.',
            'nome.required' => 'Informe o nome do grupo.',
            'nome.max' => 'O nome do grupo pode ter no máximo 120 caracteres.',
            'nome.unique' => 'Já existe um grupo com esse nome neste setor.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $nome = is_string($this->input('nome')) ? trim($this->input('nome')) : null;

        $this->merge(['nome' => $nome ?? $this->input('nome')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDoGrupo(): array
    {
        return [
            'cidade_id' => $this->integer('cidade_id'),
            'nome' => (string) $this->string('nome'),
            'ativo' => $this->boolean('ativo', true),
        ];
    }
}
