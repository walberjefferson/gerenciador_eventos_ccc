<?php

declare(strict_types=1);

use App\Enums\AcaoAuditada;
use App\Enums\FormaRecebimento;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoComprovante;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\ComprovantePagamento;
use App\Models\Inscricao;
use App\Models\LogAuditoria;
use App\Models\User;
use Database\Seeders\PapeisSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Admin\Cenario as CenarioAdmin;
use Tests\Feature\Inscricoes\Cenario;

/*
 * A conferencia do comprovante, no painel.
 *
 * O que se prova aqui:
 *
 * - RN-S9 — quem confere ve APENAS o proprio setor. Nao e um filtro de tela: a
 *   inscricao de outro setor pedida pela URL direta responde 403, e o download
 *   do comprovante de outro setor tambem;
 * - RN-S10 — aceitar cai em ConfirmarPagamentoManual: a inscricao confirma, a
 *   vaga presa vira vaga paga, o metodo gravado e Transferencia e a auditoria
 *   fica com quem declarou e o que escreveu. Recusar exige motivo e NAO mexe na
 *   inscricao;
 * - RN-S11 — o arquivo so sai por rota autenticada, e com o mesmo escopo.
 */

beforeEach(function (): void {
    Storage::fake('comprovantes');
    CenarioAdmin::semearPapeis();
});

/**
 * Um setor com dono, um evento recebendo por ele, e uma inscricao com
 * comprovante ja enviado.
 *
 * @return array{responsavel: User, inscricao: Inscricao, comprovante: ComprovantePagamento}
 */
function setorComComprovante(string $nomeDoSetor, string $uf = 'AL'): array
{
    $responsavel = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_RESPONSAVEL_SETOR);

    $cenario = Cenario::montar([
        'forma_recebimento' => FormaRecebimento::Setor,
        'prazo_pagamento_minutos' => 10080,
    ]);

    $cenario->cidade->update([
        'nome' => $nomeDoSetor,
        'uf' => $uf,
        'responsavel_id' => $responsavel->getKey(),
        'chave_pix' => Str::slug($nomeDoSetor).'@example.com',
        'titular_chave_pix' => 'Titular do '.$nomeDoSetor,
    ]);

    $inscricao = $cenario->inscrever();

    $url = URL::temporarySignedRoute(
        'inscricoes.comprovante',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    test()->post($url, ['comprovante' => UploadedFile::fake()->image('recibo.jpg')])->assertRedirect();

    return [
        'responsavel' => $responsavel->fresh(),
        'inscricao' => $inscricao->fresh(),
        'comprovante' => ComprovantePagamento::query()
            ->where('inscricao_id', $inscricao->getKey())
            ->firstOrFail(),
    ];
}

// ---------------------------------------------------------------------------
// RN-S9 — escopo por setor, do servidor
// ---------------------------------------------------------------------------

it('mostra na fila apenas os comprovantes do proprio setor', function (): void {
    $a = setorComComprovante('Setor A');
    $b = setorComComprovante('Setor B');

    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    $codigos = collect($props['comprovantes'])->pluck('inscricao.codigo_publico')->all();

    expect($codigos)->toBe([$a['inscricao']->codigo_publico])
        ->and($props['escopo']['recortado_por_setor'])->toBeTrue()
        ->and($props['escopo']['setores'])->toBe(['Setor A'])
        ->and($codigos)->not->toContain($b['inscricao']->codigo_publico);
});

it('recusa com 403 o comprovante de outro setor pedido pela url direta', function (): void {
    $a = setorComComprovante('Setor A');
    $b = setorComComprovante('Setor B');

    // Nao e lista filtrada: e porta fechada.
    $this->actingAs($a['responsavel'])
        ->get("/admin/comprovantes/{$b['comprovante']->getKey()}/arquivo")
        ->assertForbidden();

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$b['comprovante']->getKey()}/aceitar", ['observacao' => 'Confere o valor.'])
        ->assertForbidden();

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$b['comprovante']->getKey()}/recusar", ['motivo' => 'Nao confere.'])
        ->assertForbidden();

    expect($b['comprovante']->fresh()->situacao)->toBe(SituacaoComprovante::Enviado)
        ->and($b['inscricao']->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
});

it('recusa com 403 a ficha da inscricao de outro setor pedida pela url direta', function (): void {
    $a = setorComComprovante('Setor A');
    $b = setorComComprovante('Setor B');

    $this->actingAs($a['responsavel'])
        ->get("/admin/inscricoes/{$b['inscricao']->getKey()}")
        ->assertForbidden();

    // A do proprio setor abre normalmente.
    $this->actingAs($a['responsavel'])
        ->get("/admin/inscricoes/{$a['inscricao']->getKey()}")
        ->assertOk();
});

