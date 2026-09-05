<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Comprovantes\EnviarComprovante;
use App\Exceptions\Pagamentos\ComprovanteRecusadoException;
use App\Http\Requests\EnviarComprovanteRequest;
use App\Models\Inscricao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * O envio do comprovante, pela tela do participante.
 *
 * A rota e assinada, como todas as do participante: o codigo publico sozinho
 * nunca serve de senha, e quem chega sem assinatura valida recebe 403 do
 * middleware, antes de o controller existir. Ela leva limite de tentativas
 * tambem — e a unica porta em que uma pessoa de fora escreve arquivo no
 * servidor.
 *
 * Este controller nao decide nada sobre dinheiro nem sobre situacao de
 * inscricao. Ele guarda um arquivo e volta para a tela dizendo que guardou; o
 * que o arquivo prova, quem diz e a pessoa que vai conferi-lo (RN-S7).
 */
class ComprovanteController extends Controller
{
    public function store(
        EnviarComprovanteRequest $pedido,
        string $codigoPublico,
        EnviarComprovante $enviar,
    ): RedirectResponse {
        $inscricao = Inscricao::query()
            ->with('evento')
            ->where('codigo_publico', $codigoPublico)
            ->firstOrFail();

        try {
            $enviar($inscricao, $pedido->arquivoEnviado());
        } catch (ComprovanteRecusadoException $recusa) {
            // A recusa volta no MESMO campo do formulario, e nao como um aviso
            // solto: e ali que a pessoa esta olhando.
            return redirect()
                ->to($this->urlDaCobranca($inscricao))
                ->withErrors(['comprovante' => $recusa->getMessage()]);
        }

        return redirect()
            ->to($this->urlDaCobranca($inscricao))
            ->with('sucesso', 'Comprovante enviado. Agora ele passa por conferência do responsável do seu setor.');
    }

    /**
     * O caminho de volta para a tela da cobranca, ja assinado.
     *
     * Assinado porque a rota de destino exige assinatura: um `back()` simples
     * dependeria do cabecalho de origem que o navegador manda — e quando ele
     * nao vem, a pessoa cai num 403 depois de ter enviado o arquivo certo.
     *
     * A validade acompanha a da propria tela, com um dia de folga, no mesmo
     * molde do PagamentoController.
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
}
