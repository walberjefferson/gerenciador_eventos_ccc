<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SituacaoEvento;
use App\Http\Resources\EventoEmDestaqueResource;
use App\Models\Evento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A porta da rua: a primeira tela de quem abre o endereco do sistema.
 *
 * Quem chega aqui veio de um link no WhatsApp, esta no celular e decide em
 * segundos se aquilo e o lugar certo. Entao a home responde tres perguntas —
 * qual e o evento, quando e, e como se inscrever — e encaminha para a vitrine,
 * que e quem tem programacao, regulamento e formulario. Nada disso e repetido
 * aqui.
 *
 * Esta tela nao decide nada de negocio: quem sabe o que e "inscricoes abertas"
 * e o proprio Evento, pelo scope comInscricoesAbertas. Duas versoes da mesma
 * regra discordariam no pior momento.
 */
class HomeController extends Controller
{
    /**
     * O texto que a pessoa le quando nao ha nenhuma inscricao aberta.
     */
    private const AVISO_SEM_INSCRICOES = 'No momento não há inscrições abertas.';

    /**
     * Uma consulta so, com as colunas que a tela usa.
     *
     * A home e a pagina mais acessada do sistema e a primeira que um pico de
     * acesso encontra. Por isso ela nao carrega dias, grupos nem atividades: a
     * arvore do evento e assunto da vitrine. E nao tem cache (D-79) — cache sem
     * medicao que o justifique so acrescenta uma fonte de verdade defasada.
     */
    public function __invoke(): Response
    {
        $momento = Carbon::now();

        // Um SELECT que traz os dois grupos de uma vez: os que estao com
        // inscricao aberta agora e os que ainda vao abrir. Separar em duas
        // consultas seria duas idas ao banco para montar uma tela so.
        $eventos = Evento::query()
            ->select([
                'id',
                'nome',
                'slug',
                'capacidade',
                'vagas_reservadas',
                'vagas_confirmadas',
                'valor_centavos',
                'descricao',
                'local',
                'data_inicio',
                'data_fim',
                'inscricoes_abrem_em',
                'inscricoes_fecham_em',
                'situacao',
            ])
            ->where(function (Builder $consulta) use ($momento): void {
                $consulta
                    ->where(fn (Builder $abertos) => $abertos->comInscricoesAbertas($momento))
                    ->orWhere(fn (Builder $futuros) => $futuros
                        ->whereIn('situacao', [
                            SituacaoEvento::Publicado->value,
                            SituacaoEvento::InscricoesAbertas->value,
                        ])
                        ->where('inscricoes_abrem_em', '>', $momento));
            })
            ->orderBy('data_inicio')
            ->orderBy('slug')
            ->get();

        // O corte e o mesmo do scope, feito de novo em memoria sobre o que ja
        // veio do banco: um evento publicado cuja janela ainda nao comecou nao
        // e "aberto" — ele e "proximo", e nunca ganha botao de inscricao.
        $abertos = $eventos->filter(fn (Evento $evento): bool => $evento->inscricoesEstaoAbertas($momento))->values();

        $futuros = $eventos
            ->reject(fn (Evento $evento): bool => $evento->inscricoesEstaoAbertas($momento))
            ->sortBy('inscricoes_abrem_em')
            ->values();

        // Nunca "o primeiro do banco": ordem sem criterio e defeito esperando
        // acontecer (DA-38). O destaque e o de data de inicio mais proxima.
        $destaque = $abertos->first();

        // Os dias so do destaque, e nunca dos outros.
        //
        // A home continua sendo a pagina mais acessada e a primeira que um pico
        // de acesso encontra: carregar a arvore de todos os eventos abertos
        // seria pagar por uma informacao que a tela nem mostra. Sao duas
        // consultas a mais — os dias e os grupos deles —, e so quando ha um
        // evento em destaque. As ATIVIDADES continuam de fora: a home resume o
        // dia, ela nao repete a programacao da vitrine.
        if ($destaque instanceof Evento) {
            $destaque->load([
                'diasEvento' => fn ($consulta) => $consulta->select(['id', 'evento_id', 'nome', 'descricao', 'data', 'posicao'])->orderBy('posicao'),
                'diasEvento.gruposAtividades' => fn ($consulta) => $consulta->select(['id', 'dia_evento_id', 'nome'])->orderBy('posicao'),
            ]);
        }

        $proximo = $futuros->first();

        return Inertia::render('Home', [
            'destaque' => $destaque === null ? null : new EventoEmDestaqueResource($destaque),
            'outros_abertos' => EventoEmDestaqueResource::collection($abertos->slice(1)->values())->resolve(),
            'proximo' => $proximo === null ? null : new EventoEmDestaqueResource($proximo),
            'aviso_sem_inscricoes' => $destaque === null ? self::AVISO_SEM_INSCRICOES : null,
        ]);
    }
}
