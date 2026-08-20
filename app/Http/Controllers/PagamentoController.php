<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Inscricao;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cobranca Pix de uma inscricao.
 *
 * A rota e assinada: o codigo publico sozinho nunca serve de senha. Quem
 * chega sem assinatura valida — ou com o link vencido — recebe 403 do proprio
 * middleware.
 *
 * ATENCAO: por enquanto este controller so entrega o codigo da inscricao para
 * a tela. O QR Code, o copia e cola, o contador regressivo e a consulta de
 * situacao entram no passo da tela de pagamento.
 */
class PagamentoController extends Controller
{
    public function show(string $codigoPublico): Response
    {
        $inscricao = Inscricao::query()
            ->where('codigo_publico', $codigoPublico)
            ->firstOrFail();

        return Inertia::render('Inscricoes/Pagamento', [
            'codigo_publico' => $inscricao->codigo_publico,
        ]);
    }
}
