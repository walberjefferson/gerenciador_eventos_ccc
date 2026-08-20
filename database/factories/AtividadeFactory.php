<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Atividade;
use App\Models\GrupoAtividade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Atividade>
 */
class AtividadeFactory extends Factory
{
    protected $model = Atividade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $comeca = Carbon::now()->addMonth()->setTime(9, 0);

        return [
            'grupo_atividade_id' => GrupoAtividade::factory(),
            'nome' => 'Atividade '.fake()->unique()->numerify('###'),
            'descricao' => null,
            'comeca_em' => $comeca,
            'termina_em' => $comeca->copy()->addHours(2),
            'capacidade' => null,
            'idade_minima' => null,
            'idade_maxima' => null,
            'posicao' => 1,
            'ativo' => true,
            'configuracoes' => [],
        ];
    }

    public function noHorario(Carbon $comeca, Carbon $termina): static
    {
        return $this->state(fn (array $atributos): array => [
            'comeca_em' => $comeca,
            'termina_em' => $termina,
        ]);
    }

    public function comCapacidade(int $capacidade): static
    {
        return $this->state(fn (array $atributos): array => [
            'capacidade' => $capacidade,
        ]);
    }

    public function comFaixaEtaria(?int $minima, ?int $maxima): static
    {
        return $this->state(fn (array $atributos): array => [
            'idade_minima' => $minima,
            'idade_maxima' => $maxima,
        ]);
    }

    public function inativa(): static
    {
        return $this->state(fn (array $atributos): array => ['ativo' => false]);
    }
}
