<?php

declare(strict_types=1);

use App\Enums\AcaoAuditada;
use App\Enums\FormaRecebimento;
use App\Enums\MetodoPagamento;
use App\Enums\SituacaoComprovante;
use App\Enums\SituacaoInscricao;
use App\Enums\SituacaoPagamento;
use App\Models\Cidade;
use App\Models\ComprovantePagamento;
use App\Models\Inscricao;
use App\Models\LogAuditoria;
use App\Models\Responsavel;
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
 * - RN-S11 — o arquivo so sai por rota autenticada, e com o mesmo escopo;
 * - RN-R6 — o escopo agora segue a cadeia
 *   `users -> responsaveis -> responsaveis_setores -> cidades`: QUALQUER
 *   responsavel do setor confere, um responsavel de dois setores alcanca os
 *   dois, e conta sem ficha (ou ficha sem conta) nao alcanca nada;
 * - RN-R7 — cada linha da fila diz para QUEM aquele Pix foi. Sem isso, com o
 *   sorteio se repetindo a cada cobranca (RN-R5), alguem aceitaria o
 *   comprovante de um Pix que caiu na conta de outra pessoa sem perceber.
 */

beforeEach(function (): void {
    Storage::fake('comprovantes');
    CenarioAdmin::semearPapeis();
});

/**
 * Um setor com UM responsavel, um evento recebendo por ele, e uma inscricao com
 * comprovante ja enviado.
 *
 * Um responsavel so: assim o sorteio da RN-R4 tem resposta unica e os testes de
 * escopo continuam falando de uma pessoa por setor. Quem prova o alcance de
 * varios responsaveis (RN-R6) monta o segundo por conta propria.
 *
 * @return array{responsavel: User, ficha: Responsavel, setor: Cidade, inscricao: Inscricao, comprovante: ComprovantePagamento}
 */
function setorComComprovante(string $nomeDoSetor, string $uf = 'AL'): array
{
    $conta = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_RESPONSAVEL_SETOR);

    $cenario = Cenario::montar([
        'forma_recebimento' => FormaRecebimento::Setor,
        'prazo_pagamento_minutos' => 10080,
    ]);

    $cenario->cidade->update(['nome' => $nomeDoSetor, 'uf' => $uf]);

    $ficha = Responsavel::factory()->create([
        'nome' => 'Titular do '.$nomeDoSetor,
        'chave_pix' => Str::slug($nomeDoSetor).'@example.com',
        'user_id' => $conta->getKey(),
    ]);

    $cenario->cidade->responsaveis()->sync([$ficha->getKey()]);

    $inscricao = $cenario->inscrever();

    $url = URL::temporarySignedRoute(
        'inscricoes.comprovante',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    test()->post($url, ['comprovante' => UploadedFile::fake()->image('recibo.jpg')])->assertRedirect();

    return [
        'responsavel' => $conta->fresh(),
        'ficha' => $ficha,
        'setor' => $cenario->cidade,
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

// ---------------------------------------------------------------------------
// RN-R6 — o escopo pela cadeia nova
// ---------------------------------------------------------------------------

it('deixa qualquer responsavel do setor conferir, e nao so o sorteado', function (): void {
    $a = setorComComprovante('Setor A');

    // O segundo responsavel do MESMO setor. Ele nunca foi sorteado para esta
    // cobranca — e mesmo assim confere, porque senao a fila do setor pararia
    // toda vez que o sorteado viajasse.
    $outraConta = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_RESPONSAVEL_SETOR);
    $outro = Responsavel::factory()->create([
        'nome' => 'Segundo do Setor A',
        'user_id' => $outraConta->getKey(),
    ]);
    $a['setor']->responsaveis()->attach($outro->getKey());

    $props = $this->actingAs($outraConta)
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect(collect($props['comprovantes'])->pluck('inscricao.codigo_publico')->all())
        ->toBe([$a['inscricao']->codigo_publico]);

    $this->actingAs($outraConta)
        ->post("/admin/comprovantes/{$a['comprovante']->getKey()}/aceitar", ['observacao' => 'Caiu na minha conta.'])
        ->assertSessionHasNoErrors();

    expect($a['inscricao']->fresh()->situacao)->toBe(SituacaoInscricao::Confirmada);
});

it('alcanca os dois setores quando a mesma pessoa atende os dois', function (): void {
    $a = setorComComprovante('Setor A');
    $b = setorComComprovante('Setor B');

    // A ficha de A passa a atender B tambem.
    $a['ficha']->setores()->attach($b['setor']->getKey());

    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect(collect($props['comprovantes'])->pluck('inscricao.codigo_publico')->all())
        ->toEqualCanonicalizing([$a['inscricao']->codigo_publico, $b['inscricao']->codigo_publico])
        ->and($props['escopo']['setores'])->toEqualCanonicalizing(['Setor A', 'Setor B']);
});

it('nao alcanca nada quem tem o papel mas nao tem ficha de responsavel', function (): void {
    $a = setorComComprovante('Setor A');

    // Ter login e ter papel nao e atender setor: a fila abre, e abre VAZIA.
    $solto = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_RESPONSAVEL_SETOR);

    $props = $this->actingAs($solto)
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['comprovantes'])->toBe([])
        ->and($props['escopo']['setores'])->toBe([]);

    // E a URL direta continua sendo porta fechada, e nao lista filtrada.
    $this->actingAs($solto)
        ->get("/admin/comprovantes/{$a['comprovante']->getKey()}/arquivo")
        ->assertForbidden();
});

