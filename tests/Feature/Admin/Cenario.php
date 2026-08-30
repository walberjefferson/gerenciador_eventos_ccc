<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\PapeisSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Apoio dos testes do lado administrativo.
 *
 * Segue o mesmo formato do Cenario das inscricoes: uma classe com metodos
 * estaticos, carregada pelo autoload, para que qualquer arquivo de teste possa
 * usar sem depender da ordem em que o Pest le os arquivos.
 */
final class Cenario
{
    /**
     * Semeia papeis e permissoes e limpa o cache do pacote.
     *
     * Sem limpar, a asserção seguinte le o retrato antigo e falha sem nenhuma
     * explicacao visivel.
     */
    public static function semearPapeis(): void
    {
        (new PapeisSeeder)->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Cria um usuario com e-mail ja verificado e, se for o caso, com papel.
     */
    public static function usuarioCom(?string $papel = null): User
    {
        $usuario = User::factory()->create();

        if ($papel !== null) {
            $usuario->assignRole($papel);
        }

        return $usuario->fresh();
    }
}
