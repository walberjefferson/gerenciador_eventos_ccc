<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O que precisa vir junto com um cancelamento administrativo.
 *
 * O motivo e obrigatorio e nao aceita duas letras jogadas: acao administrativa
 * sem justificativa e rastro que nao explica nada, e daqui a algumas fases
 * esses textos viram o registro de auditoria que alguem vai precisar ler.
 */
class CancelarInscricaoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo do cancelamento. Ele fica registrado na inscrição.',
            'motivo.min' => 'Escreva o motivo com pelo menos algumas palavras: quem ler depois precisa entender.',
            'motivo.max' => 'O motivo pode ter no máximo 255 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
    }
}
