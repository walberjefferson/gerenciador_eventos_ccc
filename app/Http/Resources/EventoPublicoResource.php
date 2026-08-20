<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SituacaoEvento;
use App\Models\Evento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * O evento como ele chega na tela publica.
 *
 * Nao expoe configuracoes internas nem os contadores vagas_reservadas e
 * vagas_confirmadas: a tela recebe vagas_disponiveis ja calculado e a
 * explicacao pronta de por que as inscricoes estao fechadas, quando estao.
 * Quem decide continua sendo o servidor.
 *
 * @mixin Evento
 */
class EventoPublicoResource extends JsonResource
{
    /**
     * Sem o envelope "data": os props do Inertia chegam direto como evento.*.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vagasDisponiveis = $this->vagasDisponiveis();
        $inscricoesAbertas = $this->inscricoesEstaoAbertas() && $this->temVagaDisponivel();

        return [
            'codigo_publico' => $this->codigo_publico,
            'nome' => $this->nome,
            'slug' => $this->slug,
            'descricao' => $this->descricao,
            'banner_url' => $this->urlDoBanner(),
            'data_inicio' => $this->data_inicio->toDateString(),
            'data_fim' => $this->data_fim->toDateString(),
            'periodo_rotulo' => $this->periodoEmPalavras(),
            'inscricoes_abrem_em' => $this->inscricoes_abrem_em->toIso8601String(),
            'inscricoes_fecham_em' => $this->inscricoes_fecham_em->toIso8601String(),
            'valor_centavos' => $this->valor_centavos,
            'moeda' => $this->moeda,
            'capacidade' => $this->capacidade,
            'vagas_disponiveis' => $vagasDisponiveis,
            'esgotado' => ! $this->temVagaDisponivel(),
            'situacao' => $this->situacao->value,
            'situacao_rotulo' => $this->situacao->rotulo(),
            'inscricoes_abertas' => $inscricoesAbertas,
            'motivo_inscricoes_fechadas' => $inscricoesAbertas ? null : $this->motivoEmPalavras(),
            'regulamento' => $this->regulamento,
            'versao_termos' => $this->versao_termos,
            'contato_email' => $this->contato_email,
            'contato_telefone' => $this->contato_telefone,
            'dias' => DiaEventoResource::collection($this->whenLoaded('diasEvento')),
        ];
    }

    private function urlDoBanner(): ?string
    {
        $caminho = $this->banner_caminho;

        if ($caminho === null || $caminho === '') {
            return null;
        }

        if (str_starts_with($caminho, 'http://') || str_starts_with($caminho, 'https://')) {
            return $caminho;
        }

        return Storage::disk('public')->url($caminho);
    }

    private function periodoEmPalavras(): string
    {
        if ($this->data_inicio->isSameDay($this->data_fim)) {
            return 'Dia '.$this->data_inicio->format('d/m/Y');
        }

        return 'De '.$this->data_inicio->format('d/m/Y').' a '.$this->data_fim->format('d/m/Y');
    }

    /**
     * Explica, em uma frase, por que nao da para se inscrever agora. A tela
     * mostra este texto no lugar do botao.
     */
    private function motivoEmPalavras(): string
    {
        $agora = Carbon::now();

        if ($this->situacao === SituacaoEvento::Publicado || $this->inscricoes_abrem_em > $agora) {
            return 'As inscrições ainda não começaram. Elas abrem em '
                .$this->inscricoes_abrem_em->format('d/m/Y').' às '
                .$this->inscricoes_abrem_em->format('H:i').'.';
        }

        if (! $this->temVagaDisponivel()) {
            return 'Todas as vagas deste evento já foram preenchidas.';
        }

        if ($this->inscricoes_fecham_em < $agora || $this->situacao === SituacaoEvento::InscricoesEncerradas) {
            return 'O prazo para se inscrever terminou em '
                .$this->inscricoes_fecham_em->format('d/m/Y').' às '
                .$this->inscricoes_fecham_em->format('H:i').'.';
        }

        if ($this->situacao === SituacaoEvento::Finalizado) {
            return 'Este evento já aconteceu.';
        }

        return 'As inscrições estão fechadas neste momento.';
    }
}
