<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Conta administrativa de demonstracao, para quem esta desenvolvendo.
 *
 * So nasce em "local". Conta previsivel em servidor de verdade e porta aberta
 * com o endereco escrito na placa — por isso a mesma trava de ambiente das
 * rotas de simulacao de pagamento (D-29).
 */
class AdminDemoSeeder extends Seeder
{
    public const EMAIL = 'admin@exemplo.test';

    public const SENHA = 'password';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('AdminDemoSeeder so roda em ambiente local. Nada foi criado.');

            return;
        }

        $this->call(PapeisSeeder::class);

        $usuario = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Administrador de demonstracao',
                'password' => Hash::make(self::SENHA),
                'email_verified_at' => now(),
            ],
        );

        $usuario->syncRoles([PapeisSeeder::PAPEL_ADMINISTRADOR]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf('Conta de demonstracao pronta: %s / %s', self::EMAIL, self::SENHA));
    }
}
