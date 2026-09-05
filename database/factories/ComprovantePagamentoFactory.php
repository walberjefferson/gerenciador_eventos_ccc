<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SituacaoComprovante;
use App\Models\ComprovantePagamento;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ComprovantePagamento>
 */
class ComprovantePagamentoFactory extends Factory
{
    protected $model = ComprovantePagamento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inscricao_id' => Inscricao::factory(),
            'caminho' => 'comprovantes/'.Carbon::now()->year.'/TESTE/'.$this->faker->uuid().'.jpg',
            'nome_original' => 'comprovante.jpg',
            'mime' => 'image/jpeg',
            'tamanho_bytes' => 120_000,
            'situacao' => SituacaoComprovante::Enviado,
            'enviado_em' => Carbon::now(),
            'conferido_por_id' => null,
            'conferido_em' => null,
            'motivo_recusa' => null,
        ];
    }

    /**
     * Comprovante esperando conferencia. E o estado padrao, e esta aqui por
     * nome para que o teste diga o que quer em vez de contar com o padrao.
     */
    public function enviado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoComprovante::Enviado,
            'conferido_por_id' => null,
            'conferido_em' => null,
            'motivo_recusa' => null,
        ]);
    }

    public function aceito(?User $conferidoPor = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoComprovante::Aceito,
            'conferido_por_id' => $conferidoPor?->getKey() ?? User::factory(),
            'conferido_em' => Carbon::now(),
            'motivo_recusa' => null,
        ]);
    }

    /**
     * O motivo nunca fica em branco: o banco recusa recusa sem explicacao.
     */
    public function recusado(string $motivo = 'O valor do comprovante não confere com o da inscrição.', ?User $conferidoPor = null): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoComprovante::Recusado,
            'conferido_por_id' => $conferidoPor?->getKey() ?? User::factory(),
            'conferido_em' => Carbon::now(),
            'motivo_recusa' => $motivo,
        ]);
    }
}
