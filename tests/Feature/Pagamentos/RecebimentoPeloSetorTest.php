<?php

declare(strict_types=1);

use App\Actions\Pagamentos\CancelarPagamento;
use App\Actions\Pagamentos\CriarPagamentoDaInscricao;
use App\Contracts\Payments\PaymentGateway;
use App\DTOs\Payments\CreatePaymentData;
use App\DTOs\Payments\PaymentResult;
use App\DTOs\Payments\PaymentStatusResult;
use App\DTOs\Payments\RefundResult;
use App\DTOs\Payments\WebhookRequestData;
use App\DTOs\Payments\WebhookResult;
use App\Enums\FormaRecebimento;
use App\Enums\SituacaoPagamento;
use App\Exceptions\Pagamentos\SetorSemChavePixException;
use App\Models\Cidade;
use App\Models\Evento;
use App\Models\Inscricao;
use App\Models\Pagamento;
use App\Models\User;
use App\Services\Pagamentos\MontadorDeBrCodePix;
use Database\Seeders\PapeisSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Admin\Cenario as CenarioAdmin;
use Tests\Feature\Inscricoes\Cenario;

/*
 * O recebimento pela chave Pix do responsavel do setor.
 *
 * O que se prova aqui, em quatro frentes:
 *
 * 1. RN-S1 e RN-S12 — a forma de recebimento e do evento, e o modo "gateway"
 *    (o padrao) continua se comportando exatamente como sempre se comportou;
 * 2. RN-S2 — no modo "setor" NENHUMA chamada sai para o provedor. A prova nao e
 *    por inspecao: um provedor falso que EXPLODE ao ser chamado e registrado no
 *    lugar do de verdade, e a cobranca e emitida do mesmo jeito;
 * 3. RN-S13 — o BR Code montado localmente carrega o codigo da inscricao
 *    inteiro, o CRC16 fecha, e o payload sem descricao e byte a byte o mesmo de
 *    antes da extracao do montador. Refatoracao que muda payload de Pix e
 *    defeito, nao melhoria;
 * 4. RN-S4 — o evento nao e gravado no modo "setor" enquanto houver setor ativo
 *    sem chave ou sem responsavel, e a recusa NOMEIA quem falta.
 *
 * Prova junto, por travamento, uma verdade que ja existia por acidente: a
 * cobranca do setor tem `id_externo` nulo e por isso nunca entra na
 * reconciliacao nem no aviso de cancelamento ao provedor.
 */

/**
 * Um provedor de pagamento que se recusa a existir.
 *
 * Qualquer metodo que fale com o mundo lanca excecao. Ele e registrado no
 * lugar do provedor de verdade nos testes do modo "setor": se uma unica chamada
 * escapar, o teste falha com a frase abaixo em vez de passar em silencio.
 */
final class ProvedorQueNaoPodeSerChamado implements PaymentGateway
{
    public function name(): string
    {
        return 'nunca';
    }

    public function createPayment(CreatePaymentData $data): PaymentResult
    {
        throw new RuntimeException('O provedor foi chamado no modo setor — e ele nao pode ser (RN-S2).');
    }

    public function getPayment(string $externalId): PaymentStatusResult
    {
        throw new RuntimeException('O provedor foi consultado sobre uma cobranca do setor (RN-S2).');
    }

    public function cancelPayment(string $externalId): void
    {
        throw new RuntimeException('O provedor foi avisado do cancelamento de uma cobranca do setor (RN-S2).');
    }

    public function refundPayment(string $externalId, ?int $amountCents = null): RefundResult
    {
        throw new RuntimeException('O provedor foi chamado para estornar uma cobranca do setor (RN-S2).');
    }

    public function webhookRequest(Request $request): WebhookRequestData
    {
        throw new RuntimeException('Nao ha webhook no modo setor (RN-S2).');
    }

    public function verifyWebhookSignature(WebhookRequestData $request): bool
    {
        throw new RuntimeException('Nao ha webhook no modo setor (RN-S2).');
    }

    /**
     * @return list<WebhookResult>
     */
    public function parseWebhook(WebhookRequestData $request): array
    {
        throw new RuntimeException('Nao ha webhook no modo setor (RN-S2).');
    }
}

/**
 * O cenario do modo setor: evento recebendo pelo setor, setor com chave e
 * responsavel, e o provedor trocado por um que explode se for chamado.
 */
