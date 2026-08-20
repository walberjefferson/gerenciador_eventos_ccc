<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Atividade;
use App\Models\ConflitoAtividade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConflitoAtividade>
 */
class ConflitoAtividadeFactory extends Factory
{
    protected $model = ConflitoAtividade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'atividade_a_id' => Atividade::factory(),
            'atividade_b_id' => Atividade::factory(),
            'motivo' => 'As duas atividades acontecem no mesmo espaço.',
        ];
    }
}
