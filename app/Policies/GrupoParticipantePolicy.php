<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GrupoParticipante;
use App\Models\User;

/**
 * Quem pode mexer no catalogo de grupos de participantes.
 *
 * Mesma permissao das cidades: grupo de participante e catalogo global, nao
 * pertence a um evento.
 */
class GrupoParticipantePolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function update(User $usuario, GrupoParticipante $grupo): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }

    public function delete(User $usuario, GrupoParticipante $grupo): bool
    {
        return $usuario->can('catalogo.gerenciar');
    }
}
