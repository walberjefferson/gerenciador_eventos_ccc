<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Ingressos\GeradorDeCodigo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que precisa vir do portao para uma conferencia acontecer.
 *
 * O codigo chega de dois jeitos — lido pela camera ou digitado a mao — e os
 * dois passam por aqui. A NORMALIZACAO ACONTECE ANTES DA VALIDACAO, no
 * `prepareForValidation`, e e ela que faz a digitacao funcionar de verdade:
 * "abcd-efgh-jkmn", "ABCD EFGH JKMN" e "ABCDEFGHJKMN" sao o mesmo ingresso, e
 * quem esta na fila nao tem por que saber disso.
 *
 * O alfabeto e o de Crockford (sem I, L, O e U), e a mesma funcao que gera o
 * codigo e a que o normaliza — por isso ela vem do `GeradorDeCodigo` e nao
 * esta reescrita aqui. Duas versoes da mesma regra divergiriam no dia em que
 * alguem mexesse numa delas.
 *
 * O tamanho e conferido, mas a EXISTENCIA nao: se o codigo existe ou nao e
 * resposta do dominio, com motivo proprio para a tela, e nao um erro de
 * formulario em campo vermelho.
 */
class ValidarIngressoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'evento_id' => ['required', 'integer', Rule::exists('eventos', 'id')],
            'codigo' => [
                'required',
                'string',
                'size:'.GeradorDeCodigo::TAMANHO,
                // So os caracteres do alfabeto de Crockford. Quem digitou uma
                // letra que nao existe la ja foi corrigido na normalizacao (o
                // "O" virou zero); o que sobra aqui e lixo de verdade.
                'regex:/^['.GeradorDeCodigo::ALFABETO.']+$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Digite o código do ingresso ou aponte a câmera para o QR Code.',
            'codigo.size' => 'O código do ingresso tem 12 caracteres.',
            'codigo.regex' => 'Esse código tem caracteres que não existem em nenhum ingresso.',
            'evento_id.required' => 'Escolha em qual evento a portaria está trabalhando.',
            'evento_id.exists' => 'Esse evento não existe.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => GeradorDeCodigo::normalizar((string) $this->input('codigo')),
        ]);
    }

    /** O codigo ja normalizado, do jeito que o banco o guarda. */
    public function codigo(): string
    {
        return (string) $this->string('codigo');
    }
}
