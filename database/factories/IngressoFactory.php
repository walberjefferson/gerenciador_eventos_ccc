<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ingresso;
use App\Models\Inscricao;
use App\Models\User;
use App\Services\Ingressos\GeradorDeCodigo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Ingresso>
 */
class IngressoFactory extends Factory
{
    protected $model = Ingresso::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A inscricao de um ingresso e sempre confirmada: e assim que ele
            // nasce no sistema, e um cenario de teste que comecasse diferente
            // provaria uma coisa que nao acontece.
            'inscricao_id' => Inscricao::factory()->confirmada(),
            // O mesmo sorteio do sistema, e nao um numero sequencial: assim o
            // teste exercita o formato de verdade.
            'codigo' => (new GeradorDeCodigo)(),
            'emitido_em' => Carbon::now(),
            'usado_em' => null,
            'usado_por' => null,
        ];
    }

    /**
     * Alguem ja entrou com este ingresso.
     */
    public function usado(?User $responsavel = null, ?Carbon $momento = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'usado_em' => $momento ?? Carbon::now(),
            'usado_por' => $responsavel?->getKey() ?? User::factory(),
        ]);
    }

    /**
     * Ninguem entrou ainda. E o estado padrao, escrito com nome proprio para
     * que o cenario diga o que esta testando em vez de depender do silencio.
     */
    public function naoUsado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'usado_em' => null,
            'usado_por' => null,
        ]);
    }
}
