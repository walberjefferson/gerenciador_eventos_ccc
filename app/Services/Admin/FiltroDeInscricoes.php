<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\Inscricao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Monta a consulta da lista de inscricoes a partir do que o organizador pediu.
 *
 * Existe uma vez so e e usada em dois lugares — a tela e a exportacao — de
 * proposito: se a consulta fosse escrita duas vezes, um dia o CSV traria linhas
 * diferentes das que estao na tela, e ninguem descobriria antes de o arquivo ja
 * ter sido mandado para alguem.
 *
 * **CPF nao filtra e nao busca.** O documento e guardado cifrado (D-08) e a
 * impressao digital serve so para comparar o numero inteiro. Procurar por
 * pedaco e impossivel por construcao — e essa impossibilidade e a protecao.
 */
final class FiltroDeInscricoes
{
    /**
     * @param  array<string, string|null>  $valores
     */
    private function __construct(private readonly array $valores) {}

    public static function doPedido(Request $pedido): self
    {
        $texto = static function (mixed $valor): ?string {
            $valor = is_scalar($valor) ? trim((string) $valor) : '';

            return $valor === '' ? null : $valor;
        };

        return new self([
            'evento_id' => $texto($pedido->input('evento_id')),
            'situacao' => $texto($pedido->input('situacao')),
            'cidade_id' => $texto($pedido->input('cidade_id')),
            'grupo_participante_id' => $texto($pedido->input('grupo_participante_id')),
            'atividade_id' => $texto($pedido->input('atividade_id')),
            'situacao_pagamento' => $texto($pedido->input('situacao_pagamento')),
            'criada_de' => $texto($pedido->input('criada_de')),
            'criada_ate' => $texto($pedido->input('criada_ate')),
            'busca' => $texto($pedido->input('busca')),
        ]);
    }

    /**
     * Os valores em vigor, do jeito que a tela precisa devolver para os campos.
     *
     * @return array<string, string|null>
     */
    public function valores(): array
    {
        return $this->valores;
    }

    /**
     * Os mesmos valores sem os vazios, para pendurar na URL da paginacao e do
     * botao de exportar sem encher o endereco de campo em branco.
     *
     * @return array<string, string>
     */
    public function paraUrl(): array
    {
        return array_filter($this->valores, fn (?string $valor): bool => $valor !== null);
    }

    public function temAlgumFiltro(): bool
    {
        return $this->paraUrl() !== [];
    }

    /**
     * A consulta pronta, sem paginacao, ordenada da mais recente para a mais
     * antiga — que e a ordem em que o organizador procura alguem.
     *
     * @return Builder<Inscricao>
     */
    public function consulta(): Builder
    {
        $consulta = Inscricao::query()
            ->with([
                'evento:id,nome',
                'grupoParticipante:id,nome,cidade_id',
                'grupoParticipante.cidade:id,nome,uf',
                'pagamentos:id,inscricao_id,situacao,valor_centavos,pago_em,expira_em',
            ])
            ->orderByDesc('inscricoes.created_at')
            ->orderByDesc('inscricoes.id');

        $this->porEvento($consulta);
        $this->porSituacao($consulta);
        $this->porCidade($consulta);
        $this->porGrupoParticipante($consulta);
        $this->porAtividade($consulta);
        $this->porSituacaoDoPagamento($consulta);
        $this->porPeriodo($consulta);
        $this->porBusca($consulta);

        return $consulta;
    }

    /**
     * @param  Builder<Inscricao>  $consulta
     */
    private function porEvento(Builder $consulta): void
    {
        if ($this->valores['evento_id'] !== null) {
            $consulta->where('inscricoes.evento_id', (int) $this->valores['evento_id']);
        }
    }

    /**
     * @param  Builder<Inscricao>  $consulta
     */
    private function porSituacao(Builder $consulta): void
    {
        $situacao = SituacaoInscricao::tryFrom((string) $this->valores['situacao']);

        if ($situacao instanceof SituacaoInscricao) {
            $consulta->where('inscricoes.situacao', $situacao->value);
        }
    }

    /**
     * A cidade nao esta na inscricao: ela vem pelo grupo de participantes, que
     * e o que a pessoa escolheu no formulario.
     *
     * @param  Builder<Inscricao>  $consulta
     */
    private function porCidade(Builder $consulta): void
    {
        if ($this->valores['cidade_id'] === null) {
            return;
        }

        $cidade = (int) $this->valores['cidade_id'];

        $consulta->whereHas('grupoParticipante', fn (Builder $grupo) => $grupo->where('cidade_id', $cidade));
    }

    /**
     * @param  Builder<Inscricao>  $consulta
     */
    private function porGrupoParticipante(Builder $consulta): void
    {
        if ($this->valores['grupo_participante_id'] !== null) {
            $consulta->where('inscricoes.grupo_participante_id', (int) $this->valores['grupo_participante_id']);
        }
    }

    /**
     * @param  Builder<Inscricao>  $consulta
     */
    private function porAtividade(Builder $consulta): void
    {
        if ($this->valores['atividade_id'] === null) {
            return;
        }

        $atividade = (int) $this->valores['atividade_id'];

        $consulta->whereHas('atividades', fn (Builder $escolhida) => $escolhida->where('atividades.id', $atividade));
    }

    /**
     * A situacao da cobranca mais recente da inscricao. E a que o organizador
     * enxerga na ficha, entao e a que o filtro precisa considerar.
     *
     * @param  Builder<Inscricao>  $consulta
     */
    private function porSituacaoDoPagamento(Builder $consulta): void
    {
        $situacao = SituacaoPagamento::tryFrom((string) $this->valores['situacao_pagamento']);

        if (! $situacao instanceof SituacaoPagamento) {
            return;
        }

        $consulta->whereHas('pagamentos', fn (Builder $pagamento) => $pagamento
            ->where('situacao', $situacao->value)
            ->whereRaw('pagamentos.id = (select max(p2.id) from pagamentos p2 where p2.inscricao_id = inscricoes.id)'));
    }

    /**
     * @param  Builder<Inscricao>  $consulta
     */
    private function porPeriodo(Builder $consulta): void
    {
        if ($this->valores['criada_de'] !== null) {
            $consulta->where('inscricoes.created_at', '>=', Carbon::parse($this->valores['criada_de'])->startOfDay());
        }

        if ($this->valores['criada_ate'] !== null) {
            $consulta->where('inscricoes.created_at', '<=', Carbon::parse($this->valores['criada_ate'])->endOfDay());
        }
    }

    /**
     * Busca por nome, e-mail e codigo publico — e so por isso.
     *
     * @param  Builder<Inscricao>  $consulta
     */
    private function porBusca(Builder $consulta): void
    {
        if ($this->valores['busca'] === null) {
            return;
        }

        $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->valores['busca']).'%';

        $consulta->where(function (Builder $parte) use ($termo): void {
            $parte->where('inscricoes.nome_completo', 'ilike', $termo)
                ->orWhere('inscricoes.email', 'ilike', $termo)
                ->orWhere('inscricoes.codigo_publico', 'ilike', $termo);
        });
    }
}
