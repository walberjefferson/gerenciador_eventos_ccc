<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AcaoAuditada;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria a conta de quem administra o sistema.
 *
 * O cadastro publico foi fechado (DA-11): ninguem mais vira usuario do lado de
 * dentro sozinho. A conta nasce aqui, na linha de comando, por alguem que ja
 * tem acesso ao servidor.
 *
 * A senha nunca aparece na tela nem no historico do terminal: ela e pedida de
 * forma escondida. Passar senha por argumento seria deixa-la gravada no
 * historico do shell.
 */
class CriarAdministrador extends Command
{
    protected $signature = 'usuario:criar-administrador
                            {email : e-mail de quem vai administrar}
                            {--nome= : nome que aparece na tela}
                            {--papel=administrador : papel atribuido a conta}';

    protected $description = 'Cria uma conta administrativa e atribui o papel informado';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $papel = (string) $this->option('papel');

        $erroDeEmail = $this->conferirEmail($email);

        if ($erroDeEmail !== null) {
            $this->components->error($erroDeEmail);

            return self::FAILURE;
        }

        // O papel precisa existir de verdade. Escrever "adminstrador" e criar
        // uma conta que nao abre nada seria pior do que recusar agora.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $papeisExistentes = Role::query()->pluck('name')->all();

        if (! in_array($papel, $papeisExistentes, true)) {
            $this->components->error(
                $papeisExistentes === []
                    ? 'Nenhum papel foi cadastrado ainda. Rode "php artisan db:seed --class=PapeisSeeder" antes.'
                    : sprintf('O papel "%s" nao existe. Papeis disponiveis: %s.', $papel, implode(', ', $papeisExistentes))
            );

            return self::FAILURE;
        }

        $nome = (string) ($this->option('nome') ?: $this->perguntarNome($email));

        $senha = $this->senhaInformada();

        if ($senha === null) {
            $this->components->error('A senha precisa ter ao menos 8 caracteres.');

            return self::FAILURE;
        }

        $usuario = User::query()->create([
            'name' => $nome,
            'email' => $email,
            'password' => Hash::make($senha),
        ]);

        // Quem cria conta pela linha de comando ja provou ter acesso ao
        // servidor; exigir confirmacao de e-mail depois disso so atrapalha.
        $usuario->forceFill(['email_verified_at' => now()])->save();

        $usuario->assignRole($papel);

        // Conta administrativa nascendo e exatamente o tipo de evento que
        // alguem revisando o sistema precisa conseguir encontrar depois. Nao
        // ha usuario autenticado aqui — quem rodou o comando esta no
        // servidor —, entao o responsavel fica como "Sistema" e o rastro
        // guarda o e-mail e o papel concedido. A senha, obviamente, nao entra.
        app(RegistrarAcao::class)(
            AcaoAuditada::CriouUsuarioAdministrativo,
            'usuario',
            (int) $usuario->getKey(),
            ['email' => $email, 'papel' => $papel, 'origem' => 'linha de comando'],
        );

        $this->components->info(sprintf('Conta criada para %s com o papel "%s".', $email, $papel));

        return self::SUCCESS;
    }

    /**
     * Diz o que ha de errado com o e-mail, ou null se estiver tudo certo.
     */
    private function conferirEmail(string $email): ?string
    {
        $validacao = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']]);

        if ($validacao->fails()) {
            return 'Informe um e-mail valido.';
        }

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return sprintf('Ja existe uma conta com o e-mail %s.', $email);
        }

        return null;
    }

    private function perguntarNome(string $email): string
    {
        $padrao = ucfirst(explode('@', $email)[0]);

        return (string) ($this->ask('Nome de quem vai usar a conta', $padrao) ?: $padrao);
    }

    /**
     * Pede a senha escondida e confere o tamanho minimo.
     */
    private function senhaInformada(): ?string
    {
        $senha = (string) $this->secret('Senha (nao aparece na tela, minimo de 8 caracteres)');

        return mb_strlen($senha) >= 8 ? $senha : null;
    }
}