it('recorta a lista de inscricoes pelo setor de quem confere', function (): void {
    $a = setorComComprovante('Setor A');
    setorComComprovante('Setor B');

    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/inscricoes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['inscricoes']['total'])->toBe(1)
        ->and($props['inscricoes']['dados'][0]['codigo_publico'])->toBe($a['inscricao']->codigo_publico);
});

it('nao amplia o recorte trocando o setor na url', function (): void {
    $a = setorComComprovante('Setor A');
    $b = setorComComprovante('Setor B');

    $setorDeB = $b['inscricao']->setor();

    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/inscricoes?cidade_id='.$setorDeB->getKey())
        ->assertOk()
        ->viewData('page')['props'];

    // As duas condicoes valem ao mesmo tempo: o filtro so estreita.
    expect($props['inscricoes']['total'])->toBe(0);
});

it('nao recorta nada para o administrador, que continua vendo tudo', function (): void {
    $a = setorComComprovante('Setor A');
    $b = setorComComprovante('Setor B');

    $administrador = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $props = $this->actingAs($administrador)
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    $codigos = collect($props['comprovantes'])->pluck('inscricao.codigo_publico')->all();

    expect($codigos)->toHaveCount(2)
        ->toContain($a['inscricao']->codigo_publico)
        ->toContain($b['inscricao']->codigo_publico)
        ->and($props['escopo']['recortado_por_setor'])->toBeFalse();

    $this->actingAs($administrador)
        ->get("/admin/comprovantes/{$b['comprovante']->getKey()}/arquivo")
        ->assertOk();
});

it('fecha a fila para quem organiza o evento', function (): void {
    setorComComprovante('Setor A');

    $this->actingAs(CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->get('/admin/comprovantes')
        ->assertForbidden();
});

it('nao muda a lista de inscricoes de quem organiza o evento', function (): void {
    $a = setorComComprovante('Setor A');
    $b = setorComComprovante('Setor B');

    $props = $this->actingAs(CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->get('/admin/inscricoes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['inscricoes']['total'])->toBe(2);

    $this->actingAs(CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR))
        ->get("/admin/inscricoes/{$b['inscricao']->getKey()}")
        ->assertOk();

    expect($a['inscricao']->codigo_publico)->not->toBe($b['inscricao']->codigo_publico);
});

// ---------------------------------------------------------------------------
// RN-S11 — o arquivo so sai por rota autenticada
// ---------------------------------------------------------------------------

it('entrega o arquivo com o nome que a pessoa deu, so para quem alcanca', function (): void {
    $a = setorComComprovante('Setor A');

    $resposta = $this->actingAs($a['responsavel'])
        ->get("/admin/comprovantes/{$a['comprovante']->getKey()}/arquivo")
        ->assertOk();

    expect($resposta->headers->get('content-disposition'))->toContain('recibo.jpg');

    // Sem login, nem chega ao controller.
    auth()->logout();
    $this->get("/admin/comprovantes/{$a['comprovante']->getKey()}/arquivo")->assertRedirect();
});

// ---------------------------------------------------------------------------
// RN-S10 — aceitar delega; recusar exige motivo e nao mexe na inscricao
// ---------------------------------------------------------------------------

it('aceita o comprovante e confirma a inscricao pelo caminho de sempre', function (): void {
    $a = setorComComprovante('Setor A');

    $evento = $a['inscricao']->evento;
    $reservadasAntes = (int) $evento->vagas_reservadas;
    $confirmadasAntes = (int) $evento->vagas_confirmadas;

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/aceitar", [
            'observacao' => 'Pix de R$ 150,00 recebido na conta do setor às 14h12.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $inscricao = $a['inscricao']->fresh();
    $comprovante = $a['comprovante']->fresh();
    $pagamento = $inscricao->pagamentos()->orderByDesc('id')->firstOrFail();
    $evento->refresh();

    expect($inscricao->situacao)->toBe(SituacaoInscricao::Confirmada)
        ->and($inscricao->confirmada_em)->not->toBeNull()
        ->and($comprovante->situacao)->toBe(SituacaoComprovante::Aceito)
        ->and((int) $comprovante->conferido_por_id)->toBe((int) $a['responsavel']->getKey())
        ->and($comprovante->conferido_em)->not->toBeNull()
        // A cobranca do setor virou paga, com o metodo que de fato aconteceu.
        ->and($pagamento->situacao)->toBe(SituacaoPagamento::Pago)
        ->and($pagamento->metodo)->toBe(MetodoPagamento::Transferencia)
        ->and($pagamento->id_externo)->toBeNull()
        // A vaga presa virou vaga paga.
        ->and((int) $evento->vagas_reservadas)->toBe($reservadasAntes - 1)
        ->and((int) $evento->vagas_confirmadas)->toBe($confirmadasAntes + 1);
});

it('grava a auditoria da confirmacao com quem declarou e o que escreveu', function (): void {
    $a = setorComComprovante('Setor A');

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/aceitar", [
            'observacao' => 'Comprovante confere com o valor e com o nome.',
        ])
        ->assertSessionHasNoErrors();

    $registro = LogAuditoria::query()
        ->where('acao', AcaoAuditada::ConfirmouPagamentoManual->value)
        ->firstOrFail();

    expect((int) $registro->usuario_id)->toBe((int) $a['responsavel']->getKey())
        ->and($registro->motivo)->toBe('Comprovante confere com o valor e com o nome.');

    // E a observacao tambem fica na cobranca, como em qualquer confirmacao manual.
    $pagamento = $a['inscricao']->fresh()->pagamentos()->orderByDesc('id')->firstOrFail();

    expect($pagamento->metadados['origem'] ?? null)->toBe('manual')
        ->and($pagamento->metadados['observacao'] ?? null)->toBe('Comprovante confere com o valor e com o nome.');
});

