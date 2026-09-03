<?php

declare(strict_types=1);

namespace App\Actions\Pagamentos;

use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\CreatePaymentData;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoPagamento;
use App\Exceptions\Pagamentos\SetorSemChavePixException;
use App\Models\Cidade;
use App\Models\Evento;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Pagamentos\MontadorDeBrCodePix;
use Illuminate\Support\Str;

/**
 * Emite a cobranca de uma inscricao.
 *
 * O prazo da cobranca e exatamente o prazo da inscricao: nao pode existir um
 * Pix que ainda aceita pagamento depois de a vaga ter voltado para a fila, nem
 * o contrario.
 *
 * E idempotente: se a inscricao ja tem cobranca aguardando pagamento, devolve
 * a mesma. Isso importa porque o participante pode reenviar o formulario e
 * porque a criacao da inscricao e repetivel pela chave de idempotencia.
 *
 * **Este e o unico lugar do sistema que le a forma de recebimento do evento**
 * (RN-S1). Ela bifurca o caminho aqui e em nenhum outro: ou a cobranca sai pelo
 * provedor, como sempre saiu, ou ela e montada localmente a partir da chave Pix
 * do responsavel pelo setor da pessoa. As duas terminam do mesmo jeito — uma
 * linha em "pagamentos" aguardando pagamento —, e e por isso que o resto do
 * sistema nao precisa saber qual delas aconteceu.
 */
