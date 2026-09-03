<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RegistraAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CidadeRequest;
use App\Models\Cidade;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * O catalogo de setores.
 *
 * A classe, o Model e a tabela continuam se chamando "cidade": o renome para
 * "setor" vale para a tela e para a URL, nao para o banco. Por isso o
 * parametro se chama `$setor` e o tipo dele e `Cidade`.
 *
 * Lista simples, com uma regra que importa mais que todas as outras: setor que
 * ja esta em uso nao e apagado. A chave estrangeira do banco recusaria de
 * qualquer jeito, mas com uma mensagem que ninguem entende — entao a recusa
 * acontece aqui, em portugues, com a saida certa oferecida junto: desativar.
 *
 * Setor desativado some do formulario publico e continua valendo para quem ja
 * se inscreveu. Nada de historico se perde.
 */
class CidadeController extends Controller
{
    use RegistraAuditoria;

    public function index(): Response
    {
        $this->authorize('viewAny', Cidade::class);

        return inertia('Admin/Catalogo/Setores', [
            'cidades' => Cidade::query()
                ->withCount('gruposParticipantes')
                ->with('responsavel:id,name')
                ->orderBy('uf')
                ->orderBy('nome')
                ->get()
                ->map(fn (Cidade $cidade): array => [
                    'id' => $cidade->id,
                    'nome' => $cidade->nome,
                    'uf' => $cidade->uf,
                    'ativo' => $cidade->ativo,
                    'grupos' => $cidade->grupos_participantes_count,
                    'responsavel_id' => $cidade->responsavel_id,
                    'responsavel_nome' => $cidade->responsavel?->name,
                    // A chave aparece INTEIRA nesta tela, e so nesta e na tela
                    // de pagamento de quem e do setor. Ela nao e segredo — foi
                    // cadastrada para ser mostrada (RN-S3) —, mas quem a le
                    // aqui ja passou por "catalogo.gerenciar".
                    'chave_pix' => $cidade->chave_pix,
                    'titular_chave_pix' => $cidade->titular_chave_pix,
                    'telefone_responsavel' => $cidade->telefone_responsavel,
                    'preparado_para_receber' => $cidade->estaPreparadaParaReceber(),
                ])
                ->all(),
            // As contas que podem responder por um setor. So as ativas: uma
            // conta desativada nao consegue entrar para conferir nada.
            'responsaveis' => User::query()
                ->where('ativo', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $usuario): array => [
                    'id' => (int) $usuario->id,
                    'nome' => $usuario->name,
                    'email' => $usuario->email,
                ])
                ->all(),
            'ufs' => CidadeRequest::UFS,
            'sucesso' => session('sucesso'),
        ]);
    }

    public function store(CidadeRequest $request): RedirectResponse
    {
        $this->authorize('create', Cidade::class);

        $setor = Cidade::create($request->dadosDaCidade());

        // A entidade da auditoria continua sendo 'cidade': ela e a chave do
        // rastro ja gravado, e renomea-la partiria o historico em dois.
        $this->auditarCriacao($setor, 'cidade');

        return back()->with('sucesso', "Setor {$setor->nome} cadastrado.");
    }

    public function update(CidadeRequest $request, Cidade $setor): RedirectResponse
    {
        $this->authorize('update', $setor);

        $antes = $setor->getRawOriginal();

        $setor->update($request->dadosDaCidade());

        $this->auditarAlteracao($setor, $antes, 'cidade');

        return back()->with('sucesso', "Setor {$setor->nome} atualizado.");
    }

    public function destroy(Cidade $setor): RedirectResponse
    {
        $this->authorize('delete', $setor);

        $grupos = $setor->gruposParticipantes()->count();

        if ($grupos > 0) {
            return back()->withErrors([
                'exclusao' => "Este setor não pode ser excluído porque tem {$grupos} grupo(s) de participantes ligado(s) a ele. "
                    .'Desative o setor para que ele pare de aparecer no formulário, sem apagar o histórico de quem já se inscreveu.',
            ]);
        }

        $nome = $setor->nome;

        $setor->delete();

        $this->auditarRemocao($setor, 'cidade');

        return back()->with('sucesso', "Setor {$nome} excluído.");
    }
}
