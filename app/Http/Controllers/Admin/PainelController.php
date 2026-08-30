<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SituacaoEvento;
use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Services\Admin\NumerosDoEvento;
use Illuminate\Http\Request;
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
    public function index(Request $request, NumerosDoEvento $numeros): Response
    {
        $eventos = $this->eventosDisponiveis();

        $evento = $this->eventoEscolhido($request, $eventos->pluck('id')->all());

        return inertia('Admin/Painel', [
            'eventos' => $eventos->map(fn (Evento $item): array => $this->resumoDoEvento($item))->all(),
            'evento' => $evento === null ? null : $this->resumoDoEvento($evento),
            'numeros' => $evento === null ? null : $numeros->paraEvento($evento),
        ]);
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
