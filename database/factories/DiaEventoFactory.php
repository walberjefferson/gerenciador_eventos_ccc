<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DiaEvento;
use App\Models\Evento;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<DiaEvento>
 */
class DiaEventoFactory extends Factory
{
    protected $model = DiaEvento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evento_id' => Evento::factory(),
            'nome' => 'Dia de teste',
            'descricao' => null,
            'data' => Carbon::now()->addMonth()->toDateString(),
            'posicao' => 1,
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(fn (array $atributos): array => ['ativo' => false]);
    }
}
