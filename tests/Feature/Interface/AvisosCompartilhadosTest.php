<?php

declare(strict_types=1);

use App\Enums\SituacaoInscricao;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;
use Tests\Feature\Inscricoes\Cenario as CenarioInscricao;

/*
|--------------------------------------------------------------------------
| O aviso da ação, compartilhado com todas as telas
|--------------------------------------------------------------------------
|
| Toda resposta do servidor carrega `flash`: o que acabou de acontecer
| (`sucesso`), a recusa que não pertence a campo nenhum (`erro`) e um
| identificador descartável.
|
| O identificador é a razão de este arquivo existir. Cancelar duas inscrições
| seguidas produz EXATAMENTE a mesma frase, e a tela precisa perceber que houve
| um aviso novo na segunda vez — senão a segunda ação fica sem resposta visível,
| justo no uso repetido, que é o uso real do painel. Um teste que só verificasse
| "a mensagem chega" deixaria passar esse defeito inteiro.
|
*/

beforeEach(function (): void {
    Cenario::semearPapeis();
});

/** O `flash` que veio nas props compartilhadas desta resposta. */
function avisoDaResposta(TestResponse $resposta): array
{
    $flash = [];

    $resposta->assertInertia(function (Assert $pagina) use (&$flash): void {
        $flash = $pagina->toArray()['props']['flash'];
    });

    return $flash;
}

it('compartilha o aviso da ação com qualquer tela, sem que a tela precise pedir', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $organizador = Cenario::usuarioCom('organizador');

    $this->actingAs($organizador)
        ->from("/admin/inscricoes/{$inscricao->id}")
        ->post("/admin/inscricoes/{$inscricao->id}/cancelar", ['motivo' => 'Desistiu por telefone.'])
        ->assertRedirect("/admin/inscricoes/{$inscricao->id}");

    $aviso = avisoDaResposta(
        $this->actingAs($organizador)->get("/admin/inscricoes/{$inscricao->id}")
    );

    expect($aviso['sucesso'])->toBe('Inscrição cancelada e vaga devolvida.')
        ->and($aviso['erro'])->toBeNull()
        ->and($aviso['id'])->toBeString()->not->toBe('');
});

it('troca o identificador a cada resposta, mesmo quando a frase é a mesma', function (): void {
    $cenario = CenarioInscricao::montar();
    $primeira = $cenario->inscrever();
    $segunda = $cenario->inscrever($cenario->outraPessoa(1));

    $organizador = Cenario::usuarioCom('organizador');

    $cancelar = function (int $id) use ($organizador): array {
        $this->actingAs($organizador)
            ->from("/admin/inscricoes/{$id}")
            ->post("/admin/inscricoes/{$id}/cancelar", ['motivo' => 'Desistiu por telefone.']);

        return avisoDaResposta($this->actingAs($organizador)->get("/admin/inscricoes/{$id}"));
    };

    $antes = $cancelar((int) $primeira->getKey());
    $depois = $cancelar((int) $segunda->getKey());

    // A frase é idêntica — é essa a armadilha: uma tela que ficasse de olho no
    // texto não veria mudança nenhuma e não avisaria da segunda vez.
    expect($depois['sucesso'])->toBe($antes['sucesso'])
        ->and($depois['id'])->not->toBe($antes['id']);
});

it('não inventa aviso quando nada aconteceu', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $aviso = avisoDaResposta(
        $this->actingAs(Cenario::usuarioCom('organizador'))->get("/admin/inscricoes/{$inscricao->id}")
    );

    expect($aviso['sucesso'])->toBeNull()
        ->and($aviso['erro'])->toBeNull()
        // O identificador existe sempre: é ele que a tela vigia. Sem mensagem,
        // a tela simplesmente não mostra nada.
        ->and($aviso['id'])->toBeString()->not->toBe('');
});

it('manda a recusa de negócio pelo aviso, e não pendurada num campo inocente', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();
    $inscricao->forceFill(['situacao' => SituacaoInscricao::Expirada->value])->save();

    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)
        ->from("/admin/inscricoes/{$inscricao->id}")
        ->post("/admin/inscricoes/{$inscricao->id}/confirmar-pagamento", [
            'metodo' => 'dinheiro',
            'observacao' => 'Trouxe o dinheiro depois do prazo.',
        ])
        ->assertSessionHasNoErrors();

    $aviso = avisoDaResposta(
        $this->actingAs($administrador)->get("/admin/inscricoes/{$inscricao->id}")
    );

    expect($aviso['erro'])->toBeString()->not->toBe('')
        ->and($aviso['sucesso'])->toBeNull();
});

it('deixa erro de campo onde ele nasceu: ao lado do campo', function (): void {
    $cenario = CenarioInscricao::montar();
    $inscricao = $cenario->inscrever();

    $organizador = Cenario::usuarioCom('organizador');

    // Cancelar sem motivo é erro DE CAMPO: quem está na tela tem o que
    // corrigir, e a correção é ali mesmo (RN-T2).
    $this->actingAs($organizador)
        ->from("/admin/inscricoes/{$inscricao->id}")
        ->post("/admin/inscricoes/{$inscricao->id}/cancelar", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    $aviso = avisoDaResposta(
        $this->actingAs($organizador)->get("/admin/inscricoes/{$inscricao->id}")
    );

    expect($aviso['erro'])->toBeNull()
        ->and($aviso['sucesso'])->toBeNull()
        ->and($inscricao->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
});
