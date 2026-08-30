<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cidade;
use App\Models\User;

/**
 * Quem pode mexer no catalogo de cidades.
 *
 * O catalogo e global: uma cidade cadastrada aparece para todos os eventos.
 * Por isso a permissao e uma so — "catalogo.gerenciar" — e vale para ver,
 * criar, alterar e desativar.
 */
class CidadePolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function update(User $usuario, Cidade $cidade): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function delete(User $usuario, Cidade $cidade): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }
}
