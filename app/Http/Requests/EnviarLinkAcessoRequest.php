<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confere o formato do e-mail no pedido de link de acesso.
 *
 * Aqui so se olha o formato. Se existe inscricao para esse endereco e assunto
 * do controller — e a resposta e a mesma nos dois casos, para que ninguem
 * descubra quem esta inscrito perguntando ao formulario.
 */
class EnviarLinkAcessoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'evento' => ['nullable', 'string', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail que você usou na inscrição.',
            'email.email' => 'Este e-mail parece incompleto. Confira e tente de novo.',
            'email.max' => 'Este e-mail é longo demais. Confira e tente de novo.',
        ];
    }
}