function cenarioDoSetor(array $atributosDoEvento = [], array $atributosDoSetor = []): Cenario
{
    $cenario = Cenario::montar(array_merge([
        'forma_recebimento' => FormaRecebimento::Setor,
        'prazo_pagamento_minutos' => 10080,
    ], $atributosDoEvento));

    $responsavel = User::factory()->create(['name' => 'Joana Responsavel']);

    $cenario->cidade->update(array_merge([
        'responsavel_id' => $responsavel->getKey(),
        'chave_pix' => 'joana.setor@example.com',
        'titular_chave_pix' => 'Joana da Silva',
    ], $atributosDoSetor));

    return $cenario;
}

// ---------------------------------------------------------------------------
// RN-S1 e RN-S12 — a forma e do evento, e o padrao nao muda nada
// ---------------------------------------------------------------------------

it('nasce recebendo pelo provedor, que e o comportamento de sempre', function (): void {
    $evento = Evento::factory()->create();

    expect($evento->forma_recebimento)->toBe(FormaRecebimento::Gateway)
        ->and($evento->recebePeloSetor())->toBeFalse();
});

it('continua emitindo a cobranca pelo provedor quando o evento recebe pelo gateway', function (): void {
    $cenario = Cenario::montar();
    $inscricao = $cenario->inscrever();

    $pagamento = app(CriarPagamentoDaInscricao::class)($inscricao);

    expect($pagamento->gateway)->toBe('fake')
        ->and($pagamento->id_externo)->not->toBeNull()
        ->and($pagamento->pix_copia_e_cola)->toContain('br.gov.bcb.pix');
});

