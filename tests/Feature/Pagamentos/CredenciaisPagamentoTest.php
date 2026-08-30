<?php

declare(strict_types=1);

use App\Enums\AmbientePagamento;
use App\Exceptions\Payments\EfiException;
use App\Models\CredencialPagamento;
use App\Services\Payments\Efi\ConfiguracaoEfi;
use App\Services\Payments\Efi\EfiClient;
use Database\Seeders\PapeisSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Admin\Cenario;

/**
 * A guarda das credenciais do provedor de pagamento.
 *
 * Este arquivo prova as coisas que, se falharem, custam a conta bancaria do
 * evento — e por isso quase todas as provas sao contra o banco de verdade, e
 * nao contra o comportamento do PHP. Restricao que so existe em codigo nao
 * sobrevive a duas requisicoes ao mesmo tempo nem a um script de migracao de
 * dados; restricao que existe no PostgreSQL sobrevive as duas coisas.
 */

/** Um conteudo qualquer no formato de certificado, so para exercitar a guarda. */
function conteudoDeCertificadoFalso(): string
{
    return "-----BEGIN CERTIFICATE-----\nconteudo-ficticio-de-teste\n-----END CERTIFICATE-----\n";
}

/**
 * @param  array<string, mixed>  $atributos
 */
function credencialDeTeste(array $atributos = []): CredencialPagamento
{
    return CredencialPagamento::query()->create(array_merge([
        'gateway' => CredencialPagamento::GATEWAY_EFI,
        'ambiente' => AmbientePagamento::Homologacao,
        'client_id' => 'Client_Id_De_Teste_123456',
        'client_secret' => 'Client_Secret_De_Teste_ABCDEF',
        'chave_pix' => 'chave-pix-de-teste@example.com',
        'webhook_hmac' => 'Hmac_De_Teste_0123456789',
        'certificado' => conteudoDeCertificadoFalso(),
        'certificado_nome' => 'certificado-de-teste.p12',
        'ativo' => false,
    ], $atributos));
}

// ---------------------------------------------------------------------
// Guarda em repouso
// ---------------------------------------------------------------------

