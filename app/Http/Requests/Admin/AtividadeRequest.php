<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Atividade;
use App\Models\GrupoAtividade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que precisa ser verdade para uma atividade ser gravada.
 *
 * Espelha as restricoes do banco (horario que termina depois de comecar,
 * capacidade que nao fica abaixo do ocupado) e acrescenta as travas de bom
 * senso: idade minima nao passa da maxima, e atividade que ja tem gente
 * escolhida nao muda de grupo.
 *
 * O horário é opcional, mas em par: uma atividade pode não ter hora marcada —
 * e então acontece no dia inteiro do dia de programação —, mas nunca pode ter
 * só o começo ou só o fim.
 */
class AtividadeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grupo_atividade_id' => ['required', 'integer', Rule::exists('grupos_atividades', 'id')],
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'descricao' => ['nullable', 'string'],
            // O horário é opcional EM PAR (RN-A1): atividade sem hora marcada
            // acontece no dia inteiro do dia de programação a que pertence.
            // Metade preenchida não descreve nada e o banco recusaria, então a
            // recusa vem antes, com nome de campo e frase em português.
            'comeca_em' => ['nullable', 'date', 'required_with:termina_em'],
            // Espelha atividades_horario_check. O "after" só entra quando os
            // dois vieram: comparar com um campo vazio produziria uma recusa
            // sobre algo que a pessoa nem preencheu.
            'termina_em' => $this->horarioCompleto()
                ? ['nullable', 'date', 'required_with:comeca_em', 'after:comeca_em']
                : ['nullable', 'date', 'required_with:comeca_em'],
            'capacidade' => ['nullable', 'integer', 'min:0'],
            'idade_minima' => ['nullable', 'integer', 'min:0', 'max:120'],
            'idade_maxima' => ['nullable', 'integer', 'min:0', 'max:120', 'gte:idade_minima'],
            'posicao' => ['required', 'integer', 'min:1', 'max:32767'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador): void {
                $atividade = $this->route('atividade');

                if (! $atividade instanceof Atividade) {
                    return;
                }

                $this->recusarCapacidadeAbaixoDoOcupado($validador, $atividade);
                $this->recusarTrocaDeEvento($validador, $atividade);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grupo_atividade_id.required' => 'Escolha o grupo a que esta atividade pertence.',
            'nome.required' => 'Informe o nome da atividade.',
            'comeca_em.required_with' => 'Informe também a hora de início. O horário é opcional, mas, quando existe, precisa ter começo e fim.',
            'comeca_em.date' => 'Informe a data e a hora de início por completo, ou deixe o horário todo em branco.',
            'termina_em.required_with' => 'Informe também a hora de término. O horário é opcional, mas, quando existe, precisa ter começo e fim.',
            'termina_em.date' => 'Informe a data e a hora de término por completo, ou deixe o horário todo em branco.',
            'termina_em.after' => 'A atividade precisa terminar depois de começar.',
            'capacidade.min' => 'A capacidade não pode ser negativa. Deixe em branco para atividade sem limite.',
            'idade_maxima.gte' => 'A idade máxima não pode ser menor que a mínima.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'grupo_atividade_id' => 'grupo',
            'comeca_em' => 'hora de início',
            'termina_em' => 'hora de término',
            'idade_minima' => 'idade mínima',
            'idade_maxima' => 'idade máxima',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDaAtividade(): array
    {
        return [
            'grupo_atividade_id' => $this->integer('grupo_atividade_id'),
            'nome' => trim((string) $this->string('nome')),
            'descricao' => $this->input('descricao'),
            'comeca_em' => $this->horarioOuNulo('comeca_em'),
            'termina_em' => $this->horarioOuNulo('termina_em'),
            'capacidade' => $this->input('capacidade') === null ? null : $this->integer('capacidade'),
            'idade_minima' => $this->input('idade_minima') === null ? null : $this->integer('idade_minima'),
            'idade_maxima' => $this->input('idade_maxima') === null ? null : $this->integer('idade_maxima'),
            'posicao' => $this->integer('posicao'),
            'ativo' => $this->boolean('ativo', true),
        ];
    }

    /**
     * Os dois campos do horário vieram preenchidos?
     *
     * O formulário manda string vazia quando a pessoa não preenche, e o campo
     * de data e hora manda "AAAA-MM-DDT" quando ela escolheu a data e não
     * digitou a hora. Nenhum dos dois é horário — mas os dois chegam aqui.
     */
    private function horarioCompleto(): bool
    {
        return $this->horarioInformado('comeca_em') && $this->horarioInformado('termina_em');
    }

    private function horarioInformado(string $campo): bool
    {
        $valor = $this->input($campo);

        return $valor !== null && trim((string) $valor) !== '';
    }

    /**
     * O horário como o banco o guarda: a data e a hora, ou nada.
     */
    private function horarioOuNulo(string $campo): ?Carbon
    {
        return $this->horarioInformado($campo) ? $this->date($campo) : null;
    }

    /**
     * Espelha atividades_capacidade_check: reduzir abaixo do ocupado tiraria
     * vaga de quem ja escolheu esta atividade.
     */
    private function recusarCapacidadeAbaixoDoOcupado(Validator $validador, Atividade $atividade): void
    {
        $nova = $this->input('capacidade');

        if ($nova === null) {
            return;
        }

        $ocupadas = $atividade->vagasOcupadas();

        if ((int) $nova < $ocupadas) {
            $validador->errors()->add(
                'capacidade',
                "Esta atividade já tem {$ocupadas} vaga(s) ocupada(s) entre reservas e confirmações. "
                .'A capacidade não pode ficar abaixo disso: cancele inscrições antes de reduzir.'
            );
        }
    }

    /**
     * A atividade pode trocar de grupo dentro do mesmo evento, mas nunca pular
     * para outro evento: as escolhas ja feitas apontam para ela.
     */
    private function recusarTrocaDeEvento(Validator $validador, Atividade $atividade): void
    {
        $destino = GrupoAtividade::query()->with('diaEvento')->find($this->integer('grupo_atividade_id'));
        $origem = $atividade->grupoAtividade()->with('diaEvento')->first();

        if ($destino === null || $origem === null) {
            return;
        }

        if ($destino->diaEvento?->evento_id !== $origem->diaEvento?->evento_id) {
            $validador->errors()->add(
                'grupo_atividade_id',
                'Uma atividade não pode ser movida para outro evento.'
            );
        }
    }
}
