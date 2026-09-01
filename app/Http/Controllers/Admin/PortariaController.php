<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Presenca\DesfazerPresenca;
use App\Actions\Presenca\RegistrarPresenca;
use App\Enums\SituacaoEvento;
use App\Exceptions\Presenca\IngressoRecusado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ValidarIngressoRequest;
use App\Models\Evento;
use App\Models\Ingresso;
use App\Services\Admin\NumerosDePresenca;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * A tela do portao: conferir ingresso, aceitar quem pode entrar, recusar quem
 * nao pode e desfazer o engano.
 *
 * Ela e a unica tela que o papel "portaria" alcanca, e isso guiou o desenho
 * inteiro: nada aqui mostra lista de inscritos, dado pessoal alem do nome de
 * quem acabou de entrar, dinheiro ou historico. Quem esta no portao ve uma
 * pergunta por vez.
 *
 * **A conferencia responde por redirecionamento, e nao por JSON.** O resultado
 * da leitura volta pela sessao e o Inertia redesenha a mesma tela com o
 * veredito e com os contadores ja atualizados. Uma rota JSON exigiria a tela
 * cuidar de token, de erro de rede e de manter os numeros em dia sozinha — e o
 * portao e justamente o lugar onde o sinal de internet e pior.
 *
 * **Nenhuma regra de presenca mora aqui.** Quem sabe aceitar ou recusar sao as
 * Actions; este controller confere quem pode, valida o que chegou do
 * formulario e conta o resultado em portugues.
 */
class PortariaController extends Controller
{
    public function index(Request $pedido, NumerosDePresenca $presenca): Response
    {
        // A segunda tranca, como no AuditoriaController e pelo mesmo motivo: se
        // um dia esta tela for registrada noutro grupo de rotas sem o
        // middleware, o 403 continua acontecendo.
        abort_unless($pedido->user()?->can('presenca.registrar') === true, 403);

        $eventos = $this->eventosDisponiveis();

        $evento = $this->eventoEscolhido($pedido, $eventos);

        return inertia('Admin/Portaria/Index', [
            'eventos' => $eventos->map(fn (Evento $item): array => $this->resumo($item))->values()->all(),
            'evento' => $evento === null ? null : $this->resumo($evento),
            'numeros' => $evento === null ? null : $presenca->paraEvento($evento),

            // O veredito da ultima leitura, se houve uma. Vem da sessao porque
            // e resposta de uma acao, e nao estado da tela: recarregar a pagina
            // nao pode fazer o "aceito" reaparecer como se alguem tivesse
            // entrado de novo.
            'resultado' => $pedido->session()->get('resultado'),

            // O aviso curto do desfazer, no mesmo formato das outras telas
            // administrativas.
            'sucesso' => $pedido->session()->get('sucesso'),

            // Desfazer NAO e da portaria (ver PapeisSeeder). A tela nem desenha
            // o botao para quem nao pode — oferecer um caminho que o servidor
            // recusaria com 403 ensina a ignorar a tela.
            'pode_desfazer' => $pedido->user()?->can('presenca.desfazer') === true,
        ]);
    }

    /**
     * Confere um ingresso e, se ele passar, registra a entrada.
     *
     * A recusa NAO e tratada como erro de formulario: ela volta como resultado,
     * com motivo proprio, porque no portao "este ingresso ja foi usado as
     * 14h02" e a resposta certa da tela — nao um campo em vermelho.
     */
    public function validar(ValidarIngressoRequest $pedido, RegistrarPresenca $registrar): RedirectResponse
    {
        $responsavel = $pedido->user();

        abort_if($responsavel === null, 403);
        abort_unless($responsavel->can('presenca.registrar'), 403);

        $evento = Evento::query()->findOrFail($pedido->integer('evento_id'));

        try {
            $resultado = $registrar($pedido->codigo(), $evento, $responsavel);
        } catch (IngressoRecusado $recusa) {
            return back()->with('resultado', $recusa->paraTela());
        }

        return back()->with('resultado', $resultado);
    }

    /**
     * Apaga uma entrada registrada por engano.
     *
     * Exige "presenca.desfazer", que a portaria nao tem: quem conserta o engano
     * do portao e quem organiza o evento, que esta no mesmo lugar no mesmo dia
     * e nao tem a fila olhando.
     */
    public function desfazer(Request $pedido, Ingresso $ingresso, DesfazerPresenca $desfazer): RedirectResponse
    {
        $responsavel = $pedido->user();

        abort_if($responsavel === null, 403);
        abort_unless($responsavel->can('presenca.desfazer'), 403);

        $desfez = $desfazer($ingresso, $responsavel);

        // O aviso sai como "sucesso", e nao como um resultado de leitura: o
        // veredito da leitura anterior morre junto com este redirecionamento,
        // que e o que se quer — a tela nao pode continuar mostrando "aceito"
        // logo depois de a entrada ter sido apagada.
        if (! $desfez) {
            return back()->with('sucesso', 'Este ingresso já não tinha entrada registrada: nada foi alterado.');
        }

        return back()->with('sucesso', sprintf(
            'Entrada desfeita. O ingresso %s vale de novo.',
            $ingresso->codigoFormatado(),
        ));
    }

    /**
     * Os eventos que a portaria pode escolher.
     *
     * Rascunho e cancelado ficam de fora: no primeiro ninguem se inscreveu, e
     * no segundo nao ha portao para abrir.
     *
     * @return Collection<int, Evento>
     */
    private function eventosDisponiveis(): Collection
    {
        return Evento::query()
            ->whereNotIn('situacao', [SituacaoEvento::Rascunho->value, SituacaoEvento::Cancelado->value])
            ->orderByDesc('data_inicio')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * O evento pedido na URL ou, na falta dele, o que esta acontecendo HOJE.
     *
     * A pre-selecao pelo dia nao e conveniencia: sem ela, o seletor abriria no
     * evento mais recente da lista — que pode ser o do ano que vem — e o
     * primeiro ingresso lido seria recusado com "este ingresso e do evento
     * tal", no meio da fila, sem ninguem entender por que.
     *
     * @param  Collection<int, Evento>  $eventos
     */
    private function eventoEscolhido(Request $pedido, Collection $eventos): ?Evento
    {
        $pedidoId = $pedido->integer('evento');

        $escolhido = $eventos->firstWhere('id', $pedidoId);

        if ($escolhido instanceof Evento) {
            return $escolhido;
        }

        $hoje = Carbon::now()->startOfDay();

        $emAndamento = $eventos->first(
            fn (Evento $evento): bool => $evento->data_inicio !== null
                && $evento->data_fim !== null
                && $hoje->betweenIncluded($evento->data_inicio->startOfDay(), $evento->data_fim->startOfDay())
        );

        return $emAndamento ?? $eventos->first();
    }

    /**
     * O cartao de identificacao do evento no seletor. So o que a portaria
     * precisa ler — nem capacidade, nem dinheiro.
     *
     * @return array{id: int, nome: string, situacao: string, situacao_rotulo: string, periodo: string}
     */
    private function resumo(Evento $evento): array
    {
        $inicio = $evento->data_inicio?->format('d/m/Y');
        $fim = $evento->data_fim?->format('d/m/Y');

        return [
            'id' => (int) $evento->getKey(),
            'nome' => $evento->nome,
            'situacao' => $evento->situacao->value,
            'situacao_rotulo' => $evento->situacao->rotulo(),
            'periodo' => match (true) {
                $inicio === null => 'sem data',
                $fim === null || $fim === $inicio => (string) $inicio,
                default => $inicio.' a '.$fim,
            },
        ];
    }
}
