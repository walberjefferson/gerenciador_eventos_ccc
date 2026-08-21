<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Deixa "$this->authorize(...)" disponivel em qualquer controller. As telas
    // administrativas usam isso como segunda tranca: a rota ja exige a
    // permissao, e a Policy confere de novo, perto do recurso.
    use AuthorizesRequests;
}
