<?php

declare(strict_types=1);

namespace App\DTOs\Inscricoes;

use App\Enums\Sexo;
use Illuminate\Support\Carbon;

/**
 * Os dados de uma correcao administrativa, ja com formato conferido, a caminho
 * da Action que aplica as regras.
 *
 * Espelha DadosNovaInscricao, e as AUSENCIAS sao o que este objeto tem de mais
 * importante:
 *
 * - **Nao ha documento.** Trocar o CPF mexeria na chave que impede a mesma
 *   pessoa de se inscrever duas vezes no mesmo evento. E outro assunto, com
 *   outra conversa — e enquanto ele nao acontece, o CPF nao entra no
 *   formulario, nao entra aqui e nao entra na auditoria.
 * - **Nao ha evento, lote nem valor.** O preco e fotografado no instante da
 *   inscricao; trocar atividade depois nao recalcula nada, e mudar de evento
 *   seria outra inscricao, nao a mesma corrigida.
 * - **Nao ha cidade.** O setor nao e campo proprio: ele vem pelo grupo de
 *   participantes, que e o que a pessoa escolheu. Um campo separado seria uma
 *   segunda verdade esperando divergir da primeira.
 */
final readonly class DadosEdicaoInscricao
{
    /**
     * @param  array<int, int>  $atividadeIds
     */
    public function __construct(
        public string $nomeCompleto,
        public string $email,
        public string $telefone,
        public Carbon $dataNascimento,
        public Sexo $sexo,
        public int $grupoParticipanteId,
        public array $atividadeIds,
        /**
         * A segunda confirmacao para furar a lotacao de uma atividade.
         *
         * Falso por padrao, e o padrao e a regra: a Action so passa por cima da
         * capacidade quando alguem disse explicitamente que quer isso, e o
         * excedente vai para a auditoria (RN-E2).
         */
        public bool $permitirExcederCapacidade = false,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public static function deArray(array $dados): self
    {
        /** @var array<int, mixed> $atividades */
        $atividades = $dados['atividades'] ?? [];

        return new self(
            nomeCompleto: trim((string) $dados['nome_completo']),
            email: mb_strtolower(trim((string) $dados['email'])),
            telefone: trim((string) $dados['telefone']),
            dataNascimento: Carbon::parse((string) $dados['data_nascimento'])->startOfDay(),
            sexo: Sexo::from((string) $dados['sexo']),
            grupoParticipanteId: (int) $dados['grupo_participante_id'],
            atividadeIds: array_values(array_unique(array_map(
                fn (mixed $id): int => (int) $id,
                $atividades,
            ))),
            permitirExcederCapacidade: (bool) ($dados['permitir_exceder_capacidade'] ?? false),
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