it('guarda a forma no banco e recusa qualquer outro valor', function (): void {
    $evento = Evento::factory()->recebendoPeloSetor()->create();

    expect($evento->fresh()->recebePeloSetor())->toBeTrue();

    expect(fn () => DB::table('eventos')
        ->where('id', $evento->getKey())
        ->update(['forma_recebimento' => 'boleto']))
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// RN-S2 — nenhuma chamada sai para o provedor
// ---------------------------------------------------------------------------

it('emite a cobranca do setor sem tocar no provedor', function (): void {
    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    $cenario = cenarioDoSetor();
    $inscricao = $cenario->inscrever();

    $pagamento = app(CriarPagamentoDaInscricao::class)($inscricao);

    expect($pagamento->gateway)->toBe('setor')
        // Nenhum identificador de provedor e inventado: o provedor nao existiu.
        ->and($pagamento->id_externo)->toBeNull()
        ->and($pagamento->situacao)->toBe(SituacaoPagamento::Pendente)
        ->and($pagamento->valor_centavos)->toBe((int) $inscricao->valor_centavos)
        ->and($pagamento->expira_em?->toIso8601String())
        ->toBe($inscricao->prazo_pagamento?->toIso8601String())
        ->and($pagamento->pix_copia_e_cola)->toContain('joana.setor@example.com');
});

it('continua idempotente no modo setor: nao emite duas cobrancas para a mesma inscricao', function (): void {
    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    $cenario = cenarioDoSetor();
    $inscricao = $cenario->inscrever();

    $primeira = app(CriarPagamentoDaInscricao::class)($inscricao);
    $segunda = app(CriarPagamentoDaInscricao::class)($inscricao);

    expect($segunda->getKey())->toBe($primeira->getKey())
        ->and(Pagamento::query()->where('inscricao_id', $inscricao->getKey())->count())->toBe(1);
});

it('recusa emitir cobranca quando o setor perdeu a chave pix', function (): void {
    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    $cenario = cenarioDoSetor(atributosDoSetor: ['chave_pix' => null]);

    // A cobranca e emitida dentro da propria criacao da inscricao: a recusa
    // acontece ali, e nao depois. Falhar alto e melhor do que emitir um Pix
    // apontando para o vazio.
    expect(fn () => $cenario->inscrever())
        ->toThrow(SetorSemChavePixException::class);
});

it('nao consulta o provedor sobre a cobranca do setor na reconciliacao', function (): void {
    $cenario = cenarioDoSetor();
    $inscricao = $cenario->inscrever();

    $pagamento = app(CriarPagamentoDaInscricao::class)($inscricao);

    // A cobranca ja venceu: se a reconciliacao fosse olhar para ela, e agora.
    Pagamento::query()->whereKey($pagamento->getKey())->update([
        'expira_em' => Carbon::now()->subHour(),
    ]);

    // O provedor so entra em cena AQUI, depois de a cobranca existir — e o
    // comando nao pode alcanca-lo.
    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    Artisan::call('pagamentos:reconciliar');

    expect(Artisan::output())->toContain('Cobrancas consultadas: 0')
        ->and($pagamento->fresh()->situacao)->toBe(SituacaoPagamento::Pendente);
});

it('encerra a cobranca do setor sem avisar provedor nenhum', function (): void {
    $cenario = cenarioDoSetor();
    $inscricao = $cenario->inscrever();

    $pagamento = app(CriarPagamentoDaInscricao::class)($inscricao);

    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    // avisarProvedor: true de proposito — e o caminho normal do cancelamento.
    // Ele passa batido porque `id_externo` e nulo, e e isso que este teste trava.
    $encerrou = app(CancelarPagamento::class)($pagamento);

    expect($encerrou)->toBeTrue()
        ->and($pagamento->fresh()->situacao)->toBe(SituacaoPagamento::Cancelado);
});

// ---------------------------------------------------------------------------
// RN-S13 — o BR Code, extraido com prova
// ---------------------------------------------------------------------------

/**
 * A montagem do BR Code exatamente como ela era dentro do FakePaymentGateway,
 * antes da extracao — ponto flutuante do campo 54 incluido.
 *
 * Ela vive aqui, copiada, para que o teste compare o novo com o ANTIGO de
 * verdade, e nao com o novo chamado duas vezes.
 */
function payloadComoEraAntes(string $chave, int $centavos, string $nome, string $cidade, string $identificador): string
{
    $campo = fn (string $id, string $valor): string => $id.str_pad((string) mb_strlen($valor), 2, '0', STR_PAD_LEFT).$valor;
    $emv26 = fn (string $texto, int $limite): string => Str::upper(Str::substr(
        trim(preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($texto)) ?? ''), 0, $limite
    ));

    $conta = $campo('00', 'br.gov.bcb.pix').$campo('01', $chave);

    $payload = $campo('00', '01')
        .$campo('26', $conta)
        .$campo('52', '0000')
        .$campo('53', '986')
        .$campo('54', number_format($centavos / 100, 2, '.', ''))
        .$campo('58', 'BR')
        .$campo('59', $emv26($nome, 25))
        .$campo('60', $emv26($cidade, 15))
        .$campo('62', $campo('05', $emv26($identificador, 25)))
        .'6304';

    $crc = 0xFFFF;

    for ($i = 0; $i < strlen($payload); $i++) {
        $crc ^= ord($payload[$i]) << 8;

        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) !== 0
                ? (($crc << 1) ^ 0x1021) & 0xFFFF
                : ($crc << 1) & 0xFFFF;
        }
    }

    return $payload.Str::upper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

it('produz byte a byte o mesmo payload de antes quando nao recebe descricao', function (int $centavos): void {
    $montador = new MontadorDeBrCodePix;

    $agora = $montador->montar(
        chave: 'chave-pix-ficticia@example.com',
        valorCentavos: $centavos,
        nomeDoRecebedor: 'EVENTOS DEMO',
        cidade: 'SAO PAULO',
        identificador: 'BCDEFGHIJKLMNOPQRSTU',
    );

    expect($agora)->toBe(payloadComoEraAntes(
        'chave-pix-ficticia@example.com',
        $centavos,
        'EVENTOS DEMO',
        'SAO PAULO',
        'BCDEFGHIJKLMNOPQRSTU',
    ));
})->with([1_00, 10_05, 999_99, 1_234_56, 12_500, 1, 99, 100_000_00, 33_33, 7_07]);

it('converte centavos por recorte de inteiro, sem ponto flutuante', function (int $centavos, string $esperado): void {
    $payload = (new MontadorDeBrCodePix)->montar(
        chave: 'chave@example.com',
        valorCentavos: $centavos,
        nomeDoRecebedor: 'RECEBEDOR',
        cidade: 'MACEIO',
        identificador: 'ABC',
    );

    // O campo 54 vem com o tamanho na frente: "54" + dois digitos + o valor.
    expect($payload)->toContain('54'.str_pad((string) strlen($esperado), 2, '0', STR_PAD_LEFT).$esperado);
})->with([
    [1_00, '1.00'],
    [10_05, '10.05'],
    [999_99, '999.99'],
    [1_234_56, '1234.56'],
    [7, '0.07'],
]);

