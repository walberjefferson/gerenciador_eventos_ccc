<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cadastro e edicao de uma conta administrativa pela tela.
 *
 * A senha so e exigida na CRIACAO. Na edicao ela e opcional, e quando vem
 * vazia o campo simplesmente nao e tocado — senao editar o nome de alguem
 * obrigaria a inventar uma senha nova para ele.
 */
class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('usuarios.gerenciar') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // O pacote guarda papel e permissao em cache; sem limpar, a
        // conferencia pode ler um retrato velho. Mesmo cuidado do
        // UsuarioPapelRequest.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $usuario = $this->route('usuario');
        $id = $usuario instanceof User ? $usuario->getKey() : null;

        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            // O e-mail e o login: dois iguais fariam duas contas disputarem a
            // mesma porta. A regra ignora a propria linha na edicao, senao
            // salvar sem mexer no e-mail acusaria duplicidade consigo mesma.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'papel' => ['required', 'string', Rule::in(Role::query()->pluck('name')->all())],
            'password' => [
                $this->isMethod('POST') ? 'required' : 'nullable',
                'string',
                'confirmed',
                Password::defaults(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da pessoa.',
            'name.min' => 'O nome precisa ter ao menos 3 letras.',
            'email.required' => 'Informe o e-mail, que também é o login.',
            'email.email' => 'Este e-mail parece incompleto. Confira e tente de novo.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'papel.required' => 'Escolha o papel da pessoa.',
            'papel.in' => 'Este papel não existe.',
            'password.required' => 'Defina uma senha para a primeira entrada.',
            'password.confirmed' => 'As duas senhas digitadas não são iguais.',
        ];
    }
}
