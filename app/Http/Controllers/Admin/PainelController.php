<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SituacaoEvento;
use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\WebhookPagamento;
use App\Services\Admin\NumerosDoEvento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * O painel do organizador: como esta o evento, num relance.
 *
 * O painel apenas le. Nenhuma regra de inscricao ou de pagamento passa por
 * aqui: os numeros vem de consultas agregadas sobre o que o dominio ja
 * decidiu.
 *
 * A tela e de um evento por vez, com seletor. Somar dois eventos diferentes
 * num numero so nao responde pergunta nenhuma.
 */
class PainelController extends Controller
{
    /**
     * A porta de entrada do lado administrativo: quem digita "/admin" cai
     * aqui, e daqui vai para a tela que a sua permissao abre.
     *
     * ATE A FASE ANTERIOR isto era um `Route::redirect('/', 'painel')` fixo, e
     * ele funcionava porque todo mundo que entrava tinha "painel.ver". Com o
     * papel "portaria" isso deixou de ser verdade: o voluntario do portao
     * entraria pelo endereco mais obvio do sistema e receberia um 403 na cara,
     * sem nenhuma pista de que existe uma tela para ele.
     *
     * O redirecionamento fixo tinha ainda um segundo defeito, este mais
     * silencioso: por ser criado dentro do grupo `admin.`, ele herdava o
     * prefixo de nome e virava uma rota administrativa SEM exigencia de
     * permissao nenhuma — o `AutorizacaoTest` acusa exatamente isso. Ao virar
     * uma rota de verdade, ele passa a cobrar as permissoes dos dois destinos
     * possiveis, e quem nao tem nenhuma das duas nao tem o que fazer no painel
     * mesmo.
     *
     * A ordem importa: quem tem as duas permissoes — administrador e
     * organizador — vai para o painel, porque e a tela que responde a pergunta
     * de quem administra. A portaria e o destino de quem SO tem o portao.
     */
    public function entrada(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        if ($usuario?->can('painel.ver') === true) {
            return redirect()->route('admin.painel');
        }

        if ($usuario?->can('presenca.registrar') === true) {
            return redirect()->route('admin.portaria.index');
        }

        // A rota ja cobra uma das duas permissoes; chegar aqui significa que o
        // middleware mudou e alguem sem destino nenhum passou. Melhor a porta
        // fechada do que um desvio para uma tela que responderia 403.
        abort(403);
    }

    public function index(Request $request, NumerosDoEvento $numeros): Response
    {
        $eventos = $this->eventosDisponiveis();

        $evento = $this->eventoEscolhido($request, $eventos->pluck('id')->all());

        return inertia('Admin/Painel', [
            'eventos' => $eventos->map(fn (Evento $item): array => $this->resumoDoEvento($item))->all(),
            'evento' => $evento === null ? null : $this->resumoDoEvento($evento),
            'numeros' => $evento === null ? null : $numeros->paraEvento($evento),
            'avisos_do_provedor' => $this->avisosDoProvedor($request),
        ]);
    }

    /**
     * O ultimo aviso que o provedor de pagamento mandou — para o sistema
     * inteiro, e nao para o evento escolhido no seletor.
     *
     * Aviso de provedor nao pertence a evento nenhum: ele fala de uma cobranca,
     * que pertence a uma inscricao. A pergunta que este cartao responde — "o
     * provedor ainda esta chamando?" — nao muda quando se troca o evento.
     *
     * Devolve null para quem nao pode abrir a tela dos avisos: mostrar o cartao
     * a quem receberia 403 ao clicar nele seria ensinar a ignorar o painel. E,
     * de quebra, o painel continua custando tres consultas para quem organiza.
     *
     * @return array{ultimo: array{recebido_em: string|null, minutos_atras: int|null, situacao: string, situacao_rotulo: string, gateway: string}|null}|null
     */
    private function avisosDoProvedor(Request $request): ?array
    {
        if ($request->user()?->can('pagamentos.avisos-ver') !== true) {
            return null;
        }

        // Uma consulta, uma linha: o mais recente. Contar ou listar aviso aqui
        // seria fazer no painel o trabalho da tela que existe para isso.
        $aviso = WebhookPagamento::query()
            ->orderByDesc('recebido_em')
            ->orderByDesc('id')
            ->first(['id', 'gateway', 'situacao', 'recebido_em']);

        if ($aviso === null) {
            // Nunca chegou aviso nenhum, e isso e normal em sistema recem
            // publicado ou rodando com o provedor simulado. Quem escreve a
            // frase e a tela; aqui so se diz que nao ha o que contar.
            return ['ultimo' => null];
        }

        return [
            'ultimo' => [
                'recebido_em' => $aviso->recebido_em?->format('d/m/Y H:i'),
                // O intervalo vai em minutos, e nao em frase pronta: a frase em
                // portugues depende do idioma configurado, o numero nao.
                'minutos_atras' => $aviso->recebido_em === null
                    ? null
                    : max(0, (int) $aviso->recebido_em->diffInMinutes(Carbon::now())),
                'situacao' => $aviso->situacao->value,
                'situacao_rotulo' => $aviso->situacao->rotulo(),
                'gateway' => $aviso->gateway,
            ],
        ];
    }

    /**
     * Os eventos que o painel deixa escolher.
     *
     * Rascunho fica de fora: evento que ainda nao foi publicado nao tem
     * inscricao nem dinheiro para acompanhar.
     *
     * @return Collection<int, Evento>
     */
    private function eventosDisponiveis()
    {
        return Evento::query()
            ->where('situacao', '!=', SituacaoEvento::Rascunho->value)
            ->orderByDesc('data_inicio')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * O evento pedido na URL ou, na falta dele, o mais recente da lista.
     *
     * @param  array<int, int>  $idsPermitidos
     */
    private function eventoEscolhido(Request $request, array $idsPermitidos): ?Evento
    {
        $pedido = $request->integer('evento');

        if ($pedido > 0 && in_array($pedido, $idsPermitidos, true)) {
            return Evento::query()->find($pedido);
        }

        $primeiro = $idsPermitidos[0] ?? null;

        return $primeiro === null ? null : Evento::query()->find($primeiro);
    }

    /**
     * O cartao de identificacao do evento, com os contadores do evento inteiro.
     *
     * @return array{id: int, nome: string, slug: string, situacao: string, situacao_rotulo: string, capacidade: int|null, vagas_reservadas: int, vagas_confirmadas: int, vagas_restantes: int|null, valor_centavos: int}
     */
    private function resumoDoEvento(Evento $evento): array
    {
        $ocupadas = $evento->vagas_reservadas + $evento->vagas_confirmadas;

        return [
            'id' => $evento->id,
            'nome' => $evento->nome,
            'slug' => $evento->slug,
            'situacao' => $evento->situacao->value,
            'situacao_rotulo' => $evento->situacao->rotulo(),
            'capacidade' => $evento->capacidade,
            'vagas_reservadas' => $evento->vagas_reservadas,
            'vagas_confirmadas' => $evento->vagas_confirmadas,
            'vagas_restantes' => $evento->capacidade === null ? null : max(0, $evento->capacidade - $ocupadas),
            'valor_centavos' => $evento->valor_centavos,
        ];
    }
}