it('leva o codigo da inscricao inteiro na descricao e os ultimos 25 no identificador', function (): void {
    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    $cenario = cenarioDoSetor();
    $inscricao = $cenario->inscrever();

    $payload = (string) app(CriarPagamentoDaInscricao::class)($inscricao)->pix_copia_e_cola;
    $codigo = (string) $inscricao->codigo_publico;

    // A palavra sai em caixa alta porque o saneamento EMV e o mesmo do resto do
    // payload — e e ele que garante a igualdade byte a byte com o codigo
    // anterior. O ULID ja e alfanumerico maiusculo e atravessa intacto.
    expect(mb_strlen($codigo))->toBe(26)
        // O 26-02 carrega o ULID inteiro, com a palavra sem acento na frente.
        ->and($payload)->toContain('0236INSCRICAO '.$codigo)
        // O 62-05 leva os ultimos 25: e tudo o que cabe nele.
        ->and($payload)->toContain('62290525'.mb_substr($codigo, -25));
});

it('fecha o crc16 mesmo depois do campo novo da descricao', function (): void {
    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    $cenario = cenarioDoSetor();
    $payload = (string) app(CriarPagamentoDaInscricao::class)($cenario->inscrever())->pix_copia_e_cola;

    // O CRC16 e calculado sobre tudo o que vem antes dele, "6304" incluido.
    $semCrc = mb_substr($payload, 0, -4);
    $crc = 0xFFFF;

    for ($i = 0; $i < strlen($semCrc); $i++) {
        $crc ^= ord($semCrc[$i]) << 8;

        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }

    expect(mb_substr($payload, -4))->toBe(Str::upper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT)));
});

it('reencontra a inscricao pelo codigo lido do payload, que e o que quem confere faz na mao', function (): void {
    app()->instance(PaymentGateway::class, new ProvedorQueNaoPodeSerChamado);

    $cenario = cenarioDoSetor();
    $inscricao = $cenario->inscrever();

    $payload = (string) app(CriarPagamentoDaInscricao::class)($inscricao)->pix_copia_e_cola;

    // Quem confere le a descricao no extrato e procura o codigo no painel.
    preg_match('/INSCRICAO ([0-9A-Z]{26})/', $payload, $achado);

    expect($achado)->toHaveCount(2);

    $encontrada = Inscricao::query()->where('codigo_publico', $achado[1])->first();

    expect($encontrada?->getKey())->toBe($inscricao->getKey());
});

// ---------------------------------------------------------------------------
// RN-S4 — o setor precisa estar pronto antes de o evento ser salvo
// ---------------------------------------------------------------------------

it('recusa salvar o evento no modo setor nomeando os setores despreparados', function (): void {
    CenarioAdmin::semearPapeis();

    $administrador = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $evento = Evento::factory()->create();

    Cidade::factory()->create(['nome' => 'Setor Norte', 'uf' => 'AL']);
    Cidade::factory()->create([
        'nome' => 'Setor Sul',
        'uf' => 'AL',
        'responsavel_id' => $administrador->getKey(),
        'chave_pix' => null,
    ]);
    // Este esta pronto e NAO pode aparecer na mensagem.
    Cidade::factory()->create([
        'nome' => 'Setor Leste',
        'uf' => 'AL',
        'responsavel_id' => $administrador->getKey(),
        'chave_pix' => 'leste@example.com',
    ]);

    $resposta = $this->actingAs($administrador)->put("/admin/eventos/{$evento->getKey()}", dadosDoEventoParaFormulario($evento, [
        'forma_recebimento' => FormaRecebimento::Setor->value,
        'prazo_pagamento_minutos' => 10080,
    ]));

    $resposta->assertSessionHasErrors('forma_recebimento');

    $erro = (string) session('errors')->first('forma_recebimento');

    expect($erro)->toContain('Setor Norte')
        ->toContain('Setor Sul')
        ->not->toContain('Setor Leste');

    expect($evento->fresh()->recebePeloSetor())->toBeFalse();
});