it('nao deixa nada legivel na linha crua do banco', function (): void {
    $credencial = credencialDeTeste();

    $linha = DB::table('credenciais_pagamento')->where('id', $credencial->id)->first();

    expect($linha)->not->toBeNull();

    $bruto = json_encode((array) $linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Nenhum dos cinco valores originais aparece em lugar nenhum da linha.
    foreach ([
        'Client_Id_De_Teste_123456',
        'Client_Secret_De_Teste_ABCDEF',
        'chave-pix-de-teste@example.com',
        'Hmac_De_Teste_0123456789',
        'conteudo-ficticio-de-teste',
        'BEGIN CERTIFICATE',
    ] as $valor) {
        expect($bruto)->not->toContain($valor);
    }

    // E o que esta guardado tem a cara do mecanismo de cifragem do Laravel —
    // o mesmo que ja protege o CPF do participante (D-08).
    foreach (CredencialPagamento::CAMPOS_SIGILOSOS as $campo) {
        $guardado = (string) $linha->{$campo};

        expect($guardado)->not->toBe('');

        $decodificado = json_decode((string) base64_decode($guardado, true), true);

        expect($decodificado)->toBeArray()
            ->and($decodificado)->toHaveKeys(['iv', 'value', 'mac']);
    }

    // Lido de volta pela aplicacao, o valor volta inteiro.
    expect($credencial->fresh()->client_id)->toBe('Client_Id_De_Teste_123456');
});

it('deixa os campos sigilosos fora de qualquer serializacao do model', function (): void {
    $json = credencialDeTeste()->toJson();

    // Nenhuma das cinco chaves sai na serializacao. A busca e pela chave com
    // aspas e dois-pontos de proposito: "certificado_nome" contem a palavra
    // "certificado" e e informacao legitima da tela.
    foreach (CredencialPagamento::CAMPOS_SIGILOSOS as $campo) {
        expect($json)->not->toContain('"'.$campo.'":');
    }

    foreach ([
        'Client_Id_De_Teste_123456',
        'Client_Secret_De_Teste_ABCDEF',
        'chave-pix-de-teste@example.com',
        'Hmac_De_Teste_0123456789',
        'conteudo-ficticio-de-teste',
    ] as $valor) {
        expect($json)->not->toContain($valor);
    }
});

it('nao devolve nenhum valor sigiloso no retrato que vai para a tela', function (): void {
    $tela = credencialDeTeste(['ativo' => true])->paraTela();

    $bruto = json_encode($tela, JSON_UNESCAPED_UNICODE);

    foreach ([
        'Client_Id_De_Teste_123456',
        'Client_Secret_De_Teste_ABCDEF',
        'chave-pix-de-teste@example.com',
        'Hmac_De_Teste_0123456789',
        'conteudo-ficticio-de-teste',
    ] as $valor) {
        expect($bruto)->not->toContain($valor);
    }

    // O que a tela recebe e a existencia do valor, nunca o valor.
    expect($tela['tem_client_id'])->toBeTrue()
        ->and($tela['tem_client_secret'])->toBeTrue()
        ->and($tela['tem_certificado'])->toBeTrue()
        ->and($tela['completa'])->toBeTrue()
        ->and($tela['certificado_nome'])->toBe('certificado-de-teste.p12');
});

// ---------------------------------------------------------------------
// O que o banco impede
// ---------------------------------------------------------------------

it('deixa o banco recusar um segundo ambiente ativo do mesmo provedor', function (): void {
    credencialDeTeste(['ambiente' => AmbientePagamento::Homologacao, 'ativo' => true]);

    // Sem passar por Action nenhuma: a insercao vai direto ao banco, que e
    // exatamente o caminho que uma verificacao em PHP nao cobriria.
    //
    // A tentativa acontece uma vez so, e nao duas: no PostgreSQL um comando
    // com erro envenena a transacao inteira, entao a segunda tentativa
    // devolveria "transacao abortada" em vez do erro que interessa.
    $erro = null;

    try {
        credencialDeTeste(['ambiente' => AmbientePagamento::Producao, 'ativo' => true]);
    } catch (QueryException $capturado) {
        $erro = $capturado;
    }

    expect($erro)->toBeInstanceOf(QueryException::class)
        // A recusa vem do indice parcial do banco, e nao de codigo nosso.
        ->and($erro?->getMessage())->toContain('credenciais_pagamento_um_ativo_por_gateway');
});

it('deixa o banco recusar dois cadastros do mesmo ambiente', function (): void {
    credencialDeTeste(['ambiente' => AmbientePagamento::Homologacao]);

    expect(fn () => credencialDeTeste(['ambiente' => AmbientePagamento::Homologacao]))
        ->toThrow(QueryException::class);
});

it('deixa o banco recusar um ambiente que nao existe', function (): void {
    expect(fn () => DB::table('credenciais_pagamento')->insert([
        'gateway' => 'efi',
        'ambiente' => 'produção',
        'ativo' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('credenciais_pagamento')->insert([
        'gateway' => 'efi',
        'ambiente' => 'PRODUCAO',
        'ativo' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('permite dois ambientes cadastrados desde que so um esteja ativo', function (): void {
    credencialDeTeste(['ambiente' => AmbientePagamento::Homologacao, 'ativo' => true]);
    credencialDeTeste(['ambiente' => AmbientePagamento::Producao, 'ativo' => false]);

    expect(CredencialPagamento::query()->count())->toBe(2)
        ->and(CredencialPagamento::ativaDe()?->ambiente)->toBe(AmbientePagamento::Homologacao);
});

// ---------------------------------------------------------------------
// O certificado em disco
// ---------------------------------------------------------------------

it('materializa o certificado com permissao restrita e fora do repositorio', function (): void {
    $credencial = credencialDeTeste();

    $caminho = $credencial->materializarCertificado();

    expect($caminho)->not->toBe('')
        ->and(is_file($caminho))->toBeTrue()
        ->and(file_get_contents($caminho))->toBe(conteudoDeCertificadoFalso());

    // 0600: so o dono le. Chave privada de conta bancaria legivel por outro
    // processo da maquina e um vazamento esperando acontecer.
    expect(substr(sprintf('%o', fileperms($caminho)), -4))->toBe('0600');

    // Dentro de storage/, que o .gitignore bloqueia — pasta e extensao. E com
    // a extensao do arquivo que foi enviado: o SDK decide como ler o
    // certificado por ela, e um .p12 com nome de .pem falharia com um erro de
    // TLS que nao explica nada.
    expect($caminho)->toStartWith(storage_path('certificados'))
        ->and($caminho)->toEndWith('.p12');

    // Nenhum arquivo temporario ficou para tras.
    expect(glob(storage_path('certificados').'/*.tmp'))->toBe([]);

    @unlink($caminho);
});

it('reescreve o certificado quando ele some do disco, porque o arquivo e cache', function (): void {
    $credencial = credencialDeTeste();

    $caminho = $credencial->materializarCertificado();
    @unlink($caminho);

    expect(is_file($caminho))->toBeFalse();

    // A fonte da verdade e o banco (DA-25): apagar o arquivo nao perde nada.
    expect($credencial->materializarCertificado())->toBe($caminho)
        ->and(file_get_contents($caminho))->toBe(conteudoDeCertificadoFalso());

    @unlink($caminho);
});

it('nao materializa arquivo nenhum quando nao ha certificado guardado', function (): void {
    $credencial = credencialDeTeste(['certificado' => null, 'certificado_nome' => null]);

    expect($credencial->materializarCertificado())->toBe('')
        ->and($credencial->estaCompleta())->toBeFalse();
});

// ---------------------------------------------------------------------
// De onde vem a configuracao (DA-26)
// ---------------------------------------------------------------------

it('cai para o arquivo de ambiente quando nao ha cadastro nenhum', function (): void {
    config([
        'payments.efi.environment' => 'homologacao',
        'payments.efi.client_id' => 'id-vindo-do-ambiente',
        'payments.efi.pix_key' => 'pix-do-ambiente@example.com',
        'payments.efi.cert_path' => '/caminho/do/ambiente.pem',
    ]);

    $configuracao = app(ConfiguracaoEfi::class);
    $configuracao->recarregar();

    expect($configuracao->origem())->toBe('ambiente')
        ->and($configuracao->clientId())->toBe('id-vindo-do-ambiente')
        ->and($configuracao->chavePix())->toBe('pix-do-ambiente@example.com')
        ->and($configuracao->caminhoDoCertificado())->toBe('/caminho/do/ambiente.pem')
        ->and($configuracao->ambiente())->toBe('homologacao');
});

it('faz o cadastro ativo vencer o arquivo de ambiente', function (): void {
    config([
        'payments.efi.environment' => 'homologacao',
        'payments.efi.client_id' => 'id-vindo-do-ambiente',
        'payments.efi.pix_key' => 'pix-do-ambiente@example.com',
    ]);

    credencialDeTeste([
        'ambiente' => AmbientePagamento::Producao,
        'client_id' => 'id-vindo-do-banco',
        'chave_pix' => 'pix-do-banco@example.com',
        'ativo' => true,
    ]);

    $configuracao = app(ConfiguracaoEfi::class);
    $configuracao->recarregar();

    expect($configuracao->origem())->toBe('banco')
        ->and($configuracao->clientId())->toBe('id-vindo-do-banco')
        ->and($configuracao->chavePix())->toBe('pix-do-banco@example.com')
        // O ambiente tambem vem do cadastro, e nao da variavel.
        ->and($configuracao->ambiente())->toBe('producao')
        ->and($configuracao->urlBase())->toBe('https://pix.api.efipay.com.br');

    // E o certificado vira arquivo em disco, com o conteudo que estava cifrado.
    $caminho = $configuracao->caminhoDoCertificado();

    expect(file_get_contents($caminho))->toBe(conteudoDeCertificadoFalso());

    @unlink($caminho);
});

it('nao completa com o arquivo de ambiente o que falta no cadastro ativo', function (): void {
    config([
        'payments.efi.client_id' => 'id-vindo-do-ambiente',
        'payments.efi.client_secret' => 'valor-vindo-do-ambiente',
    ]);

    // Um cadastro pela metade: identificacao preenchida, o resto em branco.
    credencialDeTeste([
        'client_secret' => null,
        'chave_pix' => null,
        'ativo' => true,
    ]);

    $configuracao = app(ConfiguracaoEfi::class);
    $configuracao->recarregar();

    // Misturar a identificacao de uma origem com a chave de outra produziria a
    // pior falha possivel: recusa da Efi na hora em que alguem tenta pagar.
    expect($configuracao->clientSecret())->toBe('')
        ->and($configuracao->chavePix())->toBe('')
        ->and($configuracao->estaCompleta())->toBeFalse();

    // E a recusa fala a lingua de quem cadastrou pela tela, nao a de quem
    // edita arquivo de ambiente.
    expect(fn () => $configuracao->exigirCompleta())->toThrow(EfiException::class);

    try {
        $configuracao->exigirCompleta();
    } catch (EfiException $erro) {
        expect($erro->getMessage())->toContain('Chave secreta da aplicacao')
            ->and($erro->getMessage())->not->toContain('EFI_CLIENT_')
            // E, como toda mensagem de erro do provedor, nao carrega valor.
            ->and($erro->getMessage())->not->toContain('id-vindo-do-ambiente');
    }
});

// ---------------------------------------------------------------------
// O token guardado
// ---------------------------------------------------------------------

it('guarda o token da Efi exatamente sob a chave que a configuracao calcula', function (): void {
    credencialDeTeste(['ambiente' => AmbientePagamento::Homologacao, 'ativo' => true]);

    app(ConfiguracaoEfi::class)->recarregar();

    // A prova e de comportamento, e nao de leitura de codigo: se o EfiClient
    // passar a usar outra chave, ele nao encontrara este valor e tentaria ir a
    // rede — e o teste fica vermelho sem que ninguem precise lembrar dele.
    Cache::put(ConfiguracaoEfi::chaveDoTokenDe('homologacao'), 'token-plantado-no-cache', 60);

    expect(app(EfiClient::class)->token())->toBe('token-plantado-no-cache');
});

it('joga fora o token dos dois ambientes quando a credencial muda', function (): void {
    Cache::put(ConfiguracaoEfi::chaveDoTokenDe('homologacao'), 'token-antigo-de-homologacao', 60);
    Cache::put(ConfiguracaoEfi::chaveDoTokenDe('producao'), 'token-antigo-de-producao', 60);

    app(ConfiguracaoEfi::class)->recarregar();

    // Dos dois, e nao so do ativo: trocar de ambiente muda qual token vale, e
    // o que sobrou do outro nao serve para nada. Sem isso, o sistema seguiria
    // usando a credencial antiga por ate uma hora.
    expect(Cache::get(ConfiguracaoEfi::chaveDoTokenDe('homologacao')))->toBeNull()
        ->and(Cache::get(ConfiguracaoEfi::chaveDoTokenDe('producao')))->toBeNull();
});

it('joga fora o token guardado ao salvar uma credencial pela tela', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    Cache::put(ConfiguracaoEfi::chaveDoTokenDe('homologacao'), 'token-da-credencial-antiga', 3600);

    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/homologacao', [
            'client_id' => 'Id_Novo_Da_Aplicacao_4321',
        ])
        ->assertRedirect();

    // Sem isso, o sistema seguiria falando com a Efi usando o token emitido
    // para a credencial antiga por ate uma hora — e o sintoma em producao
    // (recusa intermitente que se cura sozinha) e incompreensivel.
    expect(Cache::get(ConfiguracaoEfi::chaveDoTokenDe('homologacao')))->toBeNull();
});

// ---------------------------------------------------------------------
// Quem entra e quem nao entra
// ---------------------------------------------------------------------

it('da a permissao de credenciais so ao administrador', function (): void {
    Cenario::semearPapeis();

    expect(Cenario::usuarioCom('administrador')->can('pagamentos.credenciais'))->toBeTrue()
        ->and(Cenario::usuarioCom('organizador')->can('pagamentos.credenciais'))->toBeFalse()
        ->and(PapeisSeeder::permissoesDoOrganizador())->not->toContain('pagamentos.credenciais');
});

it('recusa a tela de credenciais para o organizador', function (): void {
    Cenario::semearPapeis();
    $organizador = Cenario::usuarioCom('organizador');

    // As quatro portas, e nao so a de leitura: uma delas aberta bastaria.
    $this->actingAs($organizador)->get('/admin/pagamentos/credenciais')->assertForbidden();
    $this->actingAs($organizador)->post('/admin/pagamentos/credenciais/homologacao')->assertForbidden();
    $this->actingAs($organizador)->post('/admin/pagamentos/credenciais/homologacao/ativar')->assertForbidden();
    $this->actingAs($organizador)->post('/admin/pagamentos/credenciais/homologacao/testar')->assertForbidden();
});

it('exige estar autenticado para abrir a tela de credenciais', function (): void {
    $this->get('/admin/pagamentos/credenciais')->assertRedirect('/login');
});

it('nao devolve nada sigiloso nas props da tela', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    credencialDeTeste(['ativo' => true]);

    $resposta = $this->actingAs($administrador)->get('/admin/pagamentos/credenciais')->assertOk();

    $resposta->assertInertia(fn (AssertableInertia $pagina) => $pagina
        ->component('Admin/Pagamentos/Credenciais/Index')
        ->has('ambientes', 2)
        ->where('ambientes.0.cadastro.tem_client_id', true)
        ->where('ambientes.0.cadastro.ativo', true)
        ->where('origem', 'banco')
    );

    // A varredura e sobre o HTML inteiro da resposta, que e onde as props do
    // Inertia viajam. Nenhum dos cinco valores pode estar ali — nem inteiro,
    // nem em pedaco reconhecivel.
    $conteudo = $resposta->getContent();

    foreach ([
        'Client_Id_De_Teste_123456',
        'Client_Secret_De_Teste_ABCDEF',
        'chave-pix-de-teste@example.com',
        'Hmac_De_Teste_0123456789',
        'conteudo-ficticio-de-teste',
        'BEGIN CERTIFICATE',
    ] as $valor) {
        expect($conteudo)->not->toContain($valor);
    }
});

// ---------------------------------------------------------------------
// Salvar
// ---------------------------------------------------------------------

it('mantem o valor guardado quando o campo chega vazio', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    credencialDeTeste();

    // Corrigir a chave Pix nao pode obrigar a redigitar a credencial inteira —
    // e nao ha como redigitar o que a tela nunca mostrou.
    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/homologacao', [
            'client_id' => '',
            'client_secret' => '',
            'chave_pix' => 'chave-pix-corrigida@example.com',
            'webhook_hmac' => '',
        ])
        ->assertRedirect();

    $credencial = CredencialPagamento::query()->where('ambiente', 'homologacao')->first();

    expect($credencial->chave_pix)->toBe('chave-pix-corrigida@example.com')
        // Em branco manteve. Jamais apagou.
        ->and($credencial->client_id)->toBe('Client_Id_De_Teste_123456')
        ->and($credencial->client_secret)->toBe('Client_Secret_De_Teste_ABCDEF')
        ->and($credencial->webhook_hmac)->toBe('Hmac_De_Teste_0123456789')
        ->and($credencial->certificado)->toBe(conteudoDeCertificadoFalso())
        ->and($credencial->atualizado_por_id)->toBe($administrador->id);
});

it('recusa um arquivo que nao abre como certificado', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/homologacao', [
            'certificado' => UploadedFile::fake()->createWithContent('contrato.pem', 'isto aqui nao e um certificado'),
        ])
        ->assertSessionHasErrors('certificado');

    expect(CredencialPagamento::query()->count())->toBe(0);
});

