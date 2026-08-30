<?php

declare(strict_types=1);

use App\Enums\SituacaoWebhook;
use App\Models\User;
use App\Models\WebhookPagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;

/**
 * A tela que mostra os avisos do provedor de pagamento.
 *
 * O que se prova aqui: quem administra enxerga o que hoje so existia no banco;
 * quem organiza o evento recebe a porta fechada; cada filtro devolve o que
 * promete; a lista custa o mesmo com uma ou com muitas linhas; e o painel
 * responde "o provedor ainda esta chamando?" sem inventar intervalo quando
 * nunca chegou aviso nenhum.
 *
 * Nenhum teste daqui envia aviso: quem recebe e grava e o
 * PaymentWebhookController, provado desde a Fase 8a. Esta tela so le.
 *
 * @param  array<string, mixed>  $atributos
 */
function avisoRecebido(array $atributos = []): WebhookPagamento
{
    static $sequencia = 0;

    $sequencia++;

    return WebhookPagamento::query()->create(array_merge([
        'gateway' => 'efi',
        'id_evento_externo' => 'evento-externo-'.$sequencia,
        'tipo_evento' => 'pix',
        // Ja limpo, como o controller do webhook grava: e ele que troca por
        // "[removido]" tudo o que costuma carregar segredo, antes de virar
        // linha no banco.
        'payload' => ['pix' => [['txid' => 'txid-'.$sequencia, 'chave' => '[removido]']]],
        'assinatura_valida' => true,
        'recebido_em' => Carbon::now(),
        'situacao' => SituacaoWebhook::Recebido,
    ], $atributos));
}

/**
 * Quantas consultas o servidor faz para responder este endereco.
 *
 * A primeira requisicao e de aquecimento e nao entra na conta: e nela que o
 * pacote de permissoes carrega o proprio cache, e esse custo aparece uma vez
 * so, em qualquer tela. O que se quer medir e o custo de listar, e ele tem de
 * ser o mesmo com um aviso ou com muitos.
 */
function consultasPara(string $endereco, User $usuario): int
{
    test()->actingAs($usuario)->get($endereco)->assertOk();

    $contador = 0;

    DB::listen(function () use (&$contador): void {
        $contador++;
    });

    test()->actingAs($usuario)->get($endereco)->assertOk();

    return $contador;
}

it('mostra os avisos para quem administra, do mais recente para o mais antigo', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['recebido_em' => Carbon::now()->subHours(3), 'tipo_evento' => 'antigo']);
    avisoRecebido(['recebido_em' => Carbon::now()->subMinutes(5), 'tipo_evento' => 'recente']);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Admin/Pagamentos/Avisos/Index')
            ->has('avisos.dados', 2)
            ->where('avisos.dados.0.tipo_evento', 'recente')
            ->where('avisos.dados.1.tipo_evento', 'antigo')
            ->where('avisos.total', 2)
            ->where('avisos.por_pagina', 25)
        );
});

it('usa o rotulo do proprio enum para escrever a situacao', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['situacao' => SituacaoWebhook::Falhou, 'erro' => 'Banco fora do ar.']);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('avisos.dados.0.situacao', 'falhou')
            ->where('avisos.dados.0.situacao_rotulo', SituacaoWebhook::Falhou->rotulo())
            ->where('avisos.dados.0.erro', 'Banco fora do ar.')
        );
});

it('recusa com 403 quem organiza o evento', function (): void {
    Cenario::semearPapeis();

    avisoRecebido();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get('/admin/pagamentos/avisos')
        ->assertForbidden();
});

it('manda o visitante para o login antes de mostrar aviso nenhum', function (): void {
    Cenario::semearPapeis();

    $this->get('/admin/pagamentos/avisos')->assertRedirect('/login');
});

it('filtra por situacao', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['situacao' => SituacaoWebhook::Processado]);
    avisoRecebido(['situacao' => SituacaoWebhook::Ignorado, 'erro' => 'Cobranca desconhecida neste sistema.']);
    avisoRecebido(['situacao' => SituacaoWebhook::Ignorado]);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos?situacao=ignorado')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 2)
            ->where('avisos.dados.0.situacao', 'ignorado')
            ->where('avisos.dados.1.situacao', 'ignorado')
            ->where('filtros.situacao', 'ignorado')
        );
});

it('filtra por provedor, e so oferece no seletor quem ja mandou alguma coisa', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['gateway' => 'efi']);
    avisoRecebido(['gateway' => 'fake']);
    avisoRecebido(['gateway' => 'fake']);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos?gateway=fake')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 2)
            ->where('avisos.dados.0.gateway', 'fake')
            ->where('opcoes.gateways', ['efi', 'fake'])
        );
});

it('filtra pela validade da assinatura, que e o caso de seguranca', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['assinatura_valida' => true]);
    avisoRecebido([
        'assinatura_valida' => false,
        'situacao' => SituacaoWebhook::Ignorado,
        'erro' => 'Assinatura invalida.',
        'id_evento_externo' => null,
    ]);

    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)
        ->get('/admin/pagamentos/avisos?assinatura_valida=nao')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 1)
            ->where('avisos.dados.0.assinatura_valida', false)
            ->where('avisos.dados.0.erro', 'Assinatura invalida.')
        );

    $this->actingAs($administrador)
        ->get('/admin/pagamentos/avisos?assinatura_valida=sim')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 1)
            ->where('avisos.dados.0.assinatura_valida', true)
        );
});

