<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TipoComunicacao;
use App\Events\InscricaoConfirmada;
use App\Mail\EmailDaInscricao;
use App\Mail\PagamentoConfirmadoMail;

/**
 * Manda o comprovante quando o dinheiro e reconhecido — com o ingresso dentro.
 *
 * Serve tanto para o pagamento reconhecido pelo provedor quanto para a
 * confirmacao feita a mao pela organizacao: para quem se inscreveu, o fato e o
 * mesmo — a vaga esta garantida. De onde veio o dinheiro fica registrado no
 * pagamento, que e onde essa informacao faz diferenca.
 */
class EnviarEmailPagamentoConfirmado extends OuvinteDeComunicacao
{
    public function handle(InscricaoConfirmada $evento): void
    {
        $inscricao = $evento->inscricao->loadMissing(['evento', 'atividades', 'ingresso']);
        $pagamento = $evento->pagamento;

        $atividades = $inscricao->atividades
            ->map(fn ($atividade): string => (string) $atividade->nome)
            ->values()
            ->all();

        $this->enviar($inscricao, TipoComunicacao::PagamentoConfirmado, fn (): PagamentoConfirmadoMail => new PagamentoConfirmadoMail(
            nome: EmailDaInscricao::primeiroNome((string) $inscricao->nome_completo),
            evento: (string) $inscricao->evento->nome,
            valor: EmailDaInscricao::moeda((int) ($pagamento->valor_centavos ?? $inscricao->valor_centavos)),
            pagoEm: EmailDaInscricao::momento($pagamento?->pago_em ?? $inscricao->confirmada_em),
            codigo: (string) $inscricao->codigo_publico,
            atividades: $atividades,
            link: $this->links->para($inscricao),
            // O ingresso ja existe quando esta mensagem e montada: quem o
            // emite e um ouvinte do MESMO anuncio, registrado antes deste e
            // rodando fora da fila (AppServiceProvider). Se ainda assim ele
            // faltar, a mensagem sai sem o ingresso em vez de nao sair — o
            // comprovante do pagamento nao pode depender do desenho de um QR.
            codigoIngresso: $inscricao->ingresso?->codigo,
        ));
    }
}
