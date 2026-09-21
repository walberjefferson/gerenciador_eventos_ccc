<?php

declare(strict_types=1);

namespace App\Actions\Comunicacao;

use App\Enums\SituacaoInscricao;
use App\Models\Inscricao;
use App\Services\Comunicacao\MensagensDaInscricao;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Manda de novo, para o participante, uma mensagem da inscricao dele.
 *
 * **O REENVIO IGNORA A TRAVA DE ENVIO UNICO, E ISSO E O PONTO.** O
 * RegistrarEnvio existe para que a automacao nunca mande duas vezes a mesma
 * coisa: o anuncio pode se repetir, a fila pode tentar de novo, e a pessoa nao
 * pode receber duas copias por causa disso. Aqui e o contrario — quem clica e
 * gente, olhando para uma pessoa que diz nao ter recebido, e a segunda copia e
 * exatamente o que se esta pedindo.
 *
 * Por isso o envio sai direto, sem passar por RegistrarEnvio, e **nao grava em
 * comunicacoes_enviadas**: a unicidade daquela tabela recusaria a linha de
 * qualquer forma, e forcar um registro ali faria o historico de envios
 * automaticos mentir. Quem registra o reenvio e a auditoria, que e onde ficam
 * as acoes de gente.
 *
 * CADA MENSAGEM SO FAZ SENTIDO NA SITUACAO DELA (RN-A2). Mandar "falta o
 * pagamento" para quem ja pagou, ou o comprovante para quem nao pagou, seria
 * pior do que nao mandar nada: a pessoa acredita no que esta escrito. A tela
 * nem oferece a acao fora de hora, e este arquivo recusa de novo — a tela pode
 * estar velha, e a URL pode ser digitada a mao.
 */
class ReenviarComunicacao
{
    /** O comprovante do pagamento, com o ingresso dentro. */
    public const CONFIRMACAO = 'confirmacao';

    /** "Recebemos a sua inscricao — falta o pagamento." */
    public const INSTRUCOES_DE_PAGAMENTO = 'instrucoes-de-pagamento';

    /** O caminho de volta para a propria inscricao. */
    public const LINK_DE_ACESSO = 'link-de-acesso';

    public function __construct(private readonly MensagensDaInscricao $mensagens) {}

    /**
     * Os tres tipos que existem.
     *
     * Eles NAO sao o TipoComunicacao: aquele enum e o vocabulario da tabela
     * comunicacoes_enviadas, e o reenvio nao escreve nela. Alem disso, o link
     * de acesso nunca teve linha la — ele nasceu como resposta a um pedido da
     * propria pessoa, e nao como mensagem automatica do sistema.
     *
     * @return array<int, string>
     */
    public static function tipos(): array
    {
        return [self::CONFIRMACAO, self::INSTRUCOES_DE_PAGAMENTO, self::LINK_DE_ACESSO];
    }

    /**
     * O nome da mensagem, do jeito que quem trabalha no painel a chama.
     */
    public static function rotulo(string $tipo): string
    {
        return match ($tipo) {
            self::CONFIRMACAO => 'Confirmação com o ingresso',
            self::INSTRUCOES_DE_PAGAMENTO => 'Instruções de pagamento',
            self::LINK_DE_ACESSO => 'Link de acesso à inscrição',
            default => $tipo,
        };
    }

    /**
     * Quais mensagens fazem sentido para ESTA inscricao, agora (RN-A2 e RN-A3).
     *
     * Sem e-mail nao ha reenvio nenhum: nao e recusa, e ausencia de destino.
     * Acontece nas inscricoes antigas e nas feitas no balcao por quem nao tinha
     * e-mail para dar.
     *
     * @return array<int, string>
     */
    public static function disponiveisPara(Inscricao $inscricao): array
    {
        if (trim((string) $inscricao->email) === '') {
            return [];
        }

        $disponiveis = [];

        if ($inscricao->situacao === SituacaoInscricao::Confirmada) {
            $disponiveis[] = self::CONFIRMACAO;
        }

        if ($inscricao->situacao === SituacaoInscricao::AguardandoPagamento) {
            $disponiveis[] = self::INSTRUCOES_DE_PAGAMENTO;
        }

        // O link de acesso vale para qualquer inscricao ativa: ele leva a
        // pagina de acompanhamento, que continua util tanto para quem ainda
        // deve quanto para quem ja pagou.
        if ($inscricao->situacao->estaAtiva()) {
            $disponiveis[] = self::LINK_DE_ACESSO;
        }

        return $disponiveis;
    }

    /**
     * Confere a situacao, monta a mensagem e envia.
     *
     * A recusa sai como erro de validacao, e nao como excecao de dominio, por
     * uma razao pratica: ela sempre acontece no campo "tipo" de um formulario, e
     * e assim que a tela sabe onde escrever a frase. O texto e escrito para quem
     * esta no painel.
     *
     * @return string o e-mail para onde a mensagem foi
     *
     * @throws ValidationException
     */
    public function __invoke(Inscricao $inscricao, string $tipo): string
    {
        $destino = trim((string) $inscricao->email);

        if ($destino === '') {
            throw ValidationException::withMessages([
                'tipo' => 'Esta inscrição não tem e-mail cadastrado: não há para onde enviar.',
            ]);
        }

        if (! in_array($tipo, self::disponiveisPara($inscricao), true)) {
            throw ValidationException::withMessages([
                'tipo' => 'Esta mensagem não vale para uma inscrição '
                    .mb_strtolower($inscricao->situacao->rotulo()).'. Atualize a página e veja o que é possível enviar.',
            ]);
        }

        Mail::to($destino)->send($this->montar($inscricao, $tipo));

        return $destino;
    }

    /**
     * A mensagem vem pronta de MensagensDaInscricao — a mesma fonte do envio
     * automatico. E o que garante que o reenvio entregue exatamente o que todo
     * mundo recebeu, e nao uma segunda versao do mesmo texto.
     */
    private function montar(Inscricao $inscricao, string $tipo): Mailable
    {
        return match ($tipo) {
            self::CONFIRMACAO => $this->mensagens->pagamentoConfirmado($inscricao),
            self::INSTRUCOES_DE_PAGAMENTO => $this->mensagens->inscricaoRecebida($inscricao),
            self::LINK_DE_ACESSO => $this->mensagens->linkDeAcesso($inscricao),
            default => throw ValidationException::withMessages([
                'tipo' => 'Esta mensagem não existe.',
            ]),
        };
    }
}
