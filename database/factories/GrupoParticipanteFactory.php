<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cidade;
use App\Models\GrupoParticipante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrupoParticipante>
 */
class GrupoParticipanteFactory extends Factory
{
    protected $model = GrupoParticipante::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cidade_id' => Cidade::factory(),
            'nome' => 'Grupo '.fake()->unique()->numerify('##'),
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(fn (array $atributos): array => ['ativo' => false]);
    }
}
