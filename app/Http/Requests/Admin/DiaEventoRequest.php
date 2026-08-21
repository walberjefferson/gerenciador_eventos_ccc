<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\DiaEvento;
use App\Models\Evento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que precisa ser verdade para um dia de evento ser gravado.
 *
 * Espelha as restricoes do banco: a posicao nao se repete dentro do evento. E
 * acrescenta uma verificacao de bom senso que o banco nao faz: o dia precisa
 * cair dentro do periodo do evento, senao a programacao aponta para uma data
 * em que nao ha evento nenhum.
 */
class DiaEventoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dia = $this->route('dia_evento');
        $id = $dia instanceof DiaEvento ? $dia->getKey() : null;

        return [
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'descricao' => ['nullable', 'string'],
            'data' => ['required', 'date'],
            'posicao' => [
                'required', 'integer', 'min:1', 'max:32767',
                Rule::unique('dias_evento', 'posicao')
                    ->where(fn ($consulta) => $consulta->where('evento_id', $this->evento()->getKey()))
                    ->ignore($id),
            ],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador): void {
                $evento = $this->evento();
                $data = $this->date('data');

                if ($data === null) {
                    return;
                }

                if ($data->lt($evento->data_inicio) || $data->gt($evento->data_fim)) {
                    $validador->errors()->add(
                        'data',
                        'A data do dia precisa cair dentro do período do evento ('
                        .$evento->data_inicio->format('d/m/Y').' a '.$evento->data_fim->format('d/m/Y').').'
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
            'nome.required' => 'Informe o nome do dia. Exemplo: Sábado.',
            'data.required' => 'Informe a data deste dia.',
            'posicao.unique' => 'Já existe um dia nesta posição. Cada dia ocupa uma posição diferente na programação.',
            'posicao.min' => 'A posição começa em 1.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDoDia(): array
    {
        return [
            'evento_id' => $this->evento()->getKey(),
            'nome' => trim((string) $this->string('nome')),
            'descricao' => $this->input('descricao'),
            'data' => $this->date('data'),
            'posicao' => $this->integer('posicao'),
            'ativo' => $this->boolean('ativo', true),
        ];
    }

    private function evento(): Evento
    {
        $evento = $this->route('evento');

        if ($evento instanceof Evento) {
            return $evento;
        }

        $dia = $this->route('dia_evento');

        return $dia instanceof DiaEvento ? $dia->evento : abort(404);
    }
}