it('filtra por periodo, incluindo o dia inteiro das duas pontas', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['recebido_em' => Carbon::parse('2026-08-10 08:00:00'), 'tipo_evento' => 'antes']);
    avisoRecebido(['recebido_em' => Carbon::parse('2026-08-15 23:50:00'), 'tipo_evento' => 'dentro']);
    avisoRecebido(['recebido_em' => Carbon::parse('2026-08-20 09:00:00'), 'tipo_evento' => 'depois']);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos?de=2026-08-15&ate=2026-08-15')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 1)
            ->where('avisos.dados.0.tipo_evento', 'dentro')
        );
});

it('combina dois filtros em vez de aplicar so o ultimo', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['gateway' => 'efi', 'situacao' => SituacaoWebhook::Falhou]);
    avisoRecebido(['gateway' => 'efi', 'situacao' => SituacaoWebhook::Processado]);
    avisoRecebido(['gateway' => 'fake', 'situacao' => SituacaoWebhook::Falhou]);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos?gateway=efi&situacao=falhou')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 1)
            ->where('avisos.dados.0.gateway', 'efi')
            ->where('avisos.dados.0.situacao', 'falhou')
        );
});

it('preserva o filtro ao virar a pagina', function (): void {
    Cenario::semearPapeis();

    for ($i = 0; $i < 26; $i++) {
        avisoRecebido(['situacao' => SituacaoWebhook::Ignorado]);
    }

    avisoRecebido(['situacao' => SituacaoWebhook::Falhou]);

    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)
        ->get('/admin/pagamentos/avisos?situacao=ignorado')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 25)
            ->where('avisos.total', 26)
            ->where('avisos.ultima_pagina', 2)
            // O endereco da proxima pagina leva o filtro junto: sem isso, virar
            // a pagina jogaria fora o que a pessoa acabou de pedir.
            ->where('avisos.links.proxima', fn (?string $url): bool => str_contains((string) $url, 'situacao=ignorado'))
        );

    $this->actingAs($administrador)
        ->get('/admin/pagamentos/avisos?situacao=ignorado&page=2')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('avisos.dados', 1)
            ->where('avisos.dados.0.situacao', 'ignorado')
            ->where('avisos.pagina_atual', 2)
        );
});

it('entrega o conteudo do aviso como ele foi gravado, com o segredo ja removido', function (): void {
    Cenario::semearPapeis();

    avisoRecebido();

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos')
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('avisos.dados.0.payload.pix.0.txid', fn (string $txid): bool => str_starts_with($txid, 'txid-'))
            // A chave da conta que recebe o dinheiro nao volta para a tela
            // porque nunca entrou no banco: quem a removeu foi o controller do
            // webhook, no momento de gravar (provado em EfiWebhookTest).
            ->where('avisos.dados.0.payload.pix.0.chave', '[removido]')
        );
});

it('nao vasculha nenhuma chave sensivel para a tela', function (): void {
    Cenario::semearPapeis();

    avisoRecebido([
        'payload' => [
            'txid' => 'cobranca-visivel',
            'secret' => '[removido]',
            'token' => '[removido]',
            'pix' => [['chave' => '[removido]']],
        ],
    ]);

    $resposta = $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/pagamentos/avisos')
        ->assertOk();

    $conteudo = $resposta->getContent();

    expect($conteudo)->toContain('cobranca-visivel')
        ->and($conteudo)->toContain('[removido]');
});

it('custa o mesmo numero de consultas com um aviso ou com muitos', function (): void {
    Cenario::semearPapeis();

    $administrador = Cenario::usuarioCom('administrador');

    avisoRecebido();
    $comUm = consultasPara('/admin/pagamentos/avisos', $administrador);

    for ($i = 0; $i < 9; $i++) {
        avisoRecebido();
    }

    $comDez = consultasPara('/admin/pagamentos/avisos', $administrador);

    // A lista nao carrega relacao nenhuma, entao dez vezes mais avisos nao
    // custam dez vezes mais idas ao banco. E isso que segura a tela no dia em
    // que o provedor mandar mil avisos.
    expect($comDez)->toBe($comUm);
});

it('mostra no painel o aviso mais recente, e nao uma lista', function (): void {
    Cenario::semearPapeis();

    avisoRecebido(['recebido_em' => Carbon::now()->subDays(2), 'gateway' => 'fake']);
    avisoRecebido([
        'recebido_em' => Carbon::now()->subMinutes(90),
        'gateway' => 'efi',
        'situacao' => SituacaoWebhook::Processado,
    ]);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/painel')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('avisos_do_provedor.ultimo.gateway', 'efi')
            ->where('avisos_do_provedor.ultimo.situacao', 'processado')
            ->where('avisos_do_provedor.ultimo.situacao_rotulo', SituacaoWebhook::Processado->rotulo())
            ->where('avisos_do_provedor.ultimo.minutos_atras', 90)
            ->etc()
        );
});

it('nao calcula intervalo nenhum quando nunca chegou aviso', function (): void {
    Cenario::semearPapeis();

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->get('/admin/painel')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('avisos_do_provedor.ultimo', null)
            ->etc()
        );
});

it('nao mostra o cartao dos avisos a quem nao pode abrir a tela deles', function (): void {
    Cenario::semearPapeis();

    avisoRecebido();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get('/admin/painel')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('avisos_do_provedor', null)
            ->etc()
        );
});

it('o cartao do painel e uma consulta agregada, e nao uma listagem', function (): void {
    Cenario::semearPapeis();

    $administrador = Cenario::usuarioCom('administrador');

    avisoRecebido();
    $comUm = consultasPara('/admin/painel', $administrador);

    for ($i = 0; $i < 19; $i++) {
        avisoRecebido();
    }

    $comVinte = consultasPara('/admin/painel', $administrador);

    // Vinte avisos no banco nao acrescentam consulta nenhuma ao painel: o
    // cartao le uma linha, a mais recente, e nunca a tabela inteira.
    expect($comVinte)->toBe($comUm);
});
