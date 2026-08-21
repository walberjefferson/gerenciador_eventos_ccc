<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cidade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que precisa ser verdade para uma cidade ser gravada.
 *
 * Cada regra daqui espelha uma restricao do banco (ver docs/DATABASE.md), para
 * que quem esta na tela receba uma frase em portugues antes de o PostgreSQL
 * recusar com a mensagem dele, que ninguem entende.
 */
class CidadeRequest extends FormRequest
{
    /** As 26 unidades da federacao mais o Distrito Federal. */
    public const UFS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT',
        'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cidade = $this->route('cidade');
        $id = $cidade instanceof Cidade ? $cidade->getKey() : null;

        return [
            // O banco guarda no maximo 120 caracteres.
            'nome' => [
                'required', 'string', 'min:2', 'max:120',
                // A unicidade e do par nome + UF: existe Franca em SP e pode
                // existir cidade de mesmo nome em outro estado.
                Rule::unique('cidades', 'nome')
                    ->where(fn ($consulta) => $consulta->where('uf', $this->uf()))
                    ->ignore($id),
            ],
            'uf' => ['required', 'string', 'size:2', Rule::in(self::UFS)],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome da cidade',
            'uf' => 'estado',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome da cidade.',
            'nome.max' => 'O nome da cidade pode ter no máximo 120 caracteres.',
            'uf.required' => 'Escolha o estado.',
            'uf.in' => 'Escolha um estado válido, com as duas letras da sigla.',
            'nome.unique' => 'Já existe uma cidade com esse nome neste estado.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => is_string($this->input('nome')) ? trim($this->input('nome')) : $this->input('nome'),
            'uf' => $this->uf(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDaCidade(): array
    {
        return [
            'nome' => (string) $this->string('nome'),
            'uf' => $this->uf(),
            'ativo' => $this->boolean('ativo', true),
        ];
    }

    private function uf(): string
    {
        return mb_strtoupper(trim((string) $this->input('uf')));
    }
}
