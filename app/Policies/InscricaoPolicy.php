<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inscricao;
use App\Models\User;

/**
 * Quem pode ver e quem pode agir sobre uma inscricao.
 *
 * Ver a lista e uma coisa; cancelar a inscricao de alguem ou reconhecer um
 * pagamento que ninguem viu entrar e outra bem diferente. Por isso cada acao
 * tem a sua permissao, e a mais delicada — a confirmacao manual — e exclusiva
 * do administrador (DA-13).
 *
 * Ver UMA inscricao pede, alem da permissao, estar dentro do alcance de setor
 * de quem pede (RN-S9). A regra do alcance nao mora aqui: ela mora inteira em
 * ComprovantePagamentoPolicy, e este arquivo apenas pergunta a ela. Sem isso, o
 * recorte da lista seria contornavel trocando o numero na URL da ficha — que e
 * a primeira coisa que qualquer pessoa tenta.
 */
class InscricaoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('inscricoes.ver');
    }

    public function view(User $usuario, Inscricao $inscricao): bool
    {
        return $usuario->can('inscricoes.ver')
            && ComprovantePagamentoPolicy::alcancaInscricao($usuario, $inscricao);
    }

    public function exportar(User $usuario): bool
    {
        return $usuario->can('inscricoes.exportar');
    }

    public function cancelar(User $usuario, Inscricao $inscricao): bool
    {
        return $usuario->can('inscricoes.cancelar');
    }

    public function confirmarManualmente(User $usuario, Inscricao $inscricao): bool
    {
        return $usuario->can('pagamentos.confirmar-manual');
    }
}
