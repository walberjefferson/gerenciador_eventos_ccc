<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cidade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que precisa ser verdade para um setor ser gravado.
 *
 * A classe, a tabela e o Model continuam se chamando "cidade": o renome para
 * "setor" vale para o que a pessoa le, nao para o banco.
 *
 * Cada regra daqui espelha uma restricao do banco (ver docs/DATABASE.md), para
 * que quem esta na tela receba uma frase em portugues antes de o PostgreSQL
 * recusar com a mensagem dele, que ninguem entende.
 *
 * O CAMPO UF CONTINUA NA TELA, mesmo tendo sumido do rotulo publico: a coluna
 * e obrigatoria e entra na chave unica (`nome`, `uf`). Escondido, o cadastro de
 * um setor novo falharia sem explicar por que.
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
        // O parametro da rota se chama `setor`; o que ele resolve continua
        // sendo um Model `Cidade`.
        $setor = $this->route('setor');
        $id = $setor instanceof Cidade ? $setor->getKey() : null;

        return [
            // O banco guarda no maximo 120 caracteres.
            'nome' => [
                'required', 'string', 'min:2', 'max:120',
                // A unicidade e do par nome + UF, como o banco a declara.
                Rule::unique('cidades', 'nome')
                    ->where(fn ($consulta) => $consulta->where('uf', $this->uf()))
                    ->ignore($id),
            ],
            'uf' => ['required', 'string', 'size:2', Rule::in(self::UFS)],
            'ativo' => ['sometimes', 'boolean'],
            // Quem atende este setor (RN-R2). O setor nao guarda mais chave,
            // titular nem telefone: esses campos descrevem uma PESSOA, e agora
            // moram no cadastro dela. O que sobra aqui e o vinculo.
            //
            // Lista vazia e permitida: cadastrar o setor antes de saber quem
            // vai atende-lo e o gesto normal de quem esta montando o catalogo.
            // Quem cobra a presenca de um responsavel apto e o formulario do
            // evento no modo setor (RN-S4), e la a recusa nomeia quem falta.
            'responsaveis' => ['sometimes', 'array'],
            'responsaveis.*' => ['integer', Rule::exists('responsaveis', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome do setor',
            'uf' => 'estado',
            'responsaveis' => 'responsáveis do setor',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome do setor.',
            'nome.max' => 'O nome do setor pode ter no máximo 120 caracteres.',
            'uf.required' => 'Escolha o estado.',
            'uf.in' => 'Escolha um estado válido, com as duas letras da sigla.',
            'nome.unique' => 'Já existe um setor com esse nome neste estado.',
            'responsaveis.*.exists' => 'Escolha um responsável que exista no cadastro.',
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

    /**
     * Os responsaveis escolhidos, prontos para o sync do vinculo.
     *
     * @return array<int, int>
     */
    public function responsaveis(): array
    {
        /** @var array<int, mixed> $escolhidos */
        $escolhidos = $this->input('responsaveis', []);

        return array_values(array_unique(array_map(
            fn (mixed $id): int => (int) $id,
            is_array($escolhidos) ? $escolhidos : [],
        )));
    }

    private function uf(): string
    {
        return mb_strtoupper(trim((string) $this->input('uf')));
    }
}
