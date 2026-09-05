<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O que precisa ser verdade para um comprovante ser aceito ou recusado.
 *
 * Os dois campos sao obrigatorios, cada um no seu caminho, e por motivos
 * diferentes:
 *
 * - a OBSERVACAO do aceite e a mesma exigencia que ConfirmarPagamentoManual ja
 *   faz desde sempre: quem declara que entrou dinheiro precisa dizer o que viu.
 *   Ela vai para a auditoria, e uma auditoria com o campo vazio nao explica
 *   nada a quem for ler daqui a um ano;
 * - o MOTIVO da recusa e o que o participante le. Sem ele, ele fica sabendo que
 *   nao deu certo e nao fica sabendo o que corrigir.
 *
 * Nao ha `authorize()` proprio: a rota cobra a permissao e o controller cobra o
 * escopo de setor pela Policy, que e o lugar unico dessa regra.
 */
class ConferirComprovanteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'observacao' => [$this->ehRecusa() ? 'nullable' : 'required', 'string', 'min:5', 'max:500'],
            'motivo' => [$this->ehRecusa() ? 'required' : 'nullable', 'string', 'min:5', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'observacao.required' => 'Descreva o que você conferiu no comprovante. '
                .'Este texto fica no histórico do pagamento.',
            'observacao.min' => 'Escreva um pouco mais: este texto fica no histórico do pagamento.',
            'motivo.required' => 'Escreva por que o comprovante não foi aceito: '
                .'é o que o participante vai ler para corrigir.',
            'motivo.min' => 'Escreva um pouco mais: é o que o participante vai ler para corrigir.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'observacao' => 'observação',
            'motivo' => 'motivo da recusa',
        ];
    }

    public function observacao(): string
    {
        return trim((string) $this->input('observacao'));
    }

    public function motivo(): string
    {
        return trim((string) $this->input('motivo'));
    }

    /**
     * Qual das duas rotas chamou.
     *
     * A pergunta e feita a ROTA, e nao a um campo do formulario: o que a pessoa
     * clicou nao pode ser dito pelo corpo do pedido, que ela controla.
     */
    private function ehRecusa(): bool
    {
        return $this->routeIs('admin.comprovantes.recusar');
    }
}
