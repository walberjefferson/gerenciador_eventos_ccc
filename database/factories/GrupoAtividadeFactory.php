<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DiaEvento;
use App\Models\GrupoAtividade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrupoAtividade>
 */
class GrupoAtividadeFactory extends Factory
{
    protected $model = GrupoAtividade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dia_evento_id' => DiaEvento::factory(),
            'nome' => 'Grupo de atividades',
            'descricao' => null,
            'obrigatorio' => false,
            'min_selecoes' => 0,
            'max_selecoes' => null,
            'posicao' => 1,
            'ativo' => true,
        ];
    }

    public function obrigatorio(int $minimo = 1, ?int $maximo = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'obrigatorio' => true,
            'min_selecoes' => $minimo,
            'max_selecoes' => $maximo,
        ]);
    }

    public function opcional(int $minimo = 0, ?int $maximo = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'obrigatorio' => false,
            'min_selecoes' => $minimo,
            'max_selecoes' => $maximo,
        ]);
    }

    public function inativo(): static
    {
        return $this->state(fn (array $atributos): array => ['ativo' => false]);
    }
}
