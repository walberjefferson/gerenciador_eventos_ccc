<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cidade>
 */
class CidadeFactory extends Factory
{
    protected $model = Cidade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->city(),
            'uf' => fake()->randomElement(['SP', 'MG', 'RJ', 'PR', 'SC', 'BA']),
            'ativo' => true,
        ];
    }

    public function inativa(): static
    {
        return $this->state(fn (array $atributos): array => ['ativo' => false]);
    }
}
