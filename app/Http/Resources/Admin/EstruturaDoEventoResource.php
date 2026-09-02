<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Atividade;
use App\Models\ConflitoAtividade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use Illuminate\Support\Facades\DB;

/**
 * A programacao inteira de um evento, no formato que a tela de cadastro le.
 *
 * Alem dos campos de cada peca, cada linha carrega dois numeros que a tela usa
 * para decidir se pode oferecer o botao de excluir: quantas vagas estao
 * ocupadas e quantas pessoas ja escolheram aquilo. Sem esses numeros, a tela
 * ofereceria um botao que o servidor recusaria — e ninguem gosta de botao que
 * mente.
 */
class EstruturaDoEventoResource
{
    public function __construct(private readonly Evento $evento) {}

    /**
     * @return array<string, mixed>
     */
    public function paraTela(): array
    {
        $this->evento->load(['diasEvento.gruposAtividades.atividades' => fn ($consulta) => $consulta->orderBy('posicao')->orderBy('id')]);

        $escolhas = $this->escolhasPorAtividade();

        return [
            'evento' => [
                'id' => $this->evento->id,
                'nome' => $this->evento->nome,
                'slug' => $this->evento->slug,
                'situacao_rotulo' => $this->evento->situacao->rotulo(),
                'data_inicio' => $this->evento->data_inicio->toDateString(),
                'data_fim' => $this->evento->data_fim->toDateString(),
                'inscricoes_ativas' => $this->evento->inscricoes()->ativas()->count(),
                // Quantos dias a programação tem. É por este número que a tela
                // decide começar com a seção de dias recolhida: evento de um
                // dia só — o caso do evento recém-criado, que já nasce com o
                // Dia 1 pronto — não precisa da tabela de dias aberta na cara
                // de quem só quer cadastrar as atividades.
                'dias_total' => $this->evento->diasEvento->count(),
            ],
            'dias' => $this->evento->diasEvento
                ->map(fn (DiaEvento $dia): array => [
                    'id' => $dia->id,
                    'nome' => $dia->nome,
                    'descricao' => $dia->descricao,
                    'data' => $dia->data->toDateString(),
                    'posicao' => $dia->posicao,
                    'ativo' => $dia->ativo,
                    'grupos' => $dia->gruposAtividades
                        ->sortBy(['posicao', 'id'])
                        ->values()
                        ->map(fn (GrupoAtividade $grupo): array => [
                            'id' => $grupo->id,
                            'dia_evento_id' => $grupo->dia_evento_id,
                            'nome' => $grupo->nome,
                            'descricao' => $grupo->descricao,
                            'obrigatorio' => $grupo->obrigatorio,
                            'min_selecoes' => $grupo->min_selecoes,
                            'max_selecoes' => $grupo->max_selecoes,
                            'posicao' => $grupo->posicao,
                            'ativo' => $grupo->ativo,
                            'atividades' => $grupo->atividades
                                ->map(fn (Atividade $atividade): array => [
                                    'id' => $atividade->id,
                                    'grupo_atividade_id' => $atividade->grupo_atividade_id,
                                    'nome' => $atividade->nome,
                                    'descricao' => $atividade->descricao,
                                    // Nulos quando a atividade não tem hora
                                    // marcada: aqui, ao contrário das telas do
                                    // participante, a ausência é informação de
                                    // trabalho e a listagem a mostra como "—".
                                    'comeca_em' => $atividade->comeca_em?->format('Y-m-d\TH:i'),
                                    'termina_em' => $atividade->termina_em?->format('Y-m-d\TH:i'),
                                    'capacidade' => $atividade->capacidade,
                                    'idade_minima' => $atividade->idade_minima,
                                    'idade_maxima' => $atividade->idade_maxima,
                                    'posicao' => $atividade->posicao,
                                    'ativo' => $atividade->ativo,
                                    'vagas_ocupadas' => $atividade->vagasOcupadas(),
                                    'escolhida_por' => $escolhas[$atividade->id] ?? 0,
                                ])
                                ->all(),
                        ])
                        ->all(),
                ])
                ->all(),
            'conflitos' => $this->conflitos(),
            'atividades' => $this->atividadesDoEvento($escolhas),
        ];
    }

    /**
     * Quantas inscricoes escolheram cada atividade deste evento.
     *
     * @return array<int, int>
     */
    private function escolhasPorAtividade(): array
    {
        $ids = $this->idsDasAtividades();

        if ($ids === []) {
            return [];
        }

        return DB::table('inscricoes_atividades')
            ->select('atividade_id', DB::raw('count(*) as total'))
            ->whereIn('atividade_id', $ids)
            ->groupBy('atividade_id')
            ->pluck('total', 'atividade_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function idsDasAtividades(): array
    {
        return $this->evento->diasEvento
            ->flatMap(fn (DiaEvento $dia) => $dia->gruposAtividades
                ->flatMap(fn (GrupoAtividade $grupo) => $grupo->atividades->pluck('id')))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Os pares que ninguem pode escolher junto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conflitos(): array
    {
        $ids = $this->idsDasAtividades();

        if ($ids === []) {
            return [];
        }

        return ConflitoAtividade::query()
            ->with(['atividadeA', 'atividadeB'])
            ->whereIn('atividade_a_id', $ids)
            ->whereIn('atividade_b_id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (ConflitoAtividade $conflito): array => [
                'id' => $conflito->id,
                'atividade_a_id' => $conflito->atividade_a_id,
                'atividade_b_id' => $conflito->atividade_b_id,
                'atividade_a' => $conflito->atividadeA?->nome ?? '',
                'atividade_b' => $conflito->atividadeB?->nome ?? '',
                'motivo' => $conflito->motivo,
            ])
            ->all();
    }

    /**
     * A lista plana de atividades, para os seletores de conflito.
     *
     * @param  array<int, int>  $escolhas
     * @return array<int, array<string, mixed>>
     */
    private function atividadesDoEvento(array $escolhas): array
    {
        $lista = [];

        foreach ($this->evento->diasEvento as $dia) {
            foreach ($dia->gruposAtividades->sortBy(['posicao', 'id']) as $grupo) {
                foreach ($grupo->atividades as $atividade) {
                    $lista[] = [
                        'id' => $atividade->id,
                        'nome' => $dia->nome.' · '.$atividade->nome,
                        'escolhida_por' => $escolhas[$atividade->id] ?? 0,
                    ];
                }
            }
        }

        return $lista;
    }
}
