<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Cidade;
use Illuminate\Database\Query\Builder as ConsultaCrua;
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
            // Quem responde pelo setor. Precisa ser uma conta ATIVA: apontar o
            // setor para alguem que nao consegue mais entrar seria o mesmo que
            // deixa-lo sem responsavel, com a diferenca de ninguem perceber.
            'responsavel_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(
                    fn (ConsultaCrua $consulta) => $consulta->where('ativo', true)
                ),
            ],
            // A chave Pix e guardada em claro de proposito (RN-S3): ela existe
            // para ser mostrada ao participante daquele setor. O formato nao e
            // validado aqui — chave Pix pode ser CPF, CNPJ, e-mail, telefone ou
            // uma chave aleatoria, e um regex que tentasse cobrir os cinco
            // recusaria alguma chave legitima antes de recusar alguma errada.
            // Quem confere de verdade e o aplicativo do banco de quem paga.
            'chave_pix' => ['nullable', 'string', 'max:140'],
            'titular_chave_pix' => ['nullable', 'string', 'max:120'],
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
            'responsavel_id' => 'responsável pelo setor',
            'chave_pix' => 'chave Pix',
            'titular_chave_pix' => 'titular da chave Pix',
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
            'responsavel_id.exists' => 'Escolha uma conta ativa do painel para responder pelo setor.',
            'chave_pix.max' => 'A chave Pix pode ter no máximo 140 caracteres.',
            'titular_chave_pix.max' => 'O nome do titular pode ter no máximo 120 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => is_string($this->input('nome')) ? trim($this->input('nome')) : $this->input('nome'),
            'uf' => $this->uf(),
            // Chave com espaço sobrando na ponta é chave errada: ela seria
            // copiada com o espaço junto e recusada pelo banco de quem paga.
            'chave_pix' => $this->texto('chave_pix'),
            'titular_chave_pix' => $this->texto('titular_chave_pix'),
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
            'responsavel_id' => $this->input('responsavel_id') === null
                ? null
                : $this->integer('responsavel_id'),
            'chave_pix' => $this->texto('chave_pix'),
            'titular_chave_pix' => $this->texto('titular_chave_pix'),
        ];
    }

    /**
     * O campo recortado, ou nulo quando so sobrou espaco.
     *
     * Guardar texto vazio faria Cidade::estaPreparadaParaReceber() responder
     * "sim" para um setor sem chave nenhuma.
     */
    private function texto(string $campo): ?string
    {
        $valor = $this->input($campo);
        $valor = is_scalar($valor) ? trim((string) $valor) : '';

        return $valor === '' ? null : $valor;
    }

    private function uf(): string
    {
        return mb_strtoupper(trim((string) $this->input('uf')));
    }
}
