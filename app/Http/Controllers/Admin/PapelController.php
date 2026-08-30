<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Database\Seeders\PapeisSeeder;
use Illuminate\Http\Request;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A janela para o `PapeisSeeder`: o que cada papel alcanca.
 *
 * **Somente leitura, e por decisao do dono do produto.** Nao existe rota para
 * criar, editar nem apagar papel ou permissao. O conjunto nasce no seeder, que
 * e versionado, revisavel em code review e roda igual em todo ambiente
 * (`docker/entrypoint.sh`). Editar isso pela tela faria o mesmo sistema ter
 * conjuntos de permissao diferentes em cada lugar onde estivesse instalado, e
 * um papel mal montado viraria brecha sem ninguem revisar.
 *
 * A tela existe porque a lista de usuarios mostra "administrador" e
 * "organizador" — e quem administra precisa poder responder "o que isso quer
 * dizer, exatamente?" sem abrir codigo. Por isso a matriz vem com o texto em
 * portugues de cada permissao, lido do proprio seeder.
 *
 * Mora sob "usuarios.gerenciar": e a mesma pergunta ("quem pode o que"), vista
 * do outro lado.
 */
class PapelController extends Controller
{
    public function index(Request $pedido): Response
    {
        // A segunda tranca, como no AuditoriaController e pelo mesmo motivo.
        abort_unless($pedido->user()?->can('usuarios.gerenciar') === true, 403);

        // O pacote guarda permissao em cache: sem limpar, a matriz pode
        // mostrar um retrato anterior ao ultimo seeder.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $papeis = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();

        return inertia('Admin/Papeis/Index', [
            'papeis' => $papeis
                ->map(fn (Role $papel): array => [
                    'nome' => $papel->name,
                    'rotulo' => mb_ucfirst($papel->name),
                    'permissoes' => $papel->permissions->pluck('name')->all(),
                    'quantas' => $papel->permissions->count(),
                ])
                ->values()
                ->all(),

            // A ordem e a explicacao vem do seeder, e nao da tabela: e la que
            // cada permissao tem a frase em portugues que diz o que ela alcanca.
            'permissoes' => collect(PapeisSeeder::PERMISSOES)
                ->map(fn (string $explicacao, string $nome): array => [
                    'nome' => $nome,
                    'explicacao' => $explicacao,
                ])
                ->values()
                ->all(),
        ]);
    }
}
