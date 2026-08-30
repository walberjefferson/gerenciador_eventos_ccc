<?php

declare(strict_types=1);

namespace App\Services\Comunicacao;

use App\Enums\TipoComunicacao;
use App\Models\ComunicacaoEnviada;
use App\Models\Inscricao;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quem garante que a mesma mensagem nao chega duas vezes.
 *
 * O jeito obvio de resolver isso seria perguntar antes ("ja mandei?") e enviar
 * se a resposta fosse nao. Esse jeito nao funciona: dois processos podem
 * perguntar ao mesmo tempo, os dois ouvem "ainda nao", e a pessoa recebe duas
 * copias. Quem arbitra precisa ser o banco.
 *
 * Entao a ordem aqui e ao contrario do intuitivo:
 *
 *   1. grava o registro de envio, dentro de uma transacao;
 *   2. so depois manda a mensagem;
 *   3. se o banco recusar o registro por ja existir, encerra em silencio —
 *      alguem chegou primeiro, e nao ha nada de errado nisso.
 *
 * Se o envio falhar, a transacao volta atras e o registro some junto: a
 * proxima tentativa da fila encontra o caminho livre e manda de novo. O
 * registro so sobrevive se a mensagem de fato entrou no caminho de entrega.
 */
class RegistrarEnvio
{
    /**
     * Executa o envio no maximo uma vez por (inscricao, tipo, canal).
     *
     * @param  Closure(): void  $envio  o que fazer para a mensagem sair
     * @return bool true se esta chamada foi a que enviou; false se ja tinha sido enviada antes
     */
    public function umaVezPor(
        Inscricao $inscricao,
        TipoComunicacao $tipo,
        string $destino,
        Closure $envio,
        string $canal = ComunicacaoEnviada::CANAL_EMAIL,
    ): bool {
        try {
            DB::transaction(function () use ($inscricao, $tipo, $destino, $canal, $envio): void {
                ComunicacaoEnviada::query()->create([
                    'inscricao_id' => $inscricao->getKey(),
                    'tipo' => $tipo->value,
                    'canal' => $canal,
                    'destino' => $destino,
                    'enviada_em' => Carbon::now(),
                ]);

                $envio();
            });
        } catch (UniqueConstraintViolationException) {
            // Ja existe registro desta mensagem para esta inscricao. Nao e
            // erro: e exatamente a protecao funcionando.
            return false;
        }

        return true;
    }

    /**
     * Se a mensagem ja saiu alguma vez. Serve para consulta e para reduzir
     * trabalho inutil (por exemplo, nao carregar dados de quem ja recebeu o
     * lembrete) — nunca como unica protecao contra duplicidade.
     */
    public function jaEnviada(
        Inscricao $inscricao,
        TipoComunicacao $tipo,
        string $canal = ComunicacaoEnviada::CANAL_EMAIL,
    ): bool {
        return ComunicacaoEnviada::query()
            ->where('inscricao_id', $inscricao->getKey())
            ->where('tipo', $tipo->value)
            ->where('canal', $canal)
            ->exists();
    }
}
