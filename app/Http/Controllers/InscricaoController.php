<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Inscricoes\CriarInscricao;
use App\Exceptions\Inscricoes\InscricaoInvalidaException;
use App\Http\Requests\StoreInscricaoRequest;
use App\Models\Atividade;
use App\Models\Inscricao;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Recebe o formulario de inscricao.
 *
 * O controller e proposital e deliberadamente magro: ele nao conhece nenhuma
 * regra do evento. Recebe, entrega para a Action e traduz a recusa do dominio
 * em erro de formulario (422), com a mesma mensagem que o participante leria.
 *
 * As telas ficam na fase do site publico; por enquanto a resposta e JSON.
 */
class InscricaoController extends Controller
{
    public function store(StoreInscricaoRequest $request, CriarInscricao $criarInscricao): JsonResponse
    {
        try {
            $inscricao = $criarInscricao($request->dados());
        } catch (InscricaoInvalidaException $recusa) {
            throw ValidationException::withMessages($recusa->erros());
        }

        // Envio repetido (mesma chave de idempotencia) devolve a inscricao que
        // ja existia, com 200 em vez de 201: nada de novo foi criado.
        return response()->json(
            ['inscricao' => $this->representar($inscricao)],
            $inscricao->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(Inscricao $inscricao): array
    {
        return [
            'codigo_publico' => $inscricao->codigo_publico,
            'nome_completo' => $inscricao->nome_completo,
            'email' => $inscricao->email,
            'situacao' => $inscricao->situacao->value,
            'situacao_rotulo' => $inscricao->situacao->rotulo(),
            'valor_centavos' => $inscricao->valor_centavos,
            'prazo_pagamento' => $inscricao->prazo_pagamento?->toIso8601String(),
            'versao_termos' => $inscricao->versao_termos,
            'atividades' => $inscricao->atividades->map(fn (Atividade $atividade): array => [
                'id' => $atividade->id,
                'nome' => $atividade->nome,
                'comeca_em' => $atividade->comeca_em->toIso8601String(),
                'termina_em' => $atividade->termina_em->toIso8601String(),
            ])->all(),
        ];
    }
}
