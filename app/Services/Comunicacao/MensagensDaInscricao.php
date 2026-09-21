<?php

declare(strict_types=1);

namespace App\Services\Comunicacao;

use App\Mail\EmailDaInscricao;
use App\Mail\InscricaoRecebidaMail;
use App\Mail\LinkDeAcessoInscricao;
use App\Mail\PagamentoConfirmadoMail;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Services\Inscricoes\GeradorLinkDeAcesso;

/**
 * Monta as mensagens de uma inscricao — e e o unico lugar que as monta.
 *
 * Ate aqui, o conteudo de cada e-mail era escrito dentro do ouvinte que o
 * disparava. Isso funcionava enquanto existia UM caminho para cada mensagem: o
 * automatico. Agora existem dois — o automatico e o reenvio pedido por gente na
 * ficha da inscricao —, e conteudo escrito em dois lugares e conteudo que um
 * dia vai divergir. O modo de falhar nao e bonito: a organizacao reenviaria o
 * comprovante e a pessoa receberia uma mensagem diferente da que todo mundo
 * recebeu, sem que nada acusasse a diferenca.
 *
 * Aqui so se MONTA a mensagem. Quem decide se ela pode sair, para onde vai e se
 * ja saiu antes nao e este arquivo: e o RegistrarEnvio, no caminho automatico, e
 * a Action de reenvio, no caminho administrativo.
 */
class MensagensDaInscricao
{
    public function __construct(private readonly GeradorLinkDeAcesso $links) {}

    /**
     * "Recebemos a sua inscricao — falta o pagamento."
     */
    public function inscricaoRecebida(Inscricao $inscricao): InscricaoRecebidaMail
    {
        $inscricao->loadMissing('evento');

        return new InscricaoRecebidaMail(
            nome: EmailDaInscricao::primeiroNome((string) $inscricao->nome_completo),
            evento: (string) $inscricao->evento?->nome,
            valor: EmailDaInscricao::moeda((int) $inscricao->valor_centavos),
            prazo: EmailDaInscricao::momento($inscricao->prazo_pagamento),
            link: $this->links->para($inscricao),
        );
    }

    /**
     * O comprovante do pagamento, com o ingresso dentro.
     *
     * O pagamento vem de fora quando quem chama ja o tem em maos (e o caso do
     * ouvinte da confirmacao, que o recebe no proprio anuncio). Sem ele, o que
     * vale e o que esta gravado na inscricao: valor cobrado e momento da
     * confirmacao. Os dois caminhos precisam existir porque o reenvio
     * administrativo acontece muito depois do anuncio, e ali nao ha anuncio
     * nenhum de onde tirar o pagamento.
     */
    public function pagamentoConfirmado(Inscricao $inscricao, ?Pagamento $pagamento = null): PagamentoConfirmadoMail
    {
        $inscricao->loadMissing(['evento', 'atividades', 'ingresso']);

        $atividades = $inscricao->atividades
            ->map(fn ($atividade): string => (string) $atividade->nome)
            ->values()
            ->all();

        return new PagamentoConfirmadoMail(
            nome: EmailDaInscricao::primeiroNome((string) $inscricao->nome_completo),
            evento: (string) $inscricao->evento?->nome,
            valor: EmailDaInscricao::moeda((int) ($pagamento?->valor_centavos ?? $inscricao->valor_centavos)),
            pagoEm: EmailDaInscricao::momento($pagamento?->pago_em ?? $inscricao->confirmada_em),
            codigo: (string) $inscricao->codigo_publico,
            atividades: $atividades,
            link: $this->links->para($inscricao),
            // Quando o ingresso falta, a mensagem sai sem ele em vez de nao
            // sair: o comprovante do pagamento nao pode depender do desenho de
            // um QR.
            codigoIngresso: $inscricao->ingresso?->codigo,
        );
    }

    /**
     * O caminho de volta para a propria inscricao.
     *
     * A mensagem foi feita para listar TODAS as inscricoes de um e-mail — e por
     * isso ela recebe uma lista. Aqui a lista tem um item so, porque quem pede
     * pela ficha esta olhando uma inscricao concreta e e dela que a pessoa
     * precisa do link.
     */
    public function linkDeAcesso(Inscricao $inscricao): LinkDeAcessoInscricao
    {
        $inscricao->loadMissing('evento');

        return new LinkDeAcessoInscricao(
            [[
                'evento' => (string) ($inscricao->evento?->nome ?? 'Evento'),
                'situacao' => $inscricao->situacao->rotulo(),
                'link' => $this->links->para($inscricao),
            ]],
            $this->links->validadeEmDias(),
        );
    }
}
