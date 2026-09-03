<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\Inscricao;
use App\Models\User;
use App\Policies\ComprovantePagamentoPolicy;
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
 *
 * **O setor tem duas caras aqui, e elas nao se misturam.** `cidade_id` e um
 * FILTRO: quem tem alcance amplo escolhe um setor para olhar e troca de setor a
 * hora que quiser. O escopo da RN-S9 e outra coisa: para quem responde por um
 * setor, o recorte e imposto pelo servidor, some da URL e nao aceita ser
 * alterado — trocar `cidade_id` na barra de enderecos nao amplia nada, porque
 * as duas condicoes valem ao mesmo tempo.
 */
final class FiltroDeInscricoes
{
    /**
     * @param  array<string, string|null>  $valores
     * @param  array<int, int>|null  $setoresPermitidos  null quando o alcance e
     *                                                   amplo; a lista (mesmo
     *                                                   vazia) quando ha recorte
     */
    private function __construct(
        private readonly array $valores,
        private readonly ?array $setoresPermitidos = null,
    ) {}

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
        ], self::escopoDoUsuario($pedido->user()));
    }

    /**
     * O recorte de setor que vale para quem fez o pedido.
     *
     * Devolve `null` — "sem recorte" — para quem alcanca todos os setores, e a
     * LISTA para quem responde por alguns. Lista vazia e uma resposta legitima
     * e quer dizer "nenhuma inscricao": quem nao responde por setor nenhum e
     * nao alcanca tudo nao tem o que ver aqui.
     *
     * A distincao entre `null` e `[]` e o coracao desta funcao. Se as duas
     * fossem a mesma coisa, o dia em que um responsavel perdesse o setor ele
     * passaria a ver o sistema inteiro — que e exatamente o erro que este
     * arquivo existe para nao cometer.
     *
     * @return array<int, int>|null
     */
    private static function escopoDoUsuario(?User $usuario): ?array
    {
        if (! $usuario instanceof User || ComprovantePagamentoPolicy::alcancaTodosOsSetores($usuario)) {
            return null;
        }

        return ComprovantePagamentoPolicy::setoresDe($usuario);
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

        $this->aplicarEscopoDeSetor($consulta);
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
     * O escopo obrigatorio da RN-S9.
     *
     * Ele entra ANTES de qualquer filtro escolhido na tela, e nao vem de
     * parametro nenhum: a lista de setores foi lida do banco a partir de quem
     * esta logado. Quem responde pelo Setor A nao amplia isto trocando
     * `cidade_id` na URL — no maximo estreita ainda mais, o que e inofensivo.
     *
     * @param  Builder<Inscricao>  $consulta
     */
    private function aplicarEscopoDeSetor(Builder $consulta): void
    {
        if ($this->setoresPermitidos === null) {
            return;
        }

        $setores = $this->setoresPermitidos;

        $consulta->whereHas('grupoParticipante', fn (Builder $grupo) => $grupo->whereIn('cidade_id', $setores));
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
     * Busca por nome, e-mail, codigo publico e identificador da cobranca no
     * provedor (o txid) — e so por isso.
     *
     * Os tres primeiros procuram por pedaco, porque quem digita "joana" ou o
     * comeco de um codigo espera achar. O txid, nao: ele nunca e digitado, e
     * sempre colado inteiro do painel da instituicao financeira. A comparacao
     * dele e de igualdade por dois motivos, e o segundo importa mais que o
     * primeiro: `ilike '%…%'` obrigaria a percorrer a tabela de pagamentos
     * inteira na busca mais usada do sistema, enquanto a igualdade cai no
     * indice `pagamentos_gateway_id_externo_unique` que ja existe.
     *
     * Cobranca reconhecida na mao tem `id_externo` nulo e por isso nunca entra
     * neste ramo — o que esta certo: ela nao existe em provedor nenhum.
     *
     * @param  Builder<Inscricao>  $consulta
     */
    private function porBusca(Builder $consulta): void
    {
        if ($this->valores['busca'] === null) {
            return;
        }

        $busca = $this->valores['busca'];
        $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $busca).'%';

        $consulta->where(function (Builder $parte) use ($termo, $busca): void {
            $parte->where('inscricoes.nome_completo', 'ilike', $termo)
                ->orWhere('inscricoes.email', 'ilike', $termo)
                ->orWhere('inscricoes.codigo_publico', 'ilike', $termo)
                ->orWhereHas('pagamentos', fn (Builder $pagamento) => $pagamento->where('id_externo', $busca));
        });
    }
}
