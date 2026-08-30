<?php

declare(strict_types=1);

use App\Actions\Pagamentos\AtivarAmbientePagamento;
use App\Actions\Pagamentos\SalvarCredencialPagamento;
use App\Enums\AcaoAuditada;
use App\Enums\AmbientePagamento;
use App\Models\CredencialPagamento;
use App\Models\LogAuditoria;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Cenario;

/**
 * O rastro da tela de credenciais — e o que ele NAO pode conter.
 *
 * Este arquivo e o irmao do AuditoriaTest da Fase 9, que prova o mesmo para
 * CPF e para o codigo Pix. A pergunta e a mesma e a resposta precisa ser a
 * mesma: a auditoria guarda *que* algo mudou, nunca *o que* era. Uma
 * auditoria que guardasse a credencial da instituicao financeira seria um
 * segundo lugar de onde vaza-la — e um lugar que, por decisao (D-77/D-78),
 * ninguem consegue limpar depois.
 */
beforeEach(function (): void {
    Cenario::semearPapeis();
});

/** Os valores que, se aparecerem em qualquer lugar do rastro, sao vazamento. */
const VALORES_QUE_NAO_PODEM_VAZAR = [
    'Client_Id_Secreto_Da_Efi_998877',
    'Chave_Secreta_Da_Efi_112233445566',
    'chave-pix-da-conta@example.com',
    'Hmac_Do_Webhook_Aabbccddeeff',
    'conteudo-do-certificado-de-teste',
];

/**
 * @param  array<string, string|null>  $ajustes
 * @return array<string, string|null>
 */
function valoresDeCredencial(array $ajustes = []): array
{
    return array_merge([
        'client_id' => 'Client_Id_Secreto_Da_Efi_998877',
        'client_secret' => 'Chave_Secreta_Da_Efi_112233445566',
        'chave_pix' => 'chave-pix-da-conta@example.com',
        'webhook_hmac' => 'Hmac_Do_Webhook_Aabbccddeeff',
    ], $ajustes);
}

function certificadoEnviado(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'certificado-da-efi.pem',
        "-----BEGIN CERTIFICATE-----\nconteudo-do-certificado-de-teste\n-----END CERTIFICATE-----\n"
    );
}

it('registra a alteracao da credencial sem guardar um unico valor', function (): void {
    $administrador = Cenario::usuarioCom('administrador');

    app(SalvarCredencialPagamento::class)(
        AmbientePagamento::Homologacao,
        valoresDeCredencial(),
        certificadoEnviado(),
        $administrador,
    );

    $registro = LogAuditoria::query()->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->acao)->toBe(AcaoAuditada::AlterouCredencialPagamento)
        ->and($registro->entidade)->toBe('credencial-pagamento')
        ->and($registro->usuario_id)->toBe($administrador->id);

    // O rastro diz o que a pessoa precisa saber: quais campos mexeram.
    expect($registro->dados['campos_alterados'])
        ->toContain('client_id', 'client_secret', 'chave_pix', 'webhook_hmac', 'certificado')
        ->and($registro->dados['ambiente'])->toBe('homologacao')
        ->and($registro->dados['cadastro'])->toBe('criado');

    // E nao diz nada do que ela nao precisa. A varredura e sobre a LINHA CRUA
    // do banco, e nao sobre o model: o filtro de dado sensivel poderia estar
    // mascarando na leitura e gravando em claro.
    $linha = DB::table('logs_auditoria')->where('id', $registro->id)->first();
    $bruto = json_encode((array) $linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach (VALORES_QUE_NAO_PODEM_VAZAR as $valor) {
        expect($bruto)->not->toContain($valor);
    }

    expect($bruto)->not->toContain('BEGIN CERTIFICATE');
});

it('registra a troca de ambiente com o nome do anterior e o do novo', function (): void {
    $administrador = Cenario::usuarioCom('administrador');

    $salvar = app(SalvarCredencialPagamento::class);
    $salvar(AmbientePagamento::Homologacao, valoresDeCredencial(), certificadoEnviado(), $administrador);
    $salvar(AmbientePagamento::Producao, valoresDeCredencial(), certificadoEnviado(), $administrador);

    $ativar = app(AtivarAmbientePagamento::class);
    $ativar(AmbientePagamento::Homologacao, $administrador);
    $ativar(AmbientePagamento::Producao, $administrador);

    $registro = LogAuditoria::query()->latest('id')->first();

    // O nome do ambiente nao e segredo — e justamente o que quem revisa o
    // sistema precisa encontrar depois: quando o evento passou a cobrar de
    // verdade, e por ordem de quem.
    expect($registro->acao)->toBe(AcaoAuditada::AlterouCredencialPagamento)
        ->and($registro->dados['ambiente_anterior'])->toBe('homologacao')
        ->and($registro->dados['ambiente_novo'])->toBe('producao')
        ->and($registro->motivo)->toBe('Troca do ambiente ativo do provedor de pagamento')
        ->and($registro->usuario_id)->toBe($administrador->id);
});

it('nao desfaz a gravacao quando a auditoria falha', function (): void {
    $administrador = Cenario::usuarioCom('administrador');

    // Um servico de auditoria que sempre estoura. A Fase 9 decidiu que
    // auditoria e testemunha, nao porteiro: perder o rastro e ruim, mas perder
    // a credencial que a pessoa acabou de cadastrar seria pior — e ela nao
    // teria como saber que precisa digitar tudo de novo.
    app()->bind(RegistrarAcao::class, fn (): RegistrarAcao => new class extends RegistrarAcao
    {
        public function __invoke(
            AcaoAuditada $acao,
            string $entidade,
            ?int $entidadeId = null,
            array $dados = [],
            ?string $motivo = null,
            ?User $responsavel = null,
        ): ?LogAuditoria {
            throw new RuntimeException('auditoria fora do ar');
        }
    });

    try {
        app(SalvarCredencialPagamento::class)(
            AmbientePagamento::Homologacao,
            valoresDeCredencial(),
            certificadoEnviado(),
            $administrador,
        );
    } catch (Throwable) {
        // O que importa nao e se a excecao subiu, e se a credencial ficou.
    }

    $credencial = CredencialPagamento::query()->where('ambiente', 'homologacao')->first();

    expect($credencial)->not->toBeNull()
        ->and($credencial->client_id)->toBe('Client_Id_Secreto_Da_Efi_998877');
});

it('nunca guarda valor nenhum em nenhum registro de auditoria desta tela', function (): void {
    $administrador = Cenario::usuarioCom('administrador');

    $salvar = app(SalvarCredencialPagamento::class);
    $salvar(AmbientePagamento::Homologacao, valoresDeCredencial(), certificadoEnviado(), $administrador);
    // Uma segunda gravacao, para exercitar tambem o caminho de "atualizado".
    $salvar(AmbientePagamento::Homologacao, valoresDeCredencial(['client_id' => 'Outro_Client_Id_5566']), null, $administrador);
    app(AtivarAmbientePagamento::class)(AmbientePagamento::Homologacao, $administrador);

    // Todos os registros da tabela, de uma vez, direto do banco.
    $bruto = DB::table('logs_auditoria')->get()->toJson();

    foreach ([...VALORES_QUE_NAO_PODEM_VAZAR, 'Outro_Client_Id_5566'] as $valor) {
        expect($bruto)->not->toContain($valor);
    }

    expect(LogAuditoria::query()->count())->toBe(3);
});