it('recusa um arquivo com extensao que nao e de certificado', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/homologacao', [
            'certificado' => UploadedFile::fake()->create('planilha.xlsx', 10),
        ])
        ->assertSessionHasErrors('certificado');
});

it('guarda o certificado e le a validade dele quando o formato permite', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $pedido = openssl_csr_new(['commonName' => 'certificado-de-teste'], $chave);
    $certificado = openssl_csr_sign($pedido, null, $chave, 30);
    openssl_x509_export($certificado, $pem);

    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/homologacao', [
            'certificado' => UploadedFile::fake()->createWithContent('efi-producao.pem', $pem),
        ])
        ->assertRedirect();

    $credencial = CredencialPagamento::query()->where('ambiente', 'homologacao')->first();

    expect($credencial->certificado)->toBe($pem)
        ->and($credencial->certificado_nome)->toBe('efi-producao.pem')
        ->and($credencial->certificado_expira_em)->not->toBeNull()
        ->and($credencial->certificado_expira_em->isFuture())->toBeTrue();
});

// ---------------------------------------------------------------------
// Ativar
// ---------------------------------------------------------------------

it('recusa ativar producao sem confirmacao explicita', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    credencialDeTeste(['ambiente' => AmbientePagamento::Producao]);

    // A confirmacao e cobrada no servidor, e nao so na tela: uma confirmacao
    // que vive so no navegador cai com um clique no lugar errado ou com uma
    // chamada feita fora da tela.
    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/producao/ativar')
        ->assertRedirect();

    expect(CredencialPagamento::ativaDe())->toBeNull();

    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/producao/ativar', ['confirmacao' => true])
        ->assertRedirect();

    expect(CredencialPagamento::ativaDe()?->ambiente)->toBe(AmbientePagamento::Producao);
});

