<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Ingressos\EmitirIngresso;
use App\Events\InscricaoConfirmada;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O ingresso nasce no mesmo instante em que a inscricao vira confirmada.
 *
 * Ouve InscricaoConfirmada, que e o ponto unico por onde passam tanto a
 * confirmacao automatica (aviso do provedor e reconciliacao) quanto a
 * declarada a mao pela organizacao. Para quem se inscreveu o fato e o mesmo —
 * a vaga esta paga —, e o ingresso e a prova disso.
 *
 * DUAS DECISOES QUE PARECEM DETALHE E NAO SAO:
 *
 * 1. Este ouvinte NAO vai para a fila, e e o primeiro registrado para este
 *    anuncio (AppServiceProvider). Ele roda ali mesmo, antes de o e-mail de
 *    pagamento confirmado ser enfileirado — e por isso o codigo do ingresso ja
 *    existe quando a mensagem e montada. Se ele tambem fosse para a fila, a
 *    ordem entre os dois trabalhos seria sorte, e um dia a pessoa receberia o
 *    comprovante sem o ingresso dentro.
 *
 * 2. Ele engole a propria falha, registrando-a no log. Nao e descuido: neste
 *    ponto o dinheiro JA foi reconhecido e a transacao JA fechou. Deixar a
 *    excecao subir faria o trabalho do webhook parecer que falhou e ser
 *    repetido, sem que a confirmacao pudesse acontecer de novo — trocaria um
 *    ingresso faltando por um pagamento com aparencia de erro. O conserto de
 *    um ingresso que nao nasceu tem nome e endereco: o comando
 *    `ingressos:emitir-pendentes`.
 */
class EmitirIngressoDaInscricao
{
    public function __construct(private readonly EmitirIngresso $emitirIngresso) {}

    public function handle(InscricaoConfirmada $evento): void
    {
        try {
            ($this->emitirIngresso)($evento->inscricao);
        } catch (Throwable $falha) {
            Log::error('Nao consegui emitir o ingresso da inscricao confirmada.', [
                'inscricao_id' => $evento->inscricao->getKey(),
                'codigo_publico' => $evento->inscricao->codigo_publico,
                'excecao' => $falha->getMessage(),
            ]);
        }
    }
}
