<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TipoComunicacao;
use App\Events\InscricaoConfirmada;
use App\Mail\PagamentoConfirmadoMail;
use App\Services\Comunicacao\MensagensDaInscricao;
use App\Services\Comunicacao\RegistrarEnvio;
use App\Services\Inscricoes\GeradorLinkDeAcesso;

/**
 * Manda o comprovante quando o dinheiro e reconhecido — com o ingresso dentro.
 *
 * Serve tanto para o pagamento reconhecido pelo provedor quanto para a
 * confirmacao feita a mao pela organizacao: para quem se inscreveu, o fato e o
 * mesmo — a vaga esta garantida. De onde veio o dinheiro fica registrado no
 * pagamento, que e onde essa informacao faz diferenca.
 *
 * O CONTEUDO da mensagem nao mora mais aqui: ele mora em MensagensDaInscricao,
 * porque agora ha dois caminhos ate o participante — este, automatico, e o
 * reenvio pedido na ficha da inscricao. Dois lugares montando o mesmo e-mail
 * sao dois lugares que um dia vao discordar.
 */
class EnviarEmailPagamentoConfirmado extends OuvinteDeComunicacao
{
    public function __construct(
        RegistrarEnvio $registrar,
        GeradorLinkDeAcesso $links,
        private readonly MensagensDaInscricao $mensagens,
    ) {
        parent::__construct($registrar, $links);
    }

    public function handle(InscricaoConfirmada $evento): void
    {
        $inscricao = $evento->inscricao->loadMissing(['evento', 'atividades', 'ingresso']);

        // O ingresso ja existe quando esta mensagem e montada: quem o emite e
        // um ouvinte do MESMO anuncio, registrado antes deste e rodando fora da
        // fila (AppServiceProvider).
        $this->enviar(
            $inscricao,
            TipoComunicacao::PagamentoConfirmado,
            fn (): PagamentoConfirmadoMail => $this->mensagens->pagamentoConfirmado($inscricao, $evento->pagamento),
        );
    }
}
