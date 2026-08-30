<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * Conta desativada não entra — e a recusa é **indistinguível** da recusa
     * por senha errada: mesma mensagem, mesmo código, mesma contagem no
     * `RateLimiter`. Dizer "sua conta foi desativada" contaria a quem tenta
     * adivinhar senha que aquele e-mail existe e está cadastrado aqui, o que
     * transforma a tela de login num verificador de e-mails.
     *
     * A conferência acontece **depois** do `Auth::attempt`, e não como mais uma
     * condição da consulta, de propósito: assim a senha é conferida (o hash é
     * calculado) nos dois caminhos, e o tempo de resposta não denuncia qual dos
     * dois motivos recusou. A sessão que o `attempt` abriu é desfeita na linha
     * seguinte, antes de qualquer redirecionamento.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->recusar();
        }

        $usuario = Auth::user();

        if ($usuario instanceof User && ! $usuario->ativo) {
            Auth::guard('web')->logout();

            $this->recusar();
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * A única recusa que existe nesta tela.
     *
     * @throws ValidationException
     */
    private function recusar(): never
    {
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.failed'),
        ]);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
