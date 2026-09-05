<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por onde o dinheiro de um evento entra.
 *
 * Sao dois caminhos, e o evento escolhe UM: ou a cobranca sai pelo provedor de
 * pagamento, que emite o Pix e reconhece o pagamento sozinho, ou a pessoa paga
 * direto na chave Pix do responsavel pelo setor dela e um humano confere o
 * comprovante.
 *
 * A escolha e lida em um lugar so — na hora de emitir a cobranca — e em
 * nenhum outro ela decide nada (RN-S1). O padrao e Gateway porque e o que todo
 * evento ja cadastrado faz, e continuar fazendo e requisito (RN-S12).
 */
enum FormaRecebimento: string
{
    /** O provedor emite a cobranca e reconhece o pagamento. O caminho de sempre. */
    case Gateway = 'gateway';

    /** A pessoa paga na chave Pix do responsavel pelo setor; alguem confere. */
    case Setor = 'setor';

    public function rotulo(): string
    {
        return match ($this) {
            self::Gateway => 'Pelo provedor de pagamento',
            self::Setor => 'Pela chave Pix do responsável do setor',
        };
    }

    /**
     * A explicacao que aparece embaixo da escolha, no formulario do evento.
     */
    public function explicacao(): string
    {
        return match ($this) {
            self::Gateway => 'O sistema emite o Pix e reconhece o pagamento sozinho, '
                .'assim que a instituição financeira avisa.',
            self::Setor => 'O participante paga direto na chave Pix do responsável pelo setor dele, '
                .'envia o comprovante pela própria tela, e o responsável confere e confirma.',
        };
    }

    /**
     * Esta forma exige que os setores estejam prontos antes de o evento ser
     * gravado?
     *
     * So a forma "setor" exige, e exige porque um evento que cobra por uma
     * chave que nao existe e uma inscricao que ninguem consegue pagar (RN-S4).
     */
    public function exigeSetorPreparado(): bool
    {
        return $this === self::Setor;
    }

    /**
     * O prazo minimo de pagamento, em minutos, para um evento nesta forma.
     *
     * Cinco minutos bastam para um Pix reconhecido em segundos pelo provedor.
     * Nao bastam para uma transferencia que uma pessoa precisa abrir, olhar e
     * conferir: por isso a forma "setor" exige dois dias (RN-S8).
     */
    public function prazoMinimoEmMinutos(): int
    {
        return match ($this) {
            self::Gateway => 5,
            self::Setor => 2880,
        };
    }

    /**
     * O prazo que o formulario sugere ao trocar para esta forma.
     */
    public function prazoSugeridoEmMinutos(): int
    {
        return match ($this) {
            self::Gateway => 60,
            self::Setor => 10080,
        };
    }
}
