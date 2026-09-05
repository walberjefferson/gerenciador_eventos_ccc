<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Responsavel;
use App\Models\User;

/**
 * Quem pode mexer no cadastro de responsaveis.
 *
 * A permissao e a mesma do resto do catalogo — "catalogo.gerenciar" —, e ela e
 * a mesma por escolha: responsavel e catalogo, como setor e grupo. Separar
 * quem cadastra chave Pix de quem cadastra setor seria uma decisao de
 * seguranca, e nao de organizacao de menu; no dia em que a organizacao quiser
 * essa separacao, e aqui que ela nasce.
 */
class ResponsavelPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function update(User $usuario, Responsavel $responsavel): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function delete(User $usuario, Responsavel $responsavel): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }
}