it('aceita salvar no modo setor quando todo setor ativo esta pronto', function (): void {
    CenarioAdmin::semearPapeis();

    $administrador = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $evento = Evento::factory()->create();

    // Setor despreparado, porem INATIVO: ele nao aparece no formulario publico,
    // entao ninguem se inscreve por ele — e por isso ele nao trava nada.
    Cidade::factory()->inativa()->create(['nome' => 'Setor Antigo', 'uf' => 'AL']);
    Cidade::factory()->create([
        'nome' => 'Setor Central',
        'uf' => 'AL',
        'responsavel_id' => $administrador->getKey(),
        'chave_pix' => 'central@example.com',
    ]);

    $this->actingAs($administrador)
        ->put("/admin/eventos/{$evento->getKey()}", dadosDoEventoParaFormulario($evento, [
            'forma_recebimento' => FormaRecebimento::Setor->value,
            'prazo_pagamento_minutos' => 10080,
        ]))
        ->assertSessionHasNoErrors();

    expect($evento->fresh()->recebePeloSetor())->toBeTrue();
});

// ---------------------------------------------------------------------------
// RN-S8 — prazo minimo maior no modo setor
// ---------------------------------------------------------------------------

it('recusa prazo curto demais no modo setor e aceita o mesmo prazo no modo gateway', function (): void {
    CenarioAdmin::semearPapeis();

    $administrador = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $evento = Evento::factory()->create();

    Cidade::factory()->create([
        'nome' => 'Setor Central',
        'uf' => 'AL',
        'responsavel_id' => $administrador->getKey(),
        'chave_pix' => 'central@example.com',
    ]);

    $this->actingAs($administrador)
        ->put("/admin/eventos/{$evento->getKey()}", dadosDoEventoParaFormulario($evento, [
            'forma_recebimento' => FormaRecebimento::Setor->value,
            'prazo_pagamento_minutos' => 60,
        ]))
        ->assertSessionHasErrors('prazo_pagamento_minutos');

    // O MESMO prazo continua valendo no modo de sempre (RN-S12).
    $this->actingAs($administrador)
        ->put("/admin/eventos/{$evento->getKey()}", dadosDoEventoParaFormulario($evento, [
            'forma_recebimento' => FormaRecebimento::Gateway->value,
            'prazo_pagamento_minutos' => 60,
        ]))
        ->assertSessionHasNoErrors();
});

it('nao exige a forma no formulario: quem nao manda o campo continua no gateway', function (): void {
    CenarioAdmin::semearPapeis();

    $administrador = CenarioAdmin::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
    $evento = Evento::factory()->create();

    $dados = dadosDoEventoParaFormulario($evento);
    unset($dados['forma_recebimento']);

    $this->actingAs($administrador)
        ->put("/admin/eventos/{$evento->getKey()}", $dados)
        ->assertSessionHasNoErrors();

    expect($evento->fresh()->forma_recebimento)->toBe(FormaRecebimento::Gateway);
});

/**
 * O corpo completo do formulario do evento, para os testes que so querem mudar
 * um campo e nao reescrever os outros vinte.
 *
 * @param  array<string, mixed>  $sobrescritas
 * @return array<string, mixed>
 */
function dadosDoEventoParaFormulario(Evento $evento, array $sobrescritas = []): array
{
    return array_merge([
        'nome' => $evento->nome,
        'slug' => $evento->slug,
        'descricao' => $evento->descricao,
        'local' => $evento->local,
        'local_detalhe' => $evento->local_detalhe,
        'data_inicio' => $evento->data_inicio->toDateString(),
        'data_fim' => $evento->data_fim->toDateString(),
        'inscricoes_abrem_em' => $evento->inscricoes_abrem_em->format('Y-m-d\TH:i'),
        'inscricoes_fecham_em' => $evento->inscricoes_fecham_em->format('Y-m-d\TH:i'),
        'capacidade' => $evento->capacidade,
        'valor_centavos' => $evento->valor_centavos,
        'moeda' => $evento->moeda,
        'prazo_pagamento_minutos' => $evento->prazo_pagamento_minutos,
        'forma_recebimento' => $evento->forma_recebimento->value,
        'situacao' => $evento->situacao->value,
        'regulamento' => $evento->regulamento,
        'versao_termos' => $evento->versao_termos,
        'contato_email' => $evento->contato_email,
        'contato_telefone' => $evento->contato_telefone,
    ], $sobrescritas);
}
