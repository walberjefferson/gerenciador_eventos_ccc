<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * O que precisa ser verdade para uma conta trocar de papel.
 *
 * Uma regra so, e ela e a que importa: **o papel precisa existir de verdade**,
 * conferido contra a tabela, e nao contra uma lista escrita a mao aqui. Papel
 * nasce no `PapeisSeeder` (D-50); se um dia nascer um terceiro, esta tela passa
 * a aceita-lo sem ninguem precisar lembrar de mexer neste arquivo — e um papel
 * escrito errado continua sendo recusado antes de virar uma conta que nao abre
 * nada.
 */
class UsuarioPapelRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // O pacote guarda papel e permissao em cache. Sem limpar, a conferencia
        // pode ler um retrato velho logo depois de o seeder rodar.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'papel' => ['required', 'string', Rule::in(Role::query()->pluck('name')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['papel' => 'papel'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'papel.required' => 'Escolha o papel da conta.',
            'papel.in' => 'Esse papel não existe.',
        ];
    }

    public function papel(): string
    {
        return (string) $this->string('papel');
    }
}