it('nao da alcance a responsavel sem conta no painel', function (): void {
    $a = setorComComprovante('Setor A');

    // "Recebe, mas nao confere" (RN-R1): ele entra no sorteio e nao entra no
    // painel, porque nao ha por onde entrar.
    $semConta = Responsavel::factory()->semConta()->create();
    $a['setor']->responsaveis()->attach($semConta->getKey());

    expect($semConta->user)->toBeNull();

    // O escopo do outro nao muda por causa dele.
    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['escopo']['setores'])->toBe(['Setor A']);
});

// ---------------------------------------------------------------------------
// RN-R7 — a fila mostra quem recebeu
// ---------------------------------------------------------------------------

it('mostra na linha o nome e a chave do responsavel daquela cobranca', function (): void {
    $a = setorComComprovante('Setor A');

    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['comprovantes'][0]['recebedor'])->toBe([
        'nome' => 'Titular do Setor A',
        'chave_pix' => 'setor-a@example.com',
    ]);
});

it('continua mostrando quem recebeu depois de a chave da pessoa mudar de dono na tela', function (): void {
    $a = setorComComprovante('Setor A');

    // Um segundo responsavel entra no setor DEPOIS da cobranca emitida. A linha
    // continua dizendo quem recebeu aquele Pix — nao quem esta no setor hoje.
    $a['setor']->responsaveis()->attach(
        Responsavel::factory()->semConta()->create(['nome' => 'Chegou Depois'])->getKey()
    );

    $props = $this->actingAs($a['responsavel'])
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['comprovantes'][0]['recebedor']['nome'])->toBe('Titular do Setor A');
});

it('nao mostra recebedor em cobranca do modo gateway, onde ninguem foi sorteado', function (): void {
    $administrador = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

    // Evento pelo provedor: nao ha sorteio nenhum, e a fila nao inventa um nome.
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    $url = URL::temporarySignedRoute(
        'inscricoes.comprovante',
        Carbon::now()->addDays(8),
        ['codigo_publico' => $inscricao->codigo_publico],
    );

    ComprovantePagamento::create([
        'inscricao_id' => $inscricao->getKey(),
        'caminho' => 'comprovantes/gateway.jpg',
        'nome_original' => 'gateway.jpg',
        'mime' => 'image/jpeg',
        'tamanho_bytes' => 1024,
        'situacao' => SituacaoComprovante::Enviado,
        'enviado_em' => Carbon::now(),
    ]);

    $props = $this->actingAs($administrador)
        ->get('/admin/comprovantes')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['comprovantes'][0]['recebedor'])->toBeNull()
        ->and($url)->toBeString();
});
