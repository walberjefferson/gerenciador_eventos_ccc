<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Evento;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Lote>
 */
class LoteFactory extends Factory
{
    protected $model = Lote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evento_id' => Evento::factory(),
            'nome' => 'Lote de teste',
            'posicao' => 1,
            'valor_centavos' => 10000,
            // Um limite por padrao, porque o banco exige pelo menos um (RN-L1).
            'disponivel_ate' => Carbon::now()->addWeek(),
            'quantidade' => null,
        ];
    }

    /**
     * Lote que encerra por data. Uma data no passado ja nasce encerrado.
     */
    public function porData(?Carbon $ate = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'disponivel_ate' => $ate ?? Carbon::now()->addWeek(),
            'quantidade' => null,
        ]);
    }

    /**
     * Lote que encerra quando as vagas dele acabam.
     */
    public function porQuantidade(int $quantidade): static
    {
        return $this->state(fn (array $atributos): array => [
            'disponivel_ate' => null,
            'quantidade' => $quantidade,
        ]);
    }

    /**
     * Lote com as vagas ja tomadas.
     *
     * O contador e movido por SQL, e nao por atribuicao no model, pelo mesmo
     * motivo que vale em producao: vagas_ocupadas nao e campo de formulario, e
     * o unico jeito legitimo de move-lo e um comando que o banco execute.
     */
    public function esgotado(): static
    {
        return $this
            ->state(fn (array $atributos): array => [
                'quantidade' => $atributos['quantidade'] ?? 1,
            ])
            ->afterCreating(function (Lote $lote): void {
                DB::table('lotes')
                    ->where('id', $lote->getKey())
                    ->update(['vagas_ocupadas' => $lote->quantidade]);

                $lote->refresh();
            });
    }
}
