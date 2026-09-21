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

    /**
     * Corrigir os dados de uma inscricao ja criada.
     *
     * Cobra o alcance de setor, e nao apenas a permissao, pela mesma razao de
     * `view()`: quem so enxerga o proprio setor na lista nao pode alcancar a
     * inscricao de outro trocando o numero na URL. Aqui a razao e ainda mais
     * forte — ver de fora e ruim, escrever por cima e pior.
     */
    public function editar(User $usuario, Inscricao $inscricao): bool
    {
        return $usuario->can('inscricoes.editar')
            && ComprovantePagamentoPolicy::alcancaInscricao($usuario, $inscricao);
    }

    /**
     * Mandar de novo, para o participante, uma mensagem da inscricao dele.
     *
     * Mesmo alcance de setor da edicao: a mensagem sai com o link assinado da
     * inscricao, entao reenviar e, na pratica, entregar acesso a ela. Quem nao
     * pode abrir a ficha nao pode despachar o conteudo dela para uma caixa de
     * entrada.
     */
    public function reenviarComunicacao(User $usuario, Inscricao $inscricao): bool
    {
        return $usuario->can('inscricoes.reenviar-comunicacao')
            && ComprovantePagamentoPolicy::alcancaInscricao($usuario, $inscricao);
    }

    public function confirmarManualmente(User $usuario, Inscricao $inscricao): bool
    {
        return $usuario->can('pagamentos.confirmar-manual');
    }
}
