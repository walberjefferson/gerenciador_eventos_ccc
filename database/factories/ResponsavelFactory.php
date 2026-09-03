<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Responsavel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Responsavel>
 */
class ResponsavelFactory extends Factory
{
    protected $model = Responsavel::class;

    /**
     * O caso comum: pessoa apta, com conta no painel.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'chave_pix' => fake()->unique()->safeEmail(),
            'telefone' => fake()->numerify('(82) 9####-####'),
            'user_id' => User::factory(),
            'ativo' => true,
        ];
    }

    /**
     * Recebe, mas nao confere (RN-R1): o tesoureiro que nao usa o sistema.
     */
    public function semConta(): static
    {
        return $this->state(fn (array $atributos): array => ['user_id' => null]);
    }

    /**
     * Cadastrado, mas sem para onde mandar dinheiro — fora do sorteio (RN-R9).
     *
     * A coluna e NOT NULL: "sem chave" e texto vazio, que e como o formulario
     * deixa o campo quando alguem apaga o que estava la.
     */
    public function semChave(): static
    {
        return $this->state(fn (array $atributos): array => ['chave_pix' => '']);
    }

    /**
     * Desativado: sai do sorteio na hora, sem tocar em cobranca ja emitida.
     */
    public function inativo(): static
    {
        return $this->state(fn (array $atributos): array => ['ativo' => false]);
    }
}
