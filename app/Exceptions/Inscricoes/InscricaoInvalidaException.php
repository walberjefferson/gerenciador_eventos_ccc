<?php

declare(strict_types=1);

namespace App\Exceptions\Inscricoes;

use RuntimeException;

/**
 * Base das recusas de inscricao que o participante precisa entender.
 *
 * Toda mensagem carregada aqui e escrita para quem esta se inscrevendo, nunca
 * para quem programa. A camada HTTP transforma esta excecao em erro de
 * validacao (422); fora da web, a mensagem continua legivel.
 */
abstract class InscricaoInvalidaException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $erros
     */
    public function __construct(protected array $erros)
    {
        parent::__construct($this->primeiraMensagem());
    }

    /**
     * Mensagens agrupadas pelo campo do formulario a que se referem.
     *
     * @return array<string, array<int, string>>
     */
    public function erros(): array
    {
        return $this->erros;
    }

    /**
     * @return array<int, string>
     */
    public function mensagens(): array
    {
        return array_merge(...array_values($this->erros));
    }

    private function primeiraMensagem(): string
    {
        foreach ($this->erros as $mensagens) {
            foreach ($mensagens as $mensagem) {
                return $mensagem;
            }
        }

        return 'Nao foi possivel concluir a inscricao.';
    }
}
