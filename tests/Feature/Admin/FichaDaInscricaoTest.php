<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Events\InscricaoCancelada;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/**
 * A ficha da inscricao e as duas acoes que a tela oferece.
 *
 * As regras de dominio ja tem teste proprio (CancelamentoAdministrativoTest e
 * ConfirmacaoManualTest). O que se prova aqui e o caminho da tela: quem alcanca
 * cada acao, o que o formulario exige antes de deixar passar e o que a pessoa
 * le quando a acao e recusada.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

it('abre a ficha com o historico da cobranca e sem CPF', function () {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $resposta = $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}");

    $resposta->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Admin/Inscricoes/Show')
            ->where('inscricao.codigo_publico', $inscricao->codigo_publico)
            ->has('inscricao.atividades', 1)
            ->has('cobrancas', 1)
            ->missing('inscricao.documento')
            ->missing('inscricao.documento_hash')
            ->etc());

    expect($resposta->getContent())->not->toContain('52998224725');
});

it('leva o identificador da cobranca no provedor ate a ficha', function () {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();
    $pagamento = $inscricao->pagamentoPendente();

    // O txid so serve para conciliar se for exatamente o mesmo que esta no
    // painel do provedor. Comparar com o valor gravado e o unico jeito de
    // provar que a tela nao mostra outro codigo qualquer no lugar dele.
    expect($pagamento->id_externo)->not->toBeNull();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}")
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('cobrancas.0.id_externo', $pagamento->id_externo)
            ->where('cobrancas.0.codigo_publico', $pagamento->codigo_publico)
            ->etc());

    // E os dois codigos continuam sendo dois: se um dia virarem o mesmo valor,
    // a coluna nova deixa de ter razao de existir e este teste avisa.
    expect($pagamento->id_externo)->not->toBe($pagamento->codigo_publico);
});

it('leva quem pagou ate a ficha, e nunca o CPF inteiro', function () {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();
    $pagamento = $inscricao->pagamentoPendente();

    // O pagador chega pelo aviso do provedor, ja mascarado por quem gravou o
    // aviso. Aqui ele e escrito direto em metadados porque o que se prova e o
    // caminho do dado ate a tela — o caminho do aviso ate metadados tem prova
    // propria em EfiWebhookTest.
    $pagamento->forceFill(['metadados' => array_merge((array) $pagamento->metadados, [
        'pagador' => [
            'nome' => 'MARIA DE SOUZA',
            'documento' => '***.456.789-**',
            'tipo_documento' => 'cpf',
            'mensagem' => 'pagando a inscricao do meu filho',
        ],
    ])])->save();

    $resposta = $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}");

    $resposta->assertInertia(fn (Assert $pagina) => $pagina
        ->where('cobrancas.0.pagador.nome', 'MARIA DE SOUZA')
        ->where('cobrancas.0.pagador.documento', '***.456.789-**')
        ->etc());

    expect($resposta->getContent())->not->toContain('123.456.789');
});

it('nao inventa pagador na cobranca que nunca teve um', function () {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}")
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('cobrancas.0.pagador', null)
            ->etc());
});

it('mostra a cobranca reconhecida na mao sem identificador de provedor', function () {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    // A cobranca do provedor sai do caminho para que a confirmacao manual crie
    // a dela — a que nasce de proposito sem identificador externo, porque
    // provedor nenhum participou dela.
    $inscricao->pagamentos()->update(['situacao' => SituacaoPagamento::Cancelado->value]);

    $this->actingAs(Cenario::usuarioCom('administrador'))
        ->post("/admin/inscricoes/{$inscricao->id}/confirmar-pagamento", [
            'metodo' => 'dinheiro',
            'observacao' => 'Entregou em espécie na secretaria.',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs(Cenario::usuarioCom('organizador'))
        ->get("/admin/inscricoes/{$inscricao->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('cobrancas.0.id_externo', null)
            ->where('cobrancas.0.origem_manual', true)
            ->etc());
});

it('recusa com 403 quem nao pode ver inscricoes', function () {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $this->actingAs(Cenario::usuarioCom())
        ->get("/admin/inscricoes/{$inscricao->id}")
        ->assertForbidden();
});

describe('cancelamento pela tela', function () {
    it('exige motivo', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/inscricoes/{$inscricao->id}/cancelar", ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
    });

    it('cancela com motivo e devolve a vaga', function () {
        Event::fake([InscricaoCancelada::class]);

        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();

        expect($cenario->evento->fresh()->vagas_reservadas)->toBe(1);

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/inscricoes/{$inscricao->id}/cancelar", ['motivo' => 'A pessoa desistiu por telefone.'])
            ->assertSessionHasNoErrors();

        $inscricao->refresh();

        expect($inscricao->situacao)->toBe(SituacaoInscricao::Cancelada)
            ->and($inscricao->motivo_cancelamento)->toBe('A pessoa desistiu por telefone.')
            ->and($cenario->evento->fresh()->vagas_reservadas)->toBe(0)
            ->and($cenario->futebol->fresh()->vagas_reservadas)->toBe(0);

        Event::assertDispatched(InscricaoCancelada::class);
    });

    it('recusa com 403 quem nao tem a permissao de cancelar', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom())
            ->post("/admin/inscricoes/{$inscricao->id}/cancelar", ['motivo' => 'Qualquer motivo escrito.'])
            ->assertForbidden();
    });
});

describe('confirmacao manual pela tela', function () {
    it('recusa com 403 o organizador', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('organizador'))
            ->post("/admin/inscricoes/{$inscricao->id}/confirmar-pagamento", [
                'metodo' => 'dinheiro',
                'observacao' => 'Recebido em espécie na secretaria.',
            ])
            ->assertForbidden();

        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
    });

    it('deixa o administrador confirmar e registra a origem manual', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();

        $administrador = Cenario::usuarioCom('administrador');

        $this->actingAs($administrador)
            ->post("/admin/inscricoes/{$inscricao->id}/confirmar-pagamento", [
                'metodo' => 'dinheiro',
                'observacao' => 'Recebido em espécie na secretaria.',
            ])
            ->assertSessionHasNoErrors();

        $inscricao->refresh();
        $pagamento = $inscricao->pagamentos()->latest('id')->firstOrFail();

        expect($inscricao->situacao)->toBe(SituacaoInscricao::Confirmada)
            ->and($pagamento->metadados['origem'] ?? null)->toBe('manual')
            ->and($pagamento->metadados['responsavel']['id'] ?? null)->toBe($administrador->id)
            ->and($pagamento->id_externo)->not->toBe('');
    });

    it('exige observacao', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('administrador'))
            ->post("/admin/inscricoes/{$inscricao->id}/confirmar-pagamento", [
                'metodo' => 'dinheiro',
                'observacao' => '',
            ])
            ->assertSessionHasErrors('observacao');
    });

    it('recusa Pix, que quem reconhece e o provedor', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();

        $this->actingAs(Cenario::usuarioCom('administrador'))
            ->post("/admin/inscricoes/{$inscricao->id}/confirmar-pagamento", [
                'metodo' => 'pix',
                'observacao' => 'Chegou o comprovante por Pix.',
            ])
            ->assertSessionHasErrors('metodo');
    });

    it('explica em portugues quando a inscricao ja expirou', function () {
        $cenario = CenarioInscricao::montar();
        $inscricao = $cenario->inscrever();
        $inscricao->forceFill(['situacao' => SituacaoInscricao::Expirada->value])->save();

        $this->actingAs(Cenario::usuarioCom('administrador'))
            ->post("/admin/inscricoes/{$inscricao->id}/confirmar-pagamento", [
                'metodo' => 'dinheiro',
                'observacao' => 'Trouxe o dinheiro depois do prazo.',
            ])
            ->assertSessionHasErrors('observacao');

        expect($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::Expirada);
    });
});
