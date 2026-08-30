<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Inscricoes\DadosNovaInscricao;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confere o formato dos dados do formulario de inscricao.
 *
 * Aqui so entra o que da para conferir sem consultar as regras do evento:
 * campo obrigatorio, formato de e-mail, CPF com digitos validos, data que
 * existe. As regras de negocio (vaga, conflito, idade minima) ficam na Action,
 * dentro da transacao — este arquivo nunca decide se a pessoa pode se
 * inscrever.
 *
 * Toda mensagem e escrita para o participante.
 */
class StoreInscricaoRequest extends FormRequest
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
            'evento_id' => ['required', 'integer', 'exists:eventos,id'],
            'cidade_id' => ['required', 'integer', 'exists:cidades,id'],
            'grupo_participante_id' => ['required', 'integer', 'exists:grupos_participantes,id'],
            'nome_completo' => ['required', 'string', 'min:3', 'max:160'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'telefone' => ['required', 'string', 'min:8', 'max:40'],
            'documento' => ['required', 'string', 'max:20', $this->cpfValido()],
            'data_nascimento' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'atividades' => ['present', 'array'],
            'atividades.*' => ['integer', 'distinct'],
            'aceite_termos' => ['accepted'],
            'chave_idempotencia' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evento_id.required' => 'Não foi possível identificar o evento desta inscrição.',
            'evento_id.exists' => 'Este evento não existe mais.',
            'cidade_id.required' => 'Escolha o seu setor.',
            'cidade_id.exists' => 'O setor escolhido não está disponível.',
            'grupo_participante_id.required' => 'Escolha o seu grupo.',
            'grupo_participante_id.exists' => 'O grupo escolhido não está disponível.',
            'nome_completo.required' => 'Informe o seu nome completo.',
            'nome_completo.min' => 'Informe o seu nome completo.',
            'email.required' => 'Informe o seu e-mail.',
            'email.email' => 'Este e-mail parece incompleto. Confira e tente de novo.',
            'telefone.required' => 'Informe um telefone com DDD para contato.',
            'telefone.min' => 'Informe um telefone com DDD para contato.',
            'documento.required' => 'Informe o seu CPF.',
            'data_nascimento.required' => 'Informe a sua data de nascimento.',
            'data_nascimento.date' => 'Esta data de nascimento não existe. Confira o dia, o mês e o ano.',
            'data_nascimento.before' => 'A data de nascimento precisa ser anterior a hoje.',
            'atividades.present' => 'Envie as atividades escolhidas, mesmo que seja uma lista vazia.',
            'atividades.array' => 'Envie as atividades escolhidas, mesmo que seja uma lista vazia.',
            'atividades.*.distinct' => 'Você escolheu a mesma atividade duas vezes.',
            'aceite_termos.accepted' => 'Você precisa aceitar o regulamento do evento para continuar.',
            'chave_idempotencia.required' => 'Recarregue a página e envie o formulário novamente.',
            'chave_idempotencia.uuid' => 'Recarregue a página e envie o formulário novamente.',
        ];
    }

    /**
     * Os dados ja conferidos, prontos para a Action.
     */
    public function dados(): DadosNovaInscricao
    {
        /** @var array<string, mixed> $validados */
        $validados = $this->validated();

        return DadosNovaInscricao::deArray($validados);
    }

    /**
     * Confere o CPF pelos dois digitos verificadores. Nao consulta a Receita:
     * apenas recusa numero impossivel, como erro de digitacao.
     */
    private function cpfValido(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $recusar): void {
            $digitos = preg_replace('/\D/', '', (string) $valor) ?? '';

            if (strlen($digitos) !== 11 || preg_match('/^(\d)\1{10}$/', $digitos) === 1) {
                $recusar('Este CPF não parece válido. Confira os números digitados.');

                return;
            }

            foreach ([9, 10] as $posicao) {
                $soma = 0;

                for ($i = 0; $i < $posicao; $i++) {
                    $soma += (int) $digitos[$i] * (($posicao + 1) - $i);
                }

                $verificador = ((10 * $soma) % 11) % 10;

                if ($verificador !== (int) $digitos[$posicao]) {
                    $recusar('Este CPF não parece válido. Confira os números digitados.');

                    return;
                }
            }
        };
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'atividades' => $this->input('atividades', []),
            'aceite_termos' => filter_var(
                $this->input('aceite_termos'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? false,
        ]);
    }
}
