<?php

declare(strict_types=1);

namespace App\Exceptions\Inscricoes;

/**
 * Ja existe uma inscricao ativa da mesma pessoa neste evento (RN-11).
 *
 * Nasce da traducao de uma violacao de unicidade do banco: e o banco, e nao
 * uma consulta previa, que garante a regra sob concorrencia.
 */
class InscricaoDuplicadaException extends InscricaoInvalidaException
{
    public static function porEmail(): self
    {
        return new self(['email' => ['Já existe uma inscrição ativa com este e-mail neste evento.']]);
    }

    public static function porDocumento(): self
    {
        return new self(['documento' => ['Já existe uma inscrição ativa com este CPF neste evento.']]);
    }
}