class CriarPagamentoDaInscricao
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly MontadorDeBrCodePix $montador,
    ) {}

    public function __invoke(Inscricao $inscricao): Pagamento
    {
        $jaEmitida = Pagamento::query()
            ->where('inscricao_id', $inscricao->getKey())
            ->pendentes()
            ->first();

        if ($jaEmitida !== null) {
            return $jaEmitida;
        }

        $evento = $inscricao->relationLoaded('evento')
            ? $inscricao->evento
            : $inscricao->evento()->first();

        // A bifurcacao (RN-S2). Ela acontece ANTES de qualquer toque no
        // provedor: no modo setor nenhuma credencial e lida, nenhum certificado
        // e materializado e nenhum pacote sai pela rede.
        if ($evento instanceof Evento && $evento->recebePeloSetor()) {
            return $this->cobrancaDoSetor($inscricao, $evento);
        }

        $resultado = $this->gateway->createPayment(new CreatePaymentData(
            externalReference: (string) $inscricao->codigo_publico,
            amountCents: (int) $inscricao->valor_centavos,
            currency: (string) config('payments.currency', 'BRL'),
            method: MetodoPagamento::Pix->value,
            description: Str::limit('Inscricao no evento '.($evento?->nome ?? ''), 120),
            payerName: (string) $inscricao->nome_completo,
            payerEmail: (string) $inscricao->email,
            payerDocument: (string) $inscricao->documento,
            expiresAt: $inscricao->prazo_pagamento,
            metadata: ['inscricao' => (string) $inscricao->codigo_publico],
        ));

        return Pagamento::create([
            'inscricao_id' => $inscricao->getKey(),
            'gateway' => $this->gateway->name(),
            'id_externo' => $resultado->externalId,
            'metodo' => MetodoPagamento::Pix,
            'valor_centavos' => (int) $inscricao->valor_centavos,
            'situacao' => SituacaoPagamento::deStatusExterno($resultado->status) ?? SituacaoPagamento::Pendente,
            'pix_copia_e_cola' => $resultado->pixPayload,
            'expira_em' => $inscricao->prazo_pagamento,
            // Guardamos so o que ajuda a investigar depois. Nada de dado
            // pessoal do pagador aqui: ele ja esta na inscricao.
            'metadados' => [
                'referencia_externa' => (string) $inscricao->codigo_publico,
                'status_externo' => $resultado->status,
            ],
        ]);
    }

    /**
     * A cobranca montada aqui dentro, pela chave Pix do responsavel do setor.
     *
     * Ela e uma cobranca de verdade na tabela "pagamentos" — com valor, prazo e
     * copia e cola —, mas nao existe do lado de instituicao financeira nenhuma.
     * Por isso:
     *
     * - `gateway` diz 'setor', e nao o nome de um provedor que nao participou;
     * - `id_externo` fica NULO. Inventar um identificador de provedor seria
     *   falsificar historico de dinheiro — e e esse nulo que ja mantem esta
     *   cobranca fora da reconciliacao (que filtra por `whereNotNull`) e fora
     *   do aviso de cancelamento ao provedor.
     *
     * Nada aqui confirma nada: quem reconhece o dinheiro e a pessoa que confere
     * o comprovante, pelo caminho de ConfirmarPagamentoManual (RN-S10).
     */
    private function cobrancaDoSetor(Inscricao $inscricao, Evento $evento): Pagamento
    {
        $setor = $inscricao->setor();

        if (! $setor instanceof Cidade || ! $setor->estaPreparadaParaReceber()) {
            throw new SetorSemChavePixException(
                'O setor desta inscrição ainda não tem chave Pix cadastrada. '
                .'Sem ela não há para onde o pagamento ir: cadastre a chave e o responsável do setor.'
            );
        }

        return Pagamento::create([
            'inscricao_id' => $inscricao->getKey(),
            'gateway' => 'setor',
            'id_externo' => null,
            'metodo' => MetodoPagamento::Pix,
            'valor_centavos' => (int) $inscricao->valor_centavos,
            'situacao' => SituacaoPagamento::Pendente,
            'pix_copia_e_cola' => $this->brCode($inscricao, $setor),
            'expira_em' => $inscricao->prazo_pagamento,
            'metadados' => [
                'origem' => 'setor',
                'setor_id' => (int) $setor->getKey(),
                'setor' => $setor->nome,
                'evento' => $evento->nome,
                // A chave NAO e gravada aqui. Ela mora numa coluna so, em
                // cidades: copiada para dentro do jsonb, ela sobreviveria a
                // uma troca de chave e a cobranca antiga passaria a apontar
                // para uma conta que o setor nao usa mais.
            ],
        ]);
    }

    /**
     * O "copia e cola" que a tela do participante mostra.
     *
     * O codigo da inscricao viaja em dois campos, e por dois motivos (RN-S13):
     * o 26-02 — que os aplicativos de banco mostram como descricao — leva o
     * ULID INTEIRO, com 26 caracteres, porque e a copia que uma pessoa vai ler
     * na hora de conferir; e o 62-05 leva os ultimos 25, que e tudo o que cabe
     * nele. Escrever so no 62-05 entregaria um codigo truncado, e quem confere
     * teria de adivinhar o primeiro caractere.
     *
     * **Nem um nem outro e conciliacao.** Nem todo aplicativo preserva ou
     * mostra esses campos a quem recebe, e nada impede a pessoa de copiar a
     * chave e pagar pela mao, sem ler o QR. Eles ajudam quem confere a achar a
     * inscricao; nenhuma decisao de dinheiro pode depender deles — inclusive o
     * valor do campo 54, que num BR Code estatico e sugestao e nao trava.
     */
    private function brCode(Inscricao $inscricao, Cidade $setor): string
    {
        $codigo = (string) $inscricao->codigo_publico;

        return $this->montador->montar(
            chave: (string) $setor->chave_pix,
            valorCentavos: (int) $inscricao->valor_centavos,
            // O titular e o nome que aparece no aplicativo de quem paga. Sem
            // ele, o nome do setor — que ainda diz mais do que nada.
            nomeDoRecebedor: (string) ($setor->titular_chave_pix ?? $setor->nome),
            cidade: $setor->nome,
            identificador: Str::substr($codigo, -25),
            // "Inscricao" sem acento de proposito: o campo EMV nao aceita
            // acento, e escrever a palavra ja limpa evita que o saneamento
            // decida por nos como ela fica. Ela sai em caixa alta, porque o
            // saneamento e o MESMO do resto do payload — e e essa mesmice que
            // garante a igualdade byte a byte com o codigo anterior.
            descricao: 'Inscricao '.$codigo,
        );
    }
}
