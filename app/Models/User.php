<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * O valor com que uma conta nasce em memória.
     *
     * Espelha o `default(true)` da coluna, e não é enfeite: sem isto, um objeto
     * recém-criado não teria o atributo `ativo` até ser relido do banco — e
     * `$usuario->ativo` viria nulo, que o middleware leria como "conta
     * desativada". A conta acabaria de nascer já trancada do lado de fora.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ativo' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
        ];
    }

    /**
     * Só quem ainda entra no painel.
     *
     * "ativo" fica FORA do `$fillable` de propósito: quem pode entrar no
     * sistema não é campo de formulário que se preenche em massa. A troca
     * acontece em um lugar só — App\Actions\Usuarios\GovernarConta —, com
     * as três travas e o registro de auditoria por perto.
     *
     * @param  Builder<User>  $consulta
     */
    public function scopeAtivos(Builder $consulta): void
    {
        $consulta->where('ativo', true);
    }
}
