<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResponsavelRequest;
use App\Models\Cidade;
use App\Models\Responsavel;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * O cadastro de quem recebe o Pix dos setores.
 *
 * Ele e catalogo, como setor e grupo: uma lista global que vale para todos os
 * eventos, sob a mesma permissao "catalogo.gerenciar".
 *
 * Duas regras importam mais que as outras, e as duas sao a mesma coisa vista de
 * dois lados (RN-R9):
 *
 * - **responsavel que ja recebeu nao e apagado.** A chave estrangeira de
 *   "pagamentos" recusaria de qualquer jeito, mas com uma mensagem que ninguem
 *   entende — entao a recusa acontece aqui, em portugues, com a saida certa
 *   oferecida junto: desativar;
 * - **desativar tira do sorteio na hora**, sem tocar em cobranca nenhuma ja
 *   emitida. A cobranca antiga continua apontando para quem recebeu, porque foi
 *   ele quem recebeu.
 */
class ResponsavelController extends Controller
{
    use RegistraAuditoria;

    public function index(): Response
    {
        $this->authorize('viewAny', Responsavel::class);

        return inertia('Admin/Catalogo/Responsaveis', [
            'responsaveis' => Responsavel::query()
                ->with(['user:id,name,email', 'setores:id,nome,uf'])
                ->withCount('pagamentos')
                ->orderBy('nome')
                ->get()
                ->map(fn (Responsavel $responsavel): array => [
                    'id' => (int) $responsavel->id,
                    'nome' => $responsavel->nome,
                    // A chave aparece INTEIRA nesta tela, e so aqui e na tela
                    // de pagamento de quem e do setor. Ela nao e segredo — foi
                    // cadastrada para ser mostrada (RN-S3) —, mas quem a le
                    // aqui ja passou por "catalogo.gerenciar".
                    'chave_pix' => $responsavel->chave_pix,
                    'telefone' => $responsavel->telefone,
                    'ativo' => $responsavel->ativo,
                    'user_id' => $responsavel->user_id === null ? null : (int) $responsavel->user_id,
                    'conta_nome' => $responsavel->user?->name,
                    'setores' => $responsavel->setores
                        ->map(fn (Cidade $setor): array => [
                            'id' => (int) $setor->id,
                            'nome' => $setor->nome,
                            'uf' => $setor->uf,
                        ])
                        ->all(),
                    // Quantas cobrancas ja apontaram para esta pessoa. E o
                    // numero que explica, na tela, por que o botao de excluir
                    // some: quem ja recebeu nao se apaga.
                    'cobrancas' => (int) $responsavel->pagamentos_count,
                    'apto' => $responsavel->estaApto(),
                ])
                ->all(),
            'setores' => Cidade::query()
                ->orderBy('uf')
                ->orderBy('nome')
                ->get(['id', 'nome', 'uf', 'ativo'])
                ->map(fn (Cidade $setor): array => [
                    'id' => (int) $setor->id,
                    'nome' => $setor->nome,
                    'uf' => $setor->uf,
                    'ativo' => $setor->ativo,
                ])
                ->all(),
            // As contas que podem ser ligadas a um responsavel. So as ativas:
            // uma conta desativada nao consegue entrar para conferir nada.
            'contas' => User::query()
                ->where('ativo', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $usuario): array => [
                    'id' => (int) $usuario->id,
                    'nome' => $usuario->name,
                    'email' => $usuario->email,
                ])
                ->all(),
            'sucesso' => session('sucesso'),
        ]);
    }

    public function store(ResponsavelRequest $request): RedirectResponse
    {
        $this->authorize('create', Responsavel::class);

        $responsavel = Responsavel::create($request->dadosDoResponsavel());
        $responsavel->setores()->sync($request->setores());

        $this->auditarCriacao($responsavel, 'responsavel');

        return back()->with('sucesso', "Responsável {$responsavel->nome} cadastrado.");
    }

    public function update(ResponsavelRequest $request, Responsavel $responsavel): RedirectResponse
    {
        $this->authorize('update', $responsavel);

        $antes = $responsavel->getRawOriginal();

        $responsavel->update($request->dadosDoResponsavel());

        // O vinculo so e mexido quando a tela mandou a lista. Um formulario que
        // nao fala de setores nao pode desvincular a pessoa de todos eles por
        // omissao — seria tirar o setor inteiro do ar sem ninguem pedir.
        if ($request->has('setores')) {
            $responsavel->setores()->sync($request->setores());
        }

        $this->auditarAlteracao($responsavel, $antes, 'responsavel');

        return back()->with('sucesso', "Responsável {$responsavel->nome} atualizado.");
    }

    public function destroy(Responsavel $responsavel): RedirectResponse
    {
        $this->authorize('delete', $responsavel);

        $cobrancas = $responsavel->pagamentos()->count();

        if ($cobrancas > 0) {
            return back()->withErrors([
                'exclusao' => "Este responsável não pode ser excluído porque {$cobrancas} cobrança(s) já apontam para ele. "
                    .'Desative o responsável para que ele saia do sorteio, sem apagar o registro de quem recebeu cada Pix.',
            ]);
        }

        $nome = $responsavel->nome;

        $responsavel->delete();

        $this->auditarRemocao($responsavel, 'responsavel');

        return back()->with('sucesso', "Responsável {$nome} excluído.");
    }
}
