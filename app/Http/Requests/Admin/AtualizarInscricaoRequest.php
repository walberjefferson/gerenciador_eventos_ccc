<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\Inscricoes\DadosEdicaoInscricao;
use App\Enums\Sexo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confere o formato do formulario de correcao de uma inscricao.
 *
 * As regras de formato sao as MESMAS do formulario publico
 * (StoreInscricaoRequest), de proposito: um nome que o participante nao
 * conseguiria digitar tambem nao pode entrar pelo painel. O que muda e o
 * publico das mensagens — aqui quem le trabalha na organizacao.
 *
 * **O CPF NAO ESTA AQUI, E A AUSENCIA E A REGRA.** Nem `documento`, nem
 * `documento_hash`. Trocar o documento mexeria na chave que impede a mesma
 * pessoa de se inscrever duas vezes no mesmo evento — e isso e outro plano.
 * Tambem nao entram evento, lote, valor nem as colunas de momento: nada disso e
 * correcao de cadastro.
 *
 * Quem decide se a pessoa PODE ser alterada nao e este arquivo (e a policy), e
 * quem decide se a combinacao de atividades pode existir tambem nao (e a
 * Action, dentro da transacao).
 */
class AtualizarInscricaoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome_completo' => ['required', 'string', 'min:3', 'max:160'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'telefone' => ['required', 'string', 'min:8', 'max:40'],
            'data_nascimento' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            // Obrigatorio na correcao, ainda que a coluna aceite nulo: o nulo
            // existe para acomodar as inscricoes anteriores ao campo (RN-X2), e
            // quem esta corrigindo a ficha tem a pessoa a mao para perguntar.
            'sexo' => ['required', Rule::enum(Sexo::class)],
            // O setor vem junto com o grupo: ele e a cidade do grupo, e nao
            // campo proprio da inscricao.
            'grupo_participante_id' => ['required', 'integer', 'exists:grupos_participantes,id'],
            'atividades' => ['present', 'array'],
            'atividades.*' => ['integer', 'distinct'],
            // A segunda confirmacao para furar a lotacao de uma atividade
            // (RN-E2). Chega falso na primeira tentativa; a tela so a oferece
            // depois que o servidor recusou por lotacao.
            'permitir_exceder_capacidade' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome_completo.required' => 'Informe o nome completo da pessoa inscrita.',
            'nome_completo.min' => 'Informe o nome completo da pessoa inscrita.',
            'email.required' => 'Informe o e-mail da pessoa inscrita.',
            'email.email' => 'Este e-mail parece incompleto. Confira e tente de novo.',
            'telefone.required' => 'Informe um telefone com DDD para contato.',
            'telefone.min' => 'Informe um telefone com DDD para contato.',
            'data_nascimento.required' => 'Informe a data de nascimento.',
            'data_nascimento.date' => 'Esta data de nascimento não existe. Confira o dia, o mês e o ano.',
            'data_nascimento.before' => 'A data de nascimento precisa ser anterior a hoje.',
            'sexo.required' => 'Escolha o sexo da pessoa inscrita.',
            'sexo.enum' => 'Escolha uma das opções de sexo oferecidas.',
            'grupo_participante_id.required' => 'Escolha o grupo da pessoa inscrita.',
            'grupo_participante_id.exists' => 'O grupo escolhido não está disponível.',
            'atividades.present' => 'Envie as atividades escolhidas, mesmo que seja uma lista vazia.',
            'atividades.array' => 'Envie as atividades escolhidas, mesmo que seja uma lista vazia.',
            'atividades.*.distinct' => 'A mesma atividade foi escolhida duas vezes.',
        ];
    }

    /**
     * Os dados ja conferidos, prontos para a Action.
     */
    public function dados(): DadosEdicaoInscricao
    {
        /** @var array<string, mixed> $validados */
        $validados = $this->validated();

        return DadosEdicaoInscricao::deArray($validados);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'atividades' => $this->input('atividades', []),
            'permitir_exceder_capacidade' => filter_var(
                $this->input('permitir_exceder_capacidade'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? false,
        ]);
    }
}