it('troca o ambiente ativo sem nunca deixar dois ativos', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    credencialDeTeste(['ambiente' => AmbientePagamento::Homologacao]);
    credencialDeTeste(['ambiente' => AmbientePagamento::Producao]);

    $this->actingAs($administrador)->post('/admin/pagamentos/credenciais/homologacao/ativar');

    expect(CredencialPagamento::query()->where('ativo', true)->count())->toBe(1);

    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/producao/ativar', ['confirmacao' => true]);

    expect(CredencialPagamento::query()->where('ativo', true)->count())->toBe(1)
        ->and(CredencialPagamento::ativaDe()?->ambiente)->toBe(AmbientePagamento::Producao);
});

it('recusa ativar um cadastro incompleto', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    credencialDeTeste(['certificado' => null, 'certificado_nome' => null]);

    // Ativar um cadastro pela metade trocaria "o pagamento nao esta
    // configurado" por "o pagamento quebra na hora da inscricao".
    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/homologacao/ativar')
        ->assertSessionHas('erro');

    expect(CredencialPagamento::ativaDe())->toBeNull();
});

it('devolve 404 para um ambiente que nao existe', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/qualquer-coisa/ativar')
        ->assertNotFound();
});

// ---------------------------------------------------------------------
// Testar conexao
// ---------------------------------------------------------------------

it('diz o que falta quando o teste de conexao roda sem cadastro', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    $resposta = $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/producao/testar')
        ->assertSessionHas('teste');

    $teste = $resposta->getSession()->get('teste');

    expect($teste['sucesso'])->toBeFalse()
        ->and($teste['mensagem'])->toContain('Nao ha credencial cadastrada');
});

it('nao expoe valor nenhum na resposta do teste de conexao', function (): void {
    Cenario::semearPapeis();
    $administrador = Cenario::usuarioCom('administrador');

    credencialDeTeste(['chave_pix' => null]);

    $resposta = $this->actingAs($administrador)
        ->post('/admin/pagamentos/credenciais/homologacao/testar')
        ->assertSessionHas('teste');

    $teste = $resposta->getSession()->get('teste');

    expect($teste['sucesso'])->toBeFalse()
        // Diz o NOME do que falta, na lingua de quem cadastra pela tela.
        ->and($teste['mensagem'])->toContain('Chave Pix da conta recebedora')
        ->and($teste['mensagem'])->not->toContain('Client_Id_De_Teste_123456')
        ->and($teste['mensagem'])->not->toContain('Client_Secret_De_Teste_ABCDEF')
        ->and($teste['mensagem'])->not->toContain(storage_path('certificados'));
});
