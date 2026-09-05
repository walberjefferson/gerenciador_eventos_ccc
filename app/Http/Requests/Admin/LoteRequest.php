<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Evento;
use App\Models\Lote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que precisa ser verdade para um lote ser gravado.
 *
 * Espelha as restricoes do banco, para que o organizador leia uma frase em
 * portugues antes de o PostgreSQL precisar recusar: a posicao nao se repete
 * dentro do evento (RN-L2), todo lote encerra por alguma coisa (RN-L1) e a
 * quantidade nunca fica abaixo do que ja foi vendido (RN-L12).
 *
 * O CHECK do banco continua no lugar, e nao e redundancia: ele e a ultima
 * linha de defesa para o que nao passar por este formulario.
 */
class LoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $lote = $this->route('lote');
        $id = $lote instanceof Lote ? $lote->getKey() : null;

        return [
            'nome' => ['required', 'string', 'min:2', 'max:80'],
            'posicao' => [
                'required', 'integer', 'min:1', 'max:32767',
                Rule::unique('lotes', 'posicao')
                    ->where(fn ($consulta) => $consulta->where('evento_id', $this->evento()->getKey()))
                    ->ignore($id),
            ],
            'valor_centavos' => ['required', 'integer', 'min:0'],
            'disponivel_ate' => ['nullable', 'date'],
            'quantidade' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador): void {
                // RN-L1 — lote sem limite nenhum nunca encerraria, e um lote
                // que nao encerra e o preco do evento com outro nome.
                if ($this->input('disponivel_ate') === null && $this->input('quantidade') === null) {
                    $validador->errors()->add(
                        'disponivel_ate',
                        'Todo lote precisa de um limite: uma data, uma quantidade de vagas, ou os dois. '
                        .'Sem limite, ele nunca encerraria — e aí é o valor do próprio evento.'
                    );
                }
            },
            function (Validator $validador): void {
                // RN-L12 — a quantidade nao pode ficar abaixo do que ja saiu.
                $lote = $this->route('lote');
                $quantidade = $this->input('quantidade');

                if (! $lote instanceof Lote || $quantidade === null) {
                    return;
                }

                if ((int) $quantidade < $lote->vagas_ocupadas) {
                    $validador->errors()->add(
                        'quantidade',
                        "Este lote já ocupou {$lote->vagas_ocupadas} vaga(s). A quantidade não pode ser menor do que isso: "
                        .'reduzi-la apagaria vagas que já foram entregues a alguém.'
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
            'nome.required' => 'Informe o nome do lote. Exemplo: 1º lote.',
            'posicao.required' => 'Informe a posição deste lote na sequência.',
            'posicao.unique' => 'Já existe um lote nesta posição. Cada lote ocupa uma posição diferente na sequência.',
            'posicao.min' => 'A posição começa em 1.',
            'valor_centavos.required' => 'Informe o valor deste lote.',
            'valor_centavos.min' => 'O valor não pode ser negativo.',
            'disponivel_ate.date' => 'Esta data não existe. Confira o dia, o mês e o ano.',
            'quantidade.min' => 'A quantidade de vagas do lote começa em 1.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDoLote(): array
    {
        return [
            'evento_id' => $this->evento()->getKey(),
            'nome' => trim((string) $this->string('nome')),
            'posicao' => $this->integer('posicao'),
            'valor_centavos' => $this->integer('valor_centavos'),
            'disponivel_ate' => $this->date('disponivel_ate'),
            'quantidade' => $this->input('quantidade') === null ? null : $this->integer('quantidade'),
        ];
    }

    private function evento(): Evento
    {
        $evento = $this->route('evento');

        if ($evento instanceof Evento) {
            return $evento;
        }

        $lote = $this->route('lote');

        return $lote instanceof Lote ? $lote->evento : abort(404);
    }
}
