<?php

declare(strict_types=1);

use Tests\Feature\Inscricoes\Cenario;

/**
 * A mesma rota atende dois clientes: o formulario da tela publica, que quer
 * chegar na cobranca, e qualquer outro cliente, que continua recebendo o JSON
 * de sempre. Nenhuma regra muda — so a forma da resposta.
 */
function cabecalhosInertia(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => ''];
}

it('leva o formulario da tela para a cobranca por uma URL assinada', function (): void {
    $cenario = Cenario::montar();

    $resposta = $this->post('/inscricoes', $cenario->payload(), cabecalhosInertia());

    $resposta->assertRedirect();

    $destino = $resposta->headers->get('Location') ?? '';

    expect($destino)
        ->toContain('/pagamento')
        ->toContain('signature=')
        ->toContain('expires=');
});

it('abre a cobranca quando o link assinado esta inteiro', function (): void {
    $cenario = Cenario::montar();

    $destino = $this->post('/inscricoes', $cenario->payload(), cabecalhosInertia())
        ->headers->get('Location') ?? '';

    $this->get($destino)->assertOk();
});

it('recusa a cobranca quando a URL vem sem assinatura', function (): void {
    $cenario = Cenario::montar();

    $inscricao = $cenario->inscrever();

    $this->get('/inscricoes/'.$inscricao->codigo_publico.'/pagamento')->assertForbidden();
});

it('continua respondendo o mesmo JSON para quem nao e a tela', function (): void {
    $cenario = Cenario::montar();

    $this->postJson('/inscricoes', $cenario->payload())
        ->assertCreated()
        ->assertJsonPath('inscricao.situacao', 'aguardando_pagamento')
        ->assertJsonStructure(['inscricao' => ['codigo_publico', 'situacao_rotulo', 'valor_centavos', 'prazo_pagamento', 'atividades']]);
});