it('recusa aceitar sem observacao', function (): void {
    $a = setorComComprovante('Setor A');

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/aceitar", ['observacao' => ''])
        ->assertSessionHasErrors('observacao');

    expect($a['comprovante']->fresh()->situacao)->toBe(SituacaoComprovante::Enviado)
        ->and($a['inscricao']->fresh()->situacao)->toBe(SituacaoInscricao::AguardandoPagamento);
});

it('recusa o comprovante com o motivo escrito, sem mexer na inscricao', function (): void {
    $a = setorComComprovante('Setor A');

    $prazoAntes = $a['inscricao']->prazo_pagamento?->toIso8601String();

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/recusar", [
            'motivo' => 'O comprovante é de outra pessoa e de outro valor.',
        ])
        ->assertSessionHasNoErrors();

    $inscricao = $a['inscricao']->fresh();
    $comprovante = $a['comprovante']->fresh();

    expect($comprovante->situacao)->toBe(SituacaoComprovante::Recusado)
        ->and($comprovante->motivo_recusa)->toBe('O comprovante é de outra pessoa e de outro valor.')
        // A inscricao NAO foi tocada: ela segue aguardando pagamento ate o prazo.
        ->and($inscricao->situacao)->toBe(SituacaoInscricao::AguardandoPagamento)
        ->and($inscricao->prazo_pagamento?->toIso8601String())->toBe($prazoAntes)
        ->and($inscricao->pagamentoPendente())->not->toBeNull();
});

it('recusa a recusa sem motivo', function (): void {
    $a = setorComComprovante('Setor A');

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/recusar", ['motivo' => ''])
        ->assertSessionHasErrors('motivo');

    expect($a['comprovante']->fresh()->situacao)->toBe(SituacaoComprovante::Enviado);
});

it('recusa conferir duas vezes o mesmo comprovante', function (): void {
    $a = setorComComprovante('Setor A');

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/aceitar", ['observacao' => 'Confere.'])
        ->assertSessionHasNoErrors();

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/aceitar", ['observacao' => 'Confere de novo.'])
        ->assertSessionHasErrors('observacao');

    expect($a['inscricao']->fresh()->pagamentos()->count())->toBe(1);
});

it('depois da recusa o participante manda outro e ele volta para a fila', function (): void {
    $a = setorComComprovante('Setor A');

    $this->actingAs($a['responsavel'])
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/recusar", [
            'motivo' => 'A imagem está ilegível.',
        ])
        ->assertSessionHasNoErrors();

    $url = URL::temporarySignedRoute(
        'inscricoes.comprovante',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $a['inscricao']->codigo_publico],
    );

    auth()->logout();

    $this->post($url, ['comprovante' => UploadedFile::fake()->image('legivel.jpg')])->assertRedirect();

    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['comprovantes'])->toHaveCount(1)
        ->and($props['comprovantes'][0]['nome_original'])->toBe('legivel.jpg')
        // A recusa continua no historico.
        ->and(ComprovantePagamento::query()->count())->toBe(2);
});

it('ordena a fila pelo prazo mais proximo e marca o que vence em menos de 24 horas', function (): void {
    $administrador = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    $folgado = setorComComprovante('Setor Folgado');
    $apertado = setorComComprovante('Setor Apertado');

    Inscricao::query()->whereKey($apertado['inscricao']->getKey())->update([
        'prazo_pagamento' => Carbon::now()->addHours(6),
    ]);

    $props = $this->actingAs($administrador)
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['comprovantes'][0]['inscricao']['codigo_publico'])
        ->toBe($apertado['inscricao']->codigo_publico)
        ->and($props['comprovantes'][0]['urgente'])->toBeTrue()
        ->and($props['comprovantes'][1]['urgente'])->toBeFalse()
        ->and($props['horas_de_alerta'])->toBe(24);
});
