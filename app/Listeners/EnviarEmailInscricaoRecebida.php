<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\TipoComunicacao;
use App\Events\InscricaoCriada;
use App\Mail\InscricaoRecebidaMail;
use App\Services\Comunicacao\MensagensDaInscricao;
use App\Services\Comunicacao\RegistrarEnvio;
use App\Services\Inscricoes\GeradorLinkDeAcesso;

/**
 * Avisa a pessoa de que a inscricao entrou e mostra como pagar.
 *
 * O anuncio InscricaoCriada e disparado depois que a transacao fecha, entao
 * quando este ouvinte roda a inscricao ja existe de verdade no banco.
 *
 * O conteudo da mensagem vem de MensagensDaInscricao — o mesmo lugar de onde o
 * reenvio administrativo a tira. O motivo esta escrito la.
 */
class EnviarEmailInscricaoRecebida extends OuvinteDeComunicacao
{
    public function __construct(
        RegistrarEnvio $registrar,
        GeradorLinkDeAcesso $links,
        private readonly MensagensDaInscricao $mensagens,
    ) {
        parent::__construct($registrar, $links);
    }

    public function handle(InscricaoCriada $evento): void
    {
        $inscricao = $evento->inscricao->loadMissing('evento');

        $this->enviar(
            $inscricao,
            TipoComunicacao::InscricaoRecebida,
            fn (): InscricaoRecebidaMail => $this->mensagens->inscricaoRecebida($inscricao),
        );
    }
}
