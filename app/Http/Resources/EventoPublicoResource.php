<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SituacaoEvento;
use App\Models\Evento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            // "17 e 18 de outubro" e, embaixo, "Sábado e domingo, 2026".
            // A data por extenso e o dia da semana respondem coisas
            // diferentes: uma diz quando, a outra diz se a pessoa consegue ir.
            'quando_rotulo' => $this->quandoEmPalavras(),
            'quando_nota' => $this->quandoNota(),
            'local' => $this->local,
            'local_detalhe' => $this->local_detalhe,
            // Listas vazias em vez de nulo: a tela pergunta "tem item?" com
            // `length`, e nao precisa saber a diferenca entre "nunca preenchi"
            // e "apaguei tudo".
            'itens_incluidos' => $this->itens_incluidos ?? [],
            'perguntas_frequentes' => $this->perguntas_frequentes ?? [],
            'inscricoes_abrem_em' => $this->inscricoes_abrem_em->toIso8601String(),
            'inscricoes_fecham_em' => $this->inscricoes_fecham_em->toIso8601String(),
            'prazo_rotulo' => $this->prazoEmPalavras(),
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

    /**
     * Quanto tempo ainda ha para se inscrever, ja escrito em portugues.
     *
     * Conta dias de CALENDARIO, e nao intervalos de 24 horas: quem le "encerram
     * amanha" numa quinta entende sexta, e nao "daqui a 24 horas". Devolve null
     * quando as inscricoes nao estao abertas — ai a frase seria sobre um prazo
     * que nao existe mais, e a etiqueta simplesmente nao aparece.
     */
    private function prazoEmPalavras(): ?string
    {
        if (! $this->inscricoesEstaoAbertas()) {
            return null;
        }

        $dias = (int) Carbon::now()->startOfDay()->diffInDays($this->inscricoes_fecham_em->copy()->startOfDay(), false);

        if ($dias < 0) {
            return null;
        }

        return match ($dias) {
            0 => 'Encerram hoje',
            1 => 'Encerram amanhã',
            default => "Encerram em {$dias} dias",
        };
    }

    private const MESES = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    private const SEMANA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    /**
     * "17 e 18 de outubro" — a data escrita como alguem a fala.
     *
     * Quando os dois dias caem no mesmo mes, o mes aparece uma vez so; quando
     * nao caem, cada dia leva o seu. Um dia so vira "17 de outubro".
     */
    private function quandoEmPalavras(): string
    {
        $inicio = $this->data_inicio;
        $fim = $this->data_fim;

        if ($inicio->isSameDay($fim)) {
            return $inicio->day.' de '.self::MESES[$inicio->month];
        }

        if ($inicio->month === $fim->month && $inicio->year === $fim->year) {
            return $inicio->day.' e '.$fim->day.' de '.self::MESES[$inicio->month];
        }

        return $inicio->day.' de '.self::MESES[$inicio->month].' a '.$fim->day.' de '.self::MESES[$fim->month];
    }

    /**
     * "Sábado e domingo, 2026" — o dia da semana e o ano.
     *
     * O dia da semana e o que faz alguem saber se consegue ir; o ano so aparece
     * aqui porque a linha de cima o deixou de fora para caber.
     */
    private function quandoNota(): string
    {
        $inicio = $this->data_inicio;
        $fim = $this->data_fim;

        $primeiro = Str::ucfirst(self::SEMANA[(int) $inicio->dayOfWeek]);

        if ($inicio->isSameDay($fim)) {
            return $primeiro.', '.$inicio->year;
        }

        return $primeiro.' e '.self::SEMANA[(int) $fim->dayOfWeek].', '.$fim->year;
    }
}
