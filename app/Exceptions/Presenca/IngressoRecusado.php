<?php

declare(strict_types=1);

namespace App\Exceptions\Presenca;

use App\Models\Ingresso;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * O portao disse nao — e disse por que.
 *
 * Esta excecao nao e um defeito do sistema: e a resposta de negocio "esta
 * pessoa nao entra", e ela precisa chegar inteira ate a tela. Quem esta no
 * portao com uma fila atras nao pode receber "erro ao validar": precisa saber
 * se o codigo nao existe, se o ingresso e de outro evento, se a inscricao foi
 * cancelada ou se alguem ja entrou com aquele ingresso — porque a conversa
 * seguinte com a pessoa da fila e completamente diferente em cada caso.
 *
 * Por isso ela carrega tres coisas: um MOTIVO (valor curto e estavel, que a
 * tela usa para escolher o desenho e que os testes usam para nao depender do
 * texto), uma MENSAGEM em portugues ja pronta para ler em voz alta, e os DADOS
 * do caso — a hora da primeira entrada, quem registrou, o nome do outro
 * evento.
 *
 * Nenhum dado pessoal alem do necessario entra aqui. O nome de quem registrou
 * a entrada anterior e da equipe, nao do participante; e ele existe porque a
 * pergunta seguinte no portao e sempre "quem deixou entrar?".
 */
class IngressoRecusado extends RuntimeException
{
    /** O codigo digitado ou lido nao corresponde a ingresso nenhum. */
    public const NAO_ENCONTRADO = 'nao-encontrado';

    /** O ingresso existe, mas e de outro evento. */
    public const OUTRO_EVENTO = 'outro-evento';

    /** A inscricao deixou de estar confirmada depois de o ingresso nascer. */
    public const INSCRICAO_NAO_CONFIRMADA = 'inscricao-nao-confirmada';

    /** Alguem ja entrou com este ingresso. */
    public const JA_UTILIZADO = 'ja-utilizado';

    /**
     * @param  array<string, mixed>  $dados  o que a tela precisa para explicar a recusa
     */
    private function __construct(
        public readonly string $motivo,
        string $mensagem,
        public readonly array $dados = [],
    ) {
        parent::__construct($mensagem);
    }

    public static function naoEncontrado(): self
    {
        // De proposito a mesma frase para "nunca existiu" e para "digitou
        // errado": distinguir os dois ensinaria, a quem tentasse adivinhar, em
        // qual metade do alfabeto continuar.
        return new self(
            self::NAO_ENCONTRADO,
            'Código não encontrado. Confira os 12 caracteres ou peça o ingresso no celular da pessoa.',
        );
    }

    public static function deOutroEvento(Ingresso $ingresso, string $nomeDoEventoDoIngresso): self
    {
        return new self(
            self::OUTRO_EVENTO,
            sprintf('Este ingresso é do evento %s.', $nomeDoEventoDoIngresso),
            [
                'evento_do_ingresso' => $nomeDoEventoDoIngresso,
                'ingresso_id' => (int) $ingresso->getKey(),
            ],
        );
    }

    /**
     * A inscricao nao esta mais confirmada.
     *
     * O texto muda conforme o caso porque a conversa no portao muda: uma
     * inscricao CANCELADA tem data e alguem para procurar; uma que expirou ou
     * voltou para a fila e outra historia.
     */
    public static function inscricaoNaoConfirmada(Inscricao $inscricao): self
    {
        $cancelada = $inscricao->cancelada_em instanceof Carbon;

        $mensagem = $cancelada
            ? sprintf('A inscrição foi cancelada em %s.', $inscricao->cancelada_em->format('d/m/Y H:i'))
            : sprintf('A inscrição não está confirmada (%s).', $inscricao->situacao->rotulo());

        return new self(
            self::INSCRICAO_NAO_CONFIRMADA,
            $mensagem,
            [
                'situacao_da_inscricao' => $inscricao->situacao->value,
                'situacao_rotulo' => $inscricao->situacao->rotulo(),
                'cancelada_em' => $cancelada ? $inscricao->cancelada_em->format('d/m/Y H:i') : null,
            ],
        );
    }

    public static function jaUtilizado(Ingresso $ingresso): self
    {
        $quando = $ingresso->usado_em instanceof Carbon
            ? $ingresso->usado_em->format('d/m/Y H:i')
            : null;

        $quem = $ingresso->usadoPor?->name;

        $mensagem = $quem === null
            ? sprintf('Entrada já registrada em %s.', $quando ?? 'outro momento')
            : sprintf('Entrada já registrada em %s, por %s.', $quando ?? 'outro momento', $quem);

        return new self(
            self::JA_UTILIZADO,
            $mensagem,
            [
                'usado_em' => $quando,
                'usado_por' => $quem,
                'ingresso_id' => (int) $ingresso->getKey(),
            ],
        );
    }

    /**
     * O pacote que a tela recebe. Sempre com a mesma forma da resposta aceita,
     * para que o componente de resultado nao tenha dois formatos para tratar.
     *
     * @return array{aceito: false, motivo: string, mensagem: string, dados: array<string, mixed>}
     */
    public function paraTela(): array
    {
        return [
            'aceito' => false,
            'motivo' => $this->motivo,
            'mensagem' => $this->getMessage(),
            'dados' => $this->dados,
        ];
    }
}
