<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * O painel do organizador: como esta o evento, num relance.
 *
 * O painel apenas le. Nenhuma regra de inscricao ou de pagamento passa por
 * aqui.
 */
class PainelController extends Controller
{
    public function index(Request $request): Response
    {
        return inertia('Admin/Painel');
    }
}
