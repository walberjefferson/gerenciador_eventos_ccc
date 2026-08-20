<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SituacaoEvento;
use App\Models\Evento;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Evento>
 */
class EventoFactory extends Factory
{
    protected $model = Evento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inicio = Carbon::now()->addMonth()->startOfDay();

        return [
            'codigo_publico' => (string) Str::ulid(),
            'nome' => 'Evento '.fake()->unique()->numerify('###'),
            'slug' => 'evento-'.fake()->unique()->numerify('###'),
            'descricao' => 'Evento de teste.',
            'banner_caminho' => null,
            'data_inicio' => $inicio->toDateString(),
            'data_fim' => $inicio->copy()->addDay()->toDateString(),
            'inscricoes_abrem_em' => Carbon::now()->subDay(),
            'inscricoes_fecham_em' => Carbon::now()->addDays(20),
            'capacidade' => null,
            'valor_centavos' => 15000,
            'moeda' => 'BRL',
            'prazo_pagamento_minutos' => 1440,
            'situacao' => SituacaoEvento::InscricoesAbertas,
            'regulamento' => 'Regulamento de teste.',
            'versao_termos' => '2026.1',
            'contato_email' => 'contato@example.com',
            'contato_telefone' => '(11) 90000-0000',
            'configuracoes' => [],
        ];
    }

    public function rascunho(): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoEvento::Rascunho,
        ]);
    }

    public function inscricoesEncerradas(): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoEvento::InscricoesEncerradas,
        ]);
    }

    /**
     * Janela de inscricao que ainda nao comecou.
     */
    public function inscricoesAindaNaoAbriram(): static
    {
        return $this->state(fn (array $atributos): array => [
            'inscricoes_abrem_em' => Carbon::now()->addDay(),
            'inscricoes_fecham_em' => Carbon::now()->addDays(10),
        ]);
    }

    /**
     * Janela de inscricao ja vencida.
     */
    public function inscricoesJaFecharam(): static
    {
        return $this->state(fn (array $atributos): array => [
            'inscricoes_abrem_em' => Carbon::now()->subDays(10),
            'inscricoes_fecham_em' => Carbon::now()->subDay(),
        ]);
    }

    public function comCapacidade(int $capacidade): static
    {
        return $this->state(fn (array $atributos): array => [
            'capacidade' => $capacidade,
        ]);
    }
}
