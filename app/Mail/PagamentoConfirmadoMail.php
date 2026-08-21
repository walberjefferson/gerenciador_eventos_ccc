<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\TipoComunicacao;
use Illuminate\Mail\Mailables\Content;

/**
 * O comprovante: "pagamento confirmado, sua inscricao esta garantida".
 *
 * E a mensagem que a pessoa vai guardar e, se precisar, mostrar na entrada.
 * Por isso traz o codigo publico da inscricao, o valor, a data do pagamento e
 * as atividades escolhidas — e nada alem disso. O codigo publico sozinho nao
 * abre nada: as paginas exigem link assinado.
 */
class PagamentoConfirmadoMail extends EmailDaInscricao
{
    /**
     * @param  list<string>  $atividades
     */
    public function __construct(
        public readonly string $nome,
        public readonly string $evento,
        public readonly string $valor,
        public readonly string $pagoEm,
        public readonly string $codigo,
        public readonly array $atividades,
        public readonly string $link,
    ) {
        parent::__construct(TipoComunicacao::PagamentoConfirmado);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pagamento-confirmado',
            text: 'emails.pagamento-confirmado-texto',
            with: [
                'nome' => $this->nome,
                'evento' => $this->evento,
                'valor' => $this->valor,
                'pagoEm' => $this->pagoEm,
                'codigo' => $this->codigo,
                'atividades' => $this->atividades,
                'link' => $this->link,
            ],
        );
    }
}
