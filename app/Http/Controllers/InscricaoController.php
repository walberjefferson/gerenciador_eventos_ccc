<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Inscricoes\CriarInscricao;
use App\Exceptions\Inscricoes\InscricaoInvalidaException;
use App\Http\Requests\StoreInscricaoRequest;
use App\Models\Atividade;
use App\Models\Inscricao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Recebe o formulario de inscricao.
 *
 * O controller e proposital e deliberadamente magro: ele nao conhece nenhuma
 * regra do evento. Recebe, entrega para a Action e traduz a recusa do dominio
 * em erro de formulario (422), com a mesma mensagem que o participante leria.
 *
 * A resposta e negociada: quando o pedido vem do formulario Inertia, o
 * participante e levado direto para a cobranca por uma URL assinada; para
 * qualquer outro cliente a resposta continua sendo o mesmo JSON de sempre.
 */
class InscricaoController extends Controller
{
    public function store(StoreInscricaoRequest $request, CriarInscricao $criarInscricao): JsonResponse|RedirectResponse
    {
        try {
            $inscricao = $criarInscricao($request->dados());
        } catch (InscricaoInvalidaException $recusa) {
            throw ValidationException::withMessages($recusa->erros());
        }

        // O formulario da tela publica nao quer JSON: quer chegar na cobranca.
        // O reenvio da mesma chave de idempotencia cai aqui tambem e leva a
        // pessoa para a mesma cobranca, sem criar nada novo.
        if ($request->inertia()) {
            return redirect()->to($this->urlDaCobranca($inscricao));
        }

        // Envio repetido (mesma chave de idempotencia) devolve a inscricao que
        // ja existia, com 200 em vez de 201: nada de novo foi criado.
        return response()->json(
            ['inscricao' => $this->representar($inscricao)],
            $inscricao->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Link assinado da cobranca.
     *
     * A validade acompanha o prazo de pagamento com 24 horas de folga: se o
     * prazo vencer, o participante ainda abre a tela e le a explicacao, em vez
     * de bater num 403 sem entender o motivo.
     */
    private function urlDaCobranca(Inscricao $inscricao): string
    {
        $prazo = $inscricao->prazo_pagamento ?? Carbon::now()->addDay();

        return URL::temporarySignedRoute(
            'inscricoes.pagamento',
            $prazo->copy()->addDay(),
            ['codigo_publico' => $inscricao->codigo_publico],
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
                // Nulos quando a atividade não tem hora marcada.
                'comeca_em' => $atividade->comeca_em?->toIso8601String(),
                'termina_em' => $atividade->termina_em?->toIso8601String(),
                'data' => $atividade->data()->toDateString(),
            ])->all(),
        ];
    }
}
