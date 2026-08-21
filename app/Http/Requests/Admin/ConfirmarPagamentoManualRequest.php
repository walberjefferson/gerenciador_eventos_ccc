<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\MetodoPagamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que precisa vir junto com a confirmacao manual de um pagamento.
 *
 * Pix e cartao nao entram na lista: esses o provedor reconhece sozinho, e
 * declarar na mao um pagamento que o provedor deveria confirmar e o comeco de
 * um historico de dinheiro que nao bate com a realidade.
 */
class ConfirmarPagamentoManualRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'metodo' => ['required', Rule::in(array_map(
                fn (MetodoPagamento $metodo): string => $metodo->value,
                MetodoPagamento::manuais(),
            ))],
            'observacao' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'metodo.required' => 'Diga como o dinheiro chegou.',
            'metodo.in' => 'Pix e cartão são reconhecidos pelo provedor, não na mão. Escolha dinheiro, transferência ou outro.',
            'observacao.required' => 'Descreva como o pagamento foi recebido. Fica registrado na cobrança.',
            'observacao.min' => 'Escreva a observação com pelo menos algumas palavras: quem ler depois precisa entender.',
        ];
    }

    public function metodo(): MetodoPagamento
    {
        return MetodoPagamento::from((string) $this->string('metodo'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['observacao' => trim((string) $this->input('observacao'))]);
    }
}
