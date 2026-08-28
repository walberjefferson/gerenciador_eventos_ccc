<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SituacaoEvento;
use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventoRequest;
use App\Http\Resources\Admin\EstruturaDoEventoResource;
use App\Models\Evento;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * O cadastro do evento.
 *
 * Duas telas: a lista com todos os eventos e a ficha de um evento, onde ficam
 * os campos gerais. A programacao (dias, grupos, atividades e conflitos) tem
 * tela propria, porque e outro assunto e outra frequencia de uso.
 *
 * A regra de ouro atravessa tudo: mexer na estrutura de um evento que ja tem
 * gente inscrita e perigoso. Nada aqui apaga em silencio — quando a mudanca
 * fere quem ja se inscreveu, a tela recusa e explica o caminho certo.
 */
class EventoController extends Controller
{
    use RegistraAuditoria;

    public function index(): Response
    {
        $this->authorize('viewAny', Evento::class);

        return inertia('Admin/Eventos/Index', [
            'eventos' => Evento::query()
                ->withCount(['inscricoes'])
                ->orderByDesc('data_inicio')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Evento $evento): array => [
                    'id' => $evento->id,
                    'nome' => $evento->nome,
                    'slug' => $evento->slug,
                    'situacao' => $evento->situacao->value,
                    'situacao_rotulo' => $evento->situacao->rotulo(),
                    'data_inicio' => $evento->data_inicio->toDateString(),
                    'data_fim' => $evento->data_fim->toDateString(),
                    'capacidade' => $evento->capacidade,
                    'vagas_ocupadas' => $evento->vagasOcupadas(),
                    'valor_centavos' => $evento->valor_centavos,
                    'inscricoes' => $evento->inscricoes_count,
                ])
                ->all(),
            'situacoes' => $this->situacoes(),
            'sucesso' => session('sucesso'),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Evento::class);

        return inertia('Admin/Eventos/Formulario', [
            'evento' => null,
            'situacoes' => $this->situacoes(),
        ]);
    }

    public function store(EventoRequest $request): RedirectResponse
    {
        $this->authorize('create', Evento::class);

        $evento = Evento::create($request->dadosDoEvento());

        $this->auditarCriacao($evento, 'evento');

        return to_route('admin.eventos.estrutura', $evento)
            ->with('sucesso', "Evento {$evento->nome} cadastrado. Agora monte a programação.");
    }

    public function edit(Evento $evento): Response
    {
        $this->authorize('update', $evento);

        return inertia('Admin/Eventos/Formulario', [
            'evento' => [
                'id' => $evento->id,
                'nome' => $evento->nome,
                'slug' => $evento->slug,
                'descricao' => $evento->descricao,
                'local' => $evento->local,
                'local_detalhe' => $evento->local_detalhe,
                'data_inicio' => $evento->data_inicio->toDateString(),
                'data_fim' => $evento->data_fim->toDateString(),
                'inscricoes_abrem_em' => $evento->inscricoes_abrem_em->format('Y-m-d\TH:i'),
                'inscricoes_fecham_em' => $evento->inscricoes_fecham_em->format('Y-m-d\TH:i'),
                'capacidade' => $evento->capacidade,
                'valor_centavos' => $evento->valor_centavos,
                'moeda' => $evento->moeda,
                'prazo_pagamento_minutos' => $evento->prazo_pagamento_minutos,
                'situacao' => $evento->situacao->value,
                'regulamento' => $evento->regulamento,
                'versao_termos' => $evento->versao_termos,
                'contato_email' => $evento->contato_email,
                'contato_telefone' => $evento->contato_telefone,
                'vagas_ocupadas' => $evento->vagasOcupadas(),
                'inscricoes_ativas' => $evento->inscricoes()->ativas()->count(),
            ],
            'situacoes' => $this->situacoes(),
        ]);
    }

    public function update(EventoRequest $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $antes = $evento->getRawOriginal();

        $evento->update($request->dadosDoEvento());

        $this->auditarAlteracao($evento, $antes, 'evento');

        return back()->with('sucesso', 'Evento atualizado.');
    }

    /**
     * A programacao do evento: dias, grupos, atividades e conflitos, tudo numa
     * tela so, porque so junto isso faz sentido de ler.
     */
    public function estrutura(Evento $evento): Response
    {
        $this->authorize('view', $evento);

        return inertia('Admin/Eventos/Estrutura', (new EstruturaDoEventoResource($evento))->paraTela()
            + ['sucesso' => session('sucesso')]);
    }

    public function destroy(Evento $evento): RedirectResponse
    {
        $this->authorize('delete', $evento);

        $inscricoes = $evento->inscricoes()->count();

        if ($inscricoes > 0) {
            return back()->withErrors([
                'exclusao' => "Este evento não pode ser excluído porque tem {$inscricoes} inscrição(ões). "
                    .'Apagá-lo levaria junto o histórico de quem se inscreveu e o registro dos pagamentos. '
                    .'Mude a situação para "Cancelado" ou "Finalizado".',
            ]);
        }

        $nome = $evento->nome;

        $evento->delete();

        $this->auditarRemocao($evento, 'evento');

        return to_route('admin.eventos.index')->with('sucesso', "Evento {$nome} excluído.");
    }

    /**
     * As situacoes possiveis, com o rotulo que a pessoa le na tela.
     *
     * @return array<int, array{valor: string, rotulo: string}>
     */
    private function situacoes(): array
    {
        return array_map(
            fn (SituacaoEvento $situacao): array => [
                'valor' => $situacao->value,
                'rotulo' => $situacao->rotulo(),
            ],
            SituacaoEvento::cases(),
        );
    }
}
