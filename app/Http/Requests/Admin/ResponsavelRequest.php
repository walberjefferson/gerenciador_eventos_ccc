<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Responsavel;
use Illuminate\Database\Query\Builder as ConsultaCrua;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que precisa ser verdade para um responsavel ser gravado.
 *
 * Cada regra daqui espelha uma restricao do banco (ver docs/DATABASE.md), para
 * que quem esta na tela receba uma frase em portugues antes de o PostgreSQL
 * recusar com a mensagem dele, que ninguem entende.
 *
 * A CHAVE PIX E OBRIGATORIA, e a obrigatoriedade e a diferenca em relacao ao
 * cadastro antigo, onde ela morava no setor e podia ficar vazia. Aqui o
 * cadastro existe para dizer para onde o dinheiro vai: sem chave ele nao diz
 * nada, e o responsavel nasceria fora do sorteio no mesmo instante (RN-R9).
 */
class ResponsavelRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $responsavel = $this->route('responsavel');
        $id = $responsavel instanceof Responsavel ? $responsavel->getKey() : null;

        return [
            // O nome do TITULAR da chave — o que aparece no aplicativo de quem
            // paga. O banco guarda no maximo 120 caracteres.
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            // A chave e guardada em claro de proposito (RN-S3): ela existe para
            // ser mostrada ao participante. O formato nao e validado aqui —
            // chave Pix pode ser CPF, CNPJ, e-mail, telefone ou uma chave
            // aleatoria, e um regex que tentasse cobrir os cinco recusaria
            // alguma chave legitima antes de recusar alguma errada. Quem
            // confere de verdade e o aplicativo do banco de quem paga.
            'chave_pix' => ['required', 'string', 'max:140'],
            // O telefone e OPCIONAL de proposito: ele ajuda quem ficou com
            // duvida, mas nao impede ninguem de pagar. Por isso ele nao entra
            // em Responsavel::estaApto() e nao trava o cadastro de um evento
            // que recebe pelo setor (RN-S4).
            'telefone' => ['nullable', 'string', 'min:8', 'max:40'],
            // A conta do painel, QUANDO ELA EXISTE (RN-R1). Nula quer dizer
            // "recebe, mas nao confere" — o tesoureiro que nao usa o sistema.
            // Precisa ser conta ATIVA: apontar para alguem que nao consegue
            // mais entrar seria o mesmo que nao apontar para ninguem, com a
            // diferenca de ninguem perceber.
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(
                    fn (ConsultaCrua $consulta) => $consulta->where('ativo', true)
                ),
                // Uma conta corresponde a UM cadastro de responsavel — o unico
                // parcial do banco. A regra esta aqui para que a recusa venha
                // em portugues, e nao como erro de indice.
                Rule::unique('responsaveis', 'user_id')->ignore($id),
            ],
            'ativo' => ['sometimes', 'boolean'],
            // Os setores que esta pessoa atende (RN-R2). Lista vazia e
            // permitida: cadastrar a pessoa antes de decidir onde ela entra e
            // um gesto normal, e um responsavel sem setor simplesmente nunca e
            // sorteado.
            'setores' => ['sometimes', 'array'],
            'setores.*' => ['integer', Rule::exists('cidades', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome do responsável',
            'chave_pix' => 'chave Pix',
            'telefone' => 'telefone',
            'user_id' => 'conta do painel',
            'setores' => 'setores atendidos',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome de quem recebe.',
            'nome.max' => 'O nome pode ter no máximo 120 caracteres.',
            'chave_pix.required' => 'Informe a chave Pix: é para ela que o pagamento vai.',
            'chave_pix.max' => 'A chave Pix pode ter no máximo 140 caracteres.',
            'telefone.min' => 'Informe o telefone com DDD.',
            'telefone.max' => 'O telefone pode ter no máximo 40 caracteres.',
            'user_id.exists' => 'Escolha uma conta ativa do painel.',
            'user_id.unique' => 'Essa conta do painel já está ligada a outro responsável.',
            'setores.*.exists' => 'Escolha um setor que exista no catálogo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => $this->texto('nome'),
            // Chave com espaço sobrando na ponta é chave errada: ela seria
            // copiada com o espaço junto e recusada pelo banco de quem paga.
            'chave_pix' => $this->texto('chave_pix'),
            'telefone' => $this->texto('telefone'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDoResponsavel(): array
    {
        return [
            'nome' => (string) $this->string('nome'),
            'chave_pix' => (string) $this->string('chave_pix'),
            'telefone' => $this->texto('telefone'),
            'user_id' => $this->input('user_id') === null
                ? null
                : $this->integer('user_id'),
            'ativo' => $this->boolean('ativo', true),
        ];
    }

    /**
     * Os setores escolhidos, prontos para o sync do vinculo.
     *
     * @return array<int, int>
     */
    public function setores(): array
    {
        /** @var array<int, mixed> $escolhidos */
        $escolhidos = $this->input('setores', []);

        return array_values(array_unique(array_map(
            fn (mixed $id): int => (int) $id,
            is_array($escolhidos) ? $escolhidos : [],
        )));
    }

    /**
     * O campo recortado, ou nulo quando so sobrou espaco.
     */
    private function texto(string $campo): ?string
    {
        $valor = $this->input($campo);
        $valor = is_scalar($valor) ? trim((string) $valor) : '';

        return $valor === '' ? null : $valor;
    }
}
