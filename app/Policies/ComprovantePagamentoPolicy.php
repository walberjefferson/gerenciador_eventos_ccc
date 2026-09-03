<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cidade;
use App\Models\ComprovantePagamento;
use App\Models\Inscricao;
use App\Models\User;

/**
 * Quem pode ver e quem pode conferir um comprovante de pagamento.
 *
 * **Este arquivo e o lugar unico do escopo por setor (RN-S9).** A regra e uma
 * frase: quem confere so alcanca inscricao do proprio setor. Ela e curta, e
 * exatamente por isso e perigosa — uma frase curta e facil de reescrever em
 * cada controller, e basta um lugar esquecer para que o responsavel do Setor A
 * abra o comprovante de alguem do Setor B trocando um numero na URL.
 *
 * Por isso as duas perguntas que o escopo responde moram aqui, publicas, e sao
 * chamadas de fora: `alcancaTodosOsSetores()` e `setoresDe()`. Elas nao sao
 * "metodos de policy" no sentido do Gate, e nao estao aqui por elegancia —
 * estao aqui para que exista um unico arquivo a mudar no dia em que a regra
 * mudar, e um unico arquivo a ler no dia em que alguem duvidar dela.
 *
 * O escopo NUNCA vem do navegador. O setor nao e parametro, nao e filtro e nao
 * e campo escondido de formulario: ele e lido de `cidades.responsavel_id`, no
 * servidor, a cada pedido.
 */
class ComprovantePagamentoPolicy
{
    /**
     * Esta pessoa e recortada por setor?
     *
     * So uma gente e: quem confere comprovante e NAO pode declarar pagamento na
     * mao — quer dizer, o responsavel de setor. As duas perguntas juntas
     * descrevem o papel sem citar o nome dele, e isso e proposital: nome de
     * papel muda, e no dia em que mudar este arquivo continuaria certo.
     *
     * A segunda metade da condicao e o que mantem TODO O RESTO do sistema
     * exatamente como estava (RN-S12): o administrador confere e declara, entao
     * nao e recortado; o organizador nao tem nenhuma das duas permissoes, entao
     * tambem nao e — a lista de inscricoes dele continua sendo a de sempre.
     *
     * Estreitar o setor de quem ja pode confirmar qualquer pagamento na mao
     * seria proteger uma janela com a porta aberta ao lado, e ainda daria a
     * falsa impressao de recorte.
     */
    public static function estaRecortadoPorSetor(User $usuario): bool
    {
        return $usuario->can('pagamentos.conferir-comprovante')
            && ! $usuario->can('pagamentos.confirmar-manual');
    }

    /**
     * Quem enxerga TODOS os setores — que e todo mundo, menos o responsavel de
     * setor.
     */
    public static function alcancaTodosOsSetores(User $usuario): bool
    {
        return ! self::estaRecortadoPorSetor($usuario);
    }

    /**
     * Os setores pelos quais esta pessoa responde.
     *
     * Lista vazia quer dizer "nenhum", e nenhum quer dizer que ela nao alcanca
     * inscricao nenhuma — nao que ela alcanca todas. A diferenca entre esses
     * dois sentidos e a diferenca entre um recorte e um vazamento.
     *
     * @return array<int, int>
     */
    public static function setoresDe(User $usuario): array
    {
        return Cidade::query()
            ->where('responsavel_id', $usuario->getKey())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Esta inscricao esta dentro do alcance desta pessoa?
     *
     * O setor da inscricao vem pelo grupo de participantes, que foi o que ela
     * escolheu no formulario — o mesmo caminho que o filtro da lista percorre.
     */
    public static function alcancaInscricao(User $usuario, Inscricao $inscricao): bool
    {
        if (self::alcancaTodosOsSetores($usuario)) {
            return true;
        }

        $setor = $inscricao->setor();

        if (! $setor instanceof Cidade) {
            // Inscricao sem setor so e alcancada por quem alcanca tudo. Nao ha
            // responsavel a quem ela pertenca, e "de ninguem" nao pode virar
            // "de qualquer um".
            return false;
        }

        return in_array((int) $setor->getKey(), self::setoresDe($usuario), true);
    }

    /**
     * Abrir a fila de conferencia.
     *
     * A permissao basta para ABRIR a tela; o que ela mostra ja vem recortado
     * pelo setor. Quem nao responde por setor nenhum e nao alcanca tudo abre
     * uma fila vazia — o que e a resposta certa, e nao um erro.
     */
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('pagamentos.conferir-comprovante');
    }

    /**
     * Abrir (ou baixar) UM comprovante.
     *
     * Aqui o escopo e obrigatorio: pedir pela URL direta o comprovante de outro
     * setor recebe 403, e nao uma lista filtrada (RN-S11).
     */
    public function view(User $usuario, ComprovantePagamento $comprovante): bool
    {
        if (! $usuario->can('pagamentos.conferir-comprovante')) {
            return false;
        }

        $inscricao = $comprovante->relationLoaded('inscricao')
            ? $comprovante->inscricao
            : $comprovante->inscricao()->first();

        return $inscricao instanceof Inscricao
            && self::alcancaInscricao($usuario, $inscricao);
    }

    /**
     * Aceitar ou recusar.
     *
     * Mesmo alcance de ver: quem pode abrir o comprovante do proprio setor pode
     * decidir sobre ele. Se conferir exigisse mais do que ver, existiria gente
     * lendo o comprovante de alguem sem nunca poder resolve-lo — e a fila
     * pararia esperando outra pessoa.
     */
    public function conferir(User $usuario, ComprovantePagamento $comprovante): bool
    {
        return $this->view($usuario, $comprovante);
    }
}
