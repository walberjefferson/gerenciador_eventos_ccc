<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Actions\Comunicacao\ReenviarComunicacao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Qual mensagem reenviar.
 *
 * Aqui so se confere que o tipo pedido EXISTE. Se ele faz sentido para aquela
 * inscricao naquela situacao — mandar o comprovante para quem nao pagou, por
 * exemplo — e pergunta de negocio, e quem responde e a Action (RN-A2). A
 * separacao importa: a lista de tipos nao muda, mas o que vale para cada
 * inscricao muda a cada pagamento reconhecido.
 */
class ReenviarComunicacaoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(ReenviarComunicacao::tipos())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Escolha qual mensagem reenviar.',
            'tipo.in' => 'Esta mensagem não existe. Atualize a página e escolha de novo.',
        ];
    }

    public function tipo(): string
    {
        return (string) $this->validated('tipo');
    }
}
