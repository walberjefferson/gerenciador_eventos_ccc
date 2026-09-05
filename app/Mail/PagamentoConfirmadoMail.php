<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\TipoComunicacao;
use App\Services\Ingressos\GeradorDeCodigo;
use App\Services\Ingressos\GeradorQrCodeIngresso;
use Illuminate\Mail\Mailables\Content;

/**
 * O comprovante: "pagamento confirmado, sua inscricao esta garantida".
 *
 * E a mensagem que a pessoa vai guardar e, se precisar, mostrar na entrada.
 * Por isso traz o codigo publico da inscricao, o valor, a data do pagamento,
 * as atividades escolhidas e — desde que o ingresso existe — o CODIGO DO
 * INGRESSO com o respectivo QR Code. Nada alem disso. O codigo publico
 * sozinho nao abre nada: as paginas exigem link assinado.
 *
 * O DESENHO DO QR NAO VIAJA NA FILA. O que a mensagem carrega e o codigo, em
 * texto; os bytes do PNG sao gerados na hora de montar o conteudo. Guardar
 * binario numa propriedade do Mailable quebraria o enfileiramento: a carga do
 * trabalho e convertida para JSON, e byte de imagem nao e texto valido.
 */
class PagamentoConfirmadoMail extends EmailDaInscricao
{
    /**
     * @param  list<string>  $atividades
     * @param  string|null  $codigoIngresso  o codigo cru do ingresso, ou null quando ainda nao ha ingresso
     */
    public function __construct(
        public readonly string $nome,
        public readonly string $evento,
        public readonly string $valor,
        public readonly string $pagoEm,
        public readonly string $codigo,
        public readonly array $atividades,
        public readonly string $link,
        public readonly ?string $codigoIngresso = null,
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
                // Em grupos de quatro, porque quem le isso num celular na fila
                // do portao vai precisar ditar ou digitar esses caracteres.
                //
                // O NOME DA VARIAVEL DA VIEW NAO PODE SER "codigoIngresso": o
                // Laravel joga as propriedades publicas do Mailable POR CIMA
                // do que vai em "with", e a propriedade de mesmo nome guarda o
                // codigo cru. O e-mail sairia com os doze caracteres colados,
                // sem que nada acusasse o engano.
                'codigoIngressoFormatado' => $this->codigoIngresso === null
                    ? null
                    : GeradorDeCodigo::formatar($this->codigoIngresso),
                // Os bytes do PNG. A view os embute como anexo inline (CID) —
                // ver o comentario na propria view sobre por que nao e "data:".
                'qrIngresso' => $this->codigoIngresso === null
                    ? null
                    : app(GeradorQrCodeIngresso::class)->png($this->codigoIngresso),
            ],
        );
    }
}
