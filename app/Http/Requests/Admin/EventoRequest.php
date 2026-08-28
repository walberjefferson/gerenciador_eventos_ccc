<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\SituacaoEvento;
use App\Models\Evento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que precisa ser verdade para um evento ser gravado.
 *
 * Cada regra daqui espelha uma restricao que o banco tambem cobra (ver
 * docs/DATABASE.md). A duplicidade e proposital: o banco e a ultima linha de
 * defesa e fala em linguagem de banco; aqui a recusa chega em portugues, no
 * campo certo do formulario, antes de o PostgreSQL precisar recusar.
 *
 * Alem das restricoes do banco, este arquivo carrega as travas de bom senso do
 * evento que ja tem gente inscrita: capacidade nao encolhe abaixo do que ja
 * esta ocupado, e valor nao muda com inscricao ativa em pe.
 */
class EventoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $evento = $this->eventoEmEdicao();

        return [
            'nome' => ['required', 'string', 'min:3', 'max:160'],
            'slug' => [
                'required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('eventos', 'slug')->ignore($evento?->getKey()),
            ],
            'descricao' => ['nullable', 'string'],
            'local' => ['nullable', 'string', 'max:160'],
            'local_detalhe' => ['nullable', 'string', 'max:255'],
            'data_inicio' => ['required', 'date'],
            // Espelha eventos_periodo_check.
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'inscricoes_abrem_em' => ['required', 'date'],
            // Espelha eventos_inscricoes_periodo_check.
            'inscricoes_fecham_em' => ['required', 'date', 'after:inscricoes_abrem_em'],
            'capacidade' => ['nullable', 'integer', 'min:0'],
            'valor_centavos' => ['required', 'integer', 'min:0'],
            'moeda' => ['required', 'string', 'size:3'],
            'prazo_pagamento_minutos' => ['required', 'integer', 'min:5', 'max:43200'],
            'situacao' => ['required', Rule::enum(SituacaoEvento::class)],
            'regulamento' => ['required', 'string', 'min:10'],
            'versao_termos' => ['required', 'string', 'max:40'],
            'contato_email' => ['required', 'email', 'max:160'],
            'contato_telefone' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * As travas que dependem de olhar o que ja aconteceu no evento.
     */
    public function after(): array
    {
        return [
            function (Validator $validador): void {
                $evento = $this->eventoEmEdicao();

                if (! $evento instanceof Evento) {
                    return;
                }

                $this->recusarCapacidadeAbaixoDoOcupado($validador, $evento);
                $this->recusarMudancaDeValorComInscricaoAtiva($validador, $evento);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome do evento.',
            'slug.required' => 'Informe o endereço do evento (o trecho que aparece na URL).',
            'slug.regex' => 'O endereço aceita apenas letras minúsculas, números e hífen. Exemplo: copa-ccc-2026.',
            'slug.unique' => 'Já existe um evento com esse endereço.',
            'data_fim.after_or_equal' => 'A data final não pode ser anterior à data inicial.',
            'inscricoes_fecham_em.after' => 'O fechamento das inscrições precisa ser depois da abertura.',
            'capacidade.min' => 'A capacidade não pode ser negativa. Deixe em branco para evento sem limite de vagas.',
            'valor_centavos.min' => 'O valor não pode ser negativo. Use zero para evento gratuito.',
            'prazo_pagamento_minutos.min' => 'O prazo de pagamento precisa ter ao menos 5 minutos.',
            'prazo_pagamento_minutos.max' => 'O prazo de pagamento não pode passar de 30 dias.',
            'regulamento.required' => 'O regulamento é obrigatório: é o texto que a pessoa aceita ao se inscrever.',
            'contato_email.email' => 'Informe um e-mail de contato válido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'data_inicio' => 'data inicial',
            'data_fim' => 'data final',
            'inscricoes_abrem_em' => 'abertura das inscrições',
            'inscricoes_fecham_em' => 'fechamento das inscrições',
            'valor_centavos' => 'valor',
            'prazo_pagamento_minutos' => 'prazo de pagamento',
            'contato_email' => 'e-mail de contato',
            'local_detalhe' => 'como chegar',
        ];
    }

    protected function prepareForValidation(): void
    {
        $nome = trim((string) $this->input('nome'));
        $slug = trim((string) $this->input('slug'));

        $this->merge([
            'nome' => $nome,
            // Endereço em branco vira o nome em formato de URL: e o que a
            // pessoa esperava digitar de qualquer jeito.
            'slug' => $slug === '' ? Str::slug($nome) : Str::slug($slug),
            'moeda' => mb_strtoupper((string) $this->input('moeda', 'BRL')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dadosDoEvento(): array
    {
        return [
            'nome' => (string) $this->string('nome'),
            'slug' => (string) $this->string('slug'),
            'descricao' => $this->input('descricao'),
            'local' => $this->input('local'),
            'local_detalhe' => $this->input('local_detalhe'),
            'data_inicio' => $this->date('data_inicio'),
            'data_fim' => $this->date('data_fim'),
            'inscricoes_abrem_em' => $this->date('inscricoes_abrem_em'),
            'inscricoes_fecham_em' => $this->date('inscricoes_fecham_em'),
            'capacidade' => $this->input('capacidade') === null ? null : $this->integer('capacidade'),
            'valor_centavos' => $this->integer('valor_centavos'),
            'moeda' => (string) $this->string('moeda'),
            'prazo_pagamento_minutos' => $this->integer('prazo_pagamento_minutos'),
            'situacao' => (string) $this->string('situacao'),
            'regulamento' => (string) $this->string('regulamento'),
            'versao_termos' => (string) $this->string('versao_termos'),
            'contato_email' => (string) $this->string('contato_email'),
            'contato_telefone' => $this->input('contato_telefone'),
        ];
    }

    private function eventoEmEdicao(): ?Evento
    {
        $evento = $this->route('evento');

        return $evento instanceof Evento ? $evento : null;
    }

    /**
     * Reduzir capacidade abaixo do que ja esta ocupado tiraria vaga de quem ja
     * tem — e o banco recusaria a gravacao de qualquer jeito.
     */
    private function recusarCapacidadeAbaixoDoOcupado(Validator $validador, Evento $evento): void
    {
        $nova = $this->input('capacidade');

        if ($nova === null) {
            return;
        }

        $ocupadas = $evento->vagasOcupadas();

        if ((int) $nova < $ocupadas) {
            $validador->errors()->add(
                'capacidade',
                "Este evento já tem {$ocupadas} vaga(s) ocupada(s) entre reservas e confirmações. "
                .'A capacidade não pode ficar abaixo disso: cancele inscrições antes de reduzir.'
            );
        }
    }

    /**
     * Mudar o valor com gente inscrita cobraria de uns um preco e de outros
     * outro, sem ninguem ter combinado isso.
     */
    private function recusarMudancaDeValorComInscricaoAtiva(Validator $validador, Evento $evento): void
    {
        if ((int) $this->input('valor_centavos') === (int) $evento->valor_centavos) {
            return;
        }

        $ativas = $evento->inscricoes()->ativas()->count();

        if ($ativas > 0) {
            $validador->errors()->add(
                'valor_centavos',
                "Este evento já tem {$ativas} inscrição(ões) ativa(s) com o valor atual. "
                .'Mudar o preço agora cobraria valores diferentes pela mesma coisa: '
                .'encerre as inscrições ou crie outro evento.'
            );
        }
    }
}
