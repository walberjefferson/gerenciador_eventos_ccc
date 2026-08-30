<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Evento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * O evento como ele aparece na porta da rua.
 *
 * E de proposito muito menor que o EventoPublicoResource: a home apresenta e
 * encaminha, entao ela precisa de nome, quando e, um resumo e o endereco da
 * vitrine. Nao ha aqui id interno, contagem de inscritos nem vaga restante —
 * numero de vaga na entrada vira pressao sem contexto, e fica errado no segundo
 * seguinte. O identificador publico e o slug.
 *
 * @mixin Evento
 */
class EventoEmDestaqueResource extends JsonResource
{
    /**
     * Quantos caracteres da descricao cabem num resumo de entrada.
     */
    private const LIMITE_DO_RESUMO = 180;

    /**
     * Sem o envelope "data": os props do Inertia chegam direto.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'nome' => $this->nome,
            'slug' => $this->slug,
            'resumo' => $this->resumo(),
            'data_inicio' => $this->data_inicio->toDateString(),
            'data_fim' => $this->data_fim->toDateString(),
            'periodo_rotulo' => $this->periodoEmPalavras(),
            // Onde. A terceira das tres perguntas que alguem faz antes de
            // decidir se vai — e a unica que ate agora so tinha resposta no
            // grupo do WhatsApp.
            'local' => $this->local,
            // O valor vai para a home porque o convite principal o mostra
            // dentro do proprio botao: quem toca em "Fazer inscricao" ja sabe
            // quanto custa, e nao descobre duas telas adiante. E um campo do
            // proprio evento — nao custa consulta nem agregacao.
            'valor_centavos' => (int) $this->valor_centavos,
            'situacao' => $this->situacao->value,
            'situacao_rotulo' => $this->situacao->rotulo(),
            // Quem decide se da para se inscrever e o servidor, sempre. A tela
            // so obedece: sem isto verdadeiro, nao existe botao de inscricao.
            'inscricoes_abertas' => $this->inscricoesEstaoAbertas(),
            'abre_em_rotulo' => $this->aberturaEmPalavras(),
            // Quanto tempo ainda ha. E o unico fato desta tela que muda com o
            // relogio, e o que faz alguem agir hoje em vez de "depois eu vejo".
            'prazo_rotulo' => $this->prazoEmPalavras(),
            // Vaga restante na porta de entrada. Entrou na Etapa 26, e ANTES
            // dela era proibida — a razao da proibicao era que o numero vira
            // pressao sem contexto e desatualiza no segundo seguinte. A razao
            // de ter mudado e o dono do produto ter decidido, vendo a tela
            // pronta, que a barra de ocupacao vale mais do que esse risco. Sao
            // dois campos do proprio evento e um contador que ele ja mantem:
            // nenhuma consulta a mais.
            'vagas_disponiveis' => $this->vagasDisponiveis(),
            'capacidade' => $this->capacidade === null ? null : (int) $this->capacidade,
            // Os dois dias, e o que acontece em cada um. So vem preenchido
            // para o evento em destaque: os demais nao carregam a relacao, e
            // "whenLoaded" garante que a chave nem aparece para eles.
            'dias' => $this->whenLoaded('diasEvento', fn (): array => $this->diasEmResumo()),
        ];
    }

    /**
     * A descricao encurtada. Nao existe coluna de resumo, e criar uma seria
     * mudanca de dominio — nesta fase, mostra-se menos.
     */
    private function resumo(): ?string
    {
        $descricao = $this->descricao;

        if ($descricao === null || trim($descricao) === '') {
            return null;
        }

        return Str::limit(trim($descricao), self::LIMITE_DO_RESUMO);
    }

    private function periodoEmPalavras(): string
    {
        if ($this->data_inicio->isSameDay($this->data_fim)) {
            return 'Dia '.$this->data_inicio->format('d/m/Y');
        }

        return 'De '.$this->data_inicio->format('d/m/Y').' a '.$this->data_fim->format('d/m/Y');
    }

    /**
     * Quando as inscricoes abrem. So faz sentido para quem ainda vai abrir:
     * para o evento ja aberto, a frase seria sobre o passado.
     */
    private function aberturaEmPalavras(): ?string
    {
        if ($this->inscricoesEstaoAbertas()) {
            return null;
        }

        return 'As inscrições abrem em '
            .$this->inscricoes_abrem_em->format('d/m/Y').' às '
            .$this->inscricoes_abrem_em->format('H:i').'.';
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

    /**
     * Os dias do evento, do jeito que a porta da rua os mostra.
     *
     * E um RESUMO, e nao a arvore do evento: o nome do dia, quando ele e, e uma
     * frase dizendo o que acontece. As atividades, as vagas de cada uma e as
     * regras de escolha continuam sendo assunto da vitrine — repeti-las aqui
     * faria a home refazer a consulta da tela seguinte.
     *
     * A frase e a descricao do dia quando ela existe; quando nao, os nomes dos
     * grupos daquele dia, que e o mais proximo disso que o cadastro tem.
     */
    private function diasEmResumo(): array
    {
        return $this->diasEvento
            ->sortBy('posicao')
            ->map(fn ($dia): array => [
                'id' => $dia->id,
                'nome' => $dia->nome,
                'quando' => $this->diaEmPalavras($dia->data),
                'resumo' => $dia->descricao ?? $dia->gruposAtividades->pluck('nome')->implode(' · '),
            ])
            ->values()
            ->all();
    }

    /**
     * "Sábado · 17/10" — o dia da semana por extenso mais a data curta.
     *
     * O dia da semana entra porque e por ele que alguem sabe se consegue ir; a
     * data sozinha obriga a abrir o calendario para descobrir isso.
     */
    private function diaEmPalavras(Carbon $data): string
    {
        $semana = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

        return Str::ucfirst($semana[(int) $data->dayOfWeek]).' · '.$data->format('d/m');
    }
}
