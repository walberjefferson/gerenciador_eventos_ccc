<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SituacaoInscricao;
use App\Models\Evento;
use Illuminate\Support\Facades\DB;

/**
 * Quantos ja entraram e quantos ainda faltam, por evento.
 *
 * Como o resto dos numeros do painel, aqui so se le — e numa consulta so,
 * agregada pelo banco. Um evento de mil pessoas nao pode virar mil objetos na
 * memoria do PHP para mostrar dois numeros, e as duas contagens saem da mesma
 * varredura: separa-las custaria o dobro para responder a mesma pergunta.
 *
 * A DEFINICAO DOS DOIS NUMEROS E A PARTE QUE IMPORTA:
 *
 * - **Presentes** sao os ingressos com "usado_em" preenchido, entre as
 *   inscricoes CONFIRMADAS deste evento. A condicao da situacao nao e enfeite:
 *   uma inscricao cancelada depois de a pessoa ja ter entrado continuaria
 *   somando no total de presentes, e o numero deixaria de fechar com a lista
 *   de quem tem direito a estar la.
 * - **Faltantes** sao as confirmadas menos os presentes — nunca negativo.
 *
 * Inscricao cancelada, expirada ou em lista de espera nao conta em nenhum dos
 * dois lados: ela nao esta esperada no portao.
 */
class NumerosDePresenca
{
    /**
     * @return array{presentes: int, faltantes: int, confirmadas: int}
     */
    public function paraEvento(Evento $evento): array
    {
        // "left join": a inscricao confirmada que ainda nao tem ingresso
        // emitido — o caso de quem pagou antes desta entrega e espera o
        // backfill — precisa continuar contando como faltante. Com "join"
        // simples ela sumiria dos dois numeros e o total nao fecharia.
        //
        // A soma condicional vai em "case when", e nao no "filter (where ...)"
        // do PostgreSQL, porque nao ha motivo para prender a contagem a um
        // banco especifico quando o padrao custa o mesmo.
        $linha = DB::table('inscricoes')
            ->leftJoin('ingressos', 'ingressos.inscricao_id', '=', 'inscricoes.id')
            ->where('inscricoes.evento_id', $evento->getKey())
            ->where('inscricoes.situacao', SituacaoInscricao::Confirmada->value)
            ->selectRaw('
                count(*) as confirmadas,
                sum(case when ingressos.usado_em is not null then 1 else 0 end) as presentes
            ')
            ->first();

        $confirmadas = (int) ($linha->confirmadas ?? 0);
        $presentes = (int) ($linha->presentes ?? 0);

        return [
            'presentes' => $presentes,
            'faltantes' => max(0, $confirmadas - $presentes),
            'confirmadas' => $confirmadas,
        ];
    }
}
