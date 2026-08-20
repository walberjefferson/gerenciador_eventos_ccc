<?php

declare(strict_types=1);

namespace App\DTOs\Inscricoes;

use Illuminate\Support\Carbon;

/**
 * Os dados de uma nova inscricao, ja com formato conferido, a caminho da
 * Action que aplica as regras.
 *
 * E um objeto imutavel de proposito: entre a validacao do formulario e a
 * gravacao no banco, nenhum campo pode mudar sem que isso apareca no codigo.
 */
final readonly class DadosNovaInscricao
{
    /**
     * @param  array<int, int>  $atividadeIds
     */
    public function __construct(
        public int $eventoId,
        public int $cidadeId,
        public int $grupoParticipanteId,
        public string $nomeCompleto,
        public string $email,
        public string $telefone,
        public string $documento,
        public Carbon $dataNascimento,
        public array $atividadeIds,
        public bool $aceitouTermos,
        public string $chaveIdempotencia,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public static function deArray(array $dados): self
    {
        /** @var array<int, mixed> $atividades */
        $atividades = $dados['atividades'] ?? [];

        return new self(
            eventoId: (int) $dados['evento_id'],
            cidadeId: (int) $dados['cidade_id'],
            grupoParticipanteId: (int) $dados['grupo_participante_id'],
            nomeCompleto: trim((string) $dados['nome_completo']),
            email: mb_strtolower(trim((string) $dados['email'])),
            telefone: trim((string) $dados['telefone']),
            documento: (string) preg_replace('/\D/', '', (string) $dados['documento']),
            dataNascimento: Carbon::parse((string) $dados['data_nascimento'])->startOfDay(),
            atividadeIds: array_values(array_unique(array_map(
                fn (mixed $id): int => (int) $id,
                $atividades,
            ))),
            aceitouTermos: (bool) ($dados['aceite_termos'] ?? false),
            chaveIdempotencia: (string) $dados['chave_idempotencia'],
        );
    }

    /**
     * Ids das atividades em ordem crescente — a ordem canonica em que os
     * contadores de vaga sao tocados.
     *
     * @return array<int, int>
     */
    public function atividadeIdsOrdenados(): array
    {
        $ids = $this->atividadeIds;
        sort($ids);

        return $ids;
    }
}
