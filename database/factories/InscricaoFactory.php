<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SituacaoInscricao;
use App\Models\Evento;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Inscricao>
 */
class InscricaoFactory extends Factory
{
    protected $model = Inscricao::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $documento = self::cpfDeTeste();
        $agora = Carbon::now();

        return [
            'codigo_publico' => (string) Str::ulid(),
            'evento_id' => Evento::factory(),
            'grupo_participante_id' => GrupoParticipante::factory(),
            'nome_completo' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'telefone' => '(11) 9'.fake()->numerify('####-####'),
            'documento' => $documento,
            'documento_hash' => Inscricao::hashDocumento($documento),
            'data_nascimento' => $agora->copy()->subYears(30)->toDateString(),
            'situacao' => SituacaoInscricao::AguardandoPagamento,
            'valor_centavos' => 15000,
            'versao_termos' => '2026.1',
            'termos_aceitos_em' => $agora,
            'chave_idempotencia' => (string) Str::uuid(),
            'prazo_pagamento' => $agora->copy()->addDay(),
        ];
    }

    public function confirmada(): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoInscricao::Confirmada,
            'confirmada_em' => Carbon::now(),
        ]);
    }

    public function expirada(): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoInscricao::Expirada,
            'expirada_em' => Carbon::now(),
        ]);
    }

    public function cancelada(string $motivo = 'Cancelada a pedido do participante.'): static
    {
        return $this->state(fn (array $atributos): array => [
            'situacao' => SituacaoInscricao::Cancelada,
            'cancelada_em' => Carbon::now(),
            'motivo_cancelamento' => $motivo,
        ]);
    }

    /**
     * Prazo de pagamento ja vencido, para exercitar a expiracao.
     */
    public function comPrazoVencido(): static
    {
        return $this->state(fn (array $atributos): array => [
            'prazo_pagamento' => Carbon::now()->subMinute(),
        ]);
    }

    public function comDocumento(string $documento): static
    {
        return $this->state(fn (array $atributos): array => [
            'documento' => $documento,
            'documento_hash' => Inscricao::hashDocumento($documento),
        ]);
    }

    /**
     * Gera um CPF com digitos verificadores corretos, para os testes que passam
     * pelo formulario. Numero ficticio: serve so para exercitar a validacao.
     */
    public static function cpfDeTeste(?string $base = null): string
    {
        $base ??= str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $digitos = $base;

        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $digitos[$i] * (($posicao + 1) - $i);
            }

            $digitos .= (string) (((10 * $soma) % 11) % 10);
        }

        return $digitos;
    }
}
