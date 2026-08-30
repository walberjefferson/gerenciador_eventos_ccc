<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Evento;
use App\Models\User;

/**
 * Quem pode cadastrar e alterar a estrutura de um evento.
 *
 * Vale para o evento e para tudo o que pendura nele: dias, grupos de
 * atividades, atividades e conflitos. Sao partes da mesma coisa e nao fazia
 * sentido inventar uma permissao para cada uma.
 */
class EventoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('eventos.gerenciar');
    }

    public function view(User $usuario, Evento $evento): bool
    {
        return $usuario->can('eventos.gerenciar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('eventos.gerenciar');
    }

    public function update(User $usuario, Evento $evento): bool
    {
        return $usuario->can('eventos.gerenciar');
    }

    public function delete(User $usuario, Evento $evento): bool
    {
        return $usuario->can('eventos.gerenciar');
    }
}
