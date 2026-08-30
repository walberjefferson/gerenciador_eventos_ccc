<?php

declare(strict_types=1);

use App\Actions\Usuarios\GovernarConta;
use App\Enums\AcaoAuditada;
use App\Models\LogAuditoria;
use App\Models\User;
use Database\Seeders\PapeisSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\Cenario;

/**
 * A tela que diz quem entra no painel, com que papel, e ate quando.
 *
 * O que precisa ficar provado aqui e sempre a mesma coisa vista de angulos
 * diferentes: **o sistema nunca fica sem ninguem que responda por ele**. Nem
 * quando a pessoa se distrai e tenta rebaixar a si mesma, nem quando resta um
 * unico administrador, nem quando duas pessoas mexem no penultimo e no ultimo
 * ao mesmo tempo.
 */

/**
 * Alguem que pode gerenciar contas SEM ser administrador.
 *
 * Existe por uma razao concreta: quem tem o papel de administrador nunca
 * consegue chegar na trava do ultimo administrador pela tela — a trava de
 * "nao mexo em mim mesmo" barra antes. A permissao concedida direto (o
 * `spatie/laravel-permission` permite, e um `tinker` faz isso em uma linha) e o
 * caminho por onde a terceira trava e alcancada de verdade.
 */
function gerenteDeContas(): User
{
    $usuario = Cenario::usuarioCom('organizador');
    $usuario->givePermissionTo('usuarios.gerenciar');

    return $usuario->fresh();
}

/**
 * Monta o cenario numa conexao propria, fora da transacao do teste.
 *
 * Os processos que disputam a governanca sao processos de verdade: eles so
 * enxergam o que ja foi confirmado no banco.
 *
 * @return array{ator: int, primeiro: int, segundo: int}
 */
function cenarioCommitadoDeGovernanca(): array
{
    return naConexaoCommitadaDeContas(function (): array {
        Cenario::semearPapeis();

        $ator = gerenteDeContas();
        $primeiro = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        $segundo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        return [
            'ator' => (int) $ator->getKey(),
            'primeiro' => (int) $primeiro->getKey(),
            'segundo' => (int) $segundo->getKey(),
        ];
    });
}

/**
 * Executa alguma coisa com a conexao commitada como padrao.
 *
 * O nome carrega o sufixo "DeContas" porque o teste de cancelamento tem uma
 * funcao irma, com o mesmo corpo, e funcao declarada em arquivo de teste vive
 * no escopo global: duas com o mesmo nome derrubam a suite inteira com "cannot
 * redeclare". Extrair as duas para uma classe e o que a D-84 fez com a maquina
 * de disputa; enquanto isso nao acontece com esta, o nome distingue.
 */
function naConexaoCommitadaDeContas(callable $acao): mixed
{
    $padrao = config('database.default');

    config(['database.connections.disputa' => config("database.connections.{$padrao}")]);
    config(['database.default' => 'disputa']);

    try {
        return $acao();
    } finally {
        config(['database.default' => $padrao]);
    }
}

/**
 * Apaga o que foi gravado fora da transacao do teste.
 *
 * E a mesma excecao que o teste de cancelamento ja abre a regra de nunca
 * apagar registro: nao ha dominio aqui, e sim sujeira que a transacao do
 * RefreshDatabase nao alcanca e que estragaria a contagem de papeis dos testes
 * seguintes.
 *
 * @param  array{ator: int, primeiro: int, segundo: int}  $cenario
 */
function limparGovernancaCommitada(array $cenario): void
{
    $conexao = DB::connection('disputa');
    $ids = array_values($cenario);

    $conexao->table('logs_auditoria')
        ->whereIn('usuario_id', $ids)
        ->orWhere(fn ($consulta) => $consulta->where('entidade', 'usuario')->whereIn('entidade_id', $ids))
        ->delete();

    $conexao->table('model_has_roles')->whereIn('model_id', $ids)->delete();
    $conexao->table('model_has_permissions')->whereIn('model_id', $ids)->delete();
    $conexao->table('users')->whereIn('id', $ids)->delete();

    // Papeis e permissoes tambem foram semeados fora da transacao. O estado
    // commitado do banco de teste nao tem nenhum dos dois: limpar as tres
    // tabelas devolve exatamente o que havia antes.
    $conexao->table('role_has_permissions')->delete();
    $conexao->table('roles')->delete();
    $conexao->table('permissions')->delete();
}

/**
 * Dispara os dois processos que disputam os dois ultimos administradores.
 *
 * @param  array{ator: int, primeiro: int, segundo: int}  $cenario
 * @return list<string>
 */
function disputarGovernanca(array $cenario, float $margemDeLargada = 2.0): array
{
    $raiz = base_path();
    $script = $raiz.'/tests/Feature/Admin/scripts/governar-conta.php';
    $conexao = config('database.connections.pgsql');

    $ambiente = array_merge(getenv(), [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $conexao['host'],
        'DB_PORT' => (string) $conexao['port'],
        'DB_DATABASE' => (string) $conexao['database'],
        'DB_USERNAME' => (string) $conexao['username'],
        'DB_PASSWORD' => (string) $conexao['password'],
        // Cada processo com o seu proprio cache de permissoes, em memoria: o
        // que esta sendo provado e a trava no banco, e nao o cache do pacote.
        'CACHE_STORE' => 'array',
    ]);

    $largada = microtime(true) + $margemDeLargada;
    $processos = [];

    // Um rebaixa; o outro desativa. Sao os dois caminhos que tiram um
    // administrador do ar, e a trava precisa segurar os dois ao mesmo tempo.
    $tentativas = [
        [(string) $cenario['primeiro'], 'papel', PapeisSeeder::PAPEL_ORGANIZADOR],
        [(string) $cenario['segundo'], 'situacao', '0'],
    ];

    foreach ($tentativas as [$alvo, $acao, $valor]) {
        $tubos = [];

        $processo = proc_open(
            [PHP_BINARY, $script, $alvo, (string) $cenario['ator'], $acao, $valor, sprintf('%.6F', $largada)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubos,
            $raiz,
            $ambiente,
        );

        if (! is_resource($processo)) {
            throw new RuntimeException("Nao foi possivel iniciar o processo de {$acao}.");
        }

        $processos[] = [$processo, $tubos];
    }

    $saidas = [];

    foreach ($processos as [$processo, $tubos]) {
        $saida = trim((string) stream_get_contents($tubos[1]));
        $erro = trim((string) stream_get_contents($tubos[2]));

        fclose($tubos[1]);
        fclose($tubos[2]);
        proc_close($processo);

        $saidas[] = $saida !== '' ? $saida : 'sem saida: '.$erro;
    }

    return $saidas;
}

describe('a lista', function (): void {
    it('abre para o administrador, com papel e situacao de cada conta', function (): void {
        Cenario::semearPapeis();

        $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

        $this->actingAs($administrador)
            ->get('/admin/usuarios')
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Admin/Usuarios/Index')
                ->has('usuarios.dados', 2)
                ->has('opcoes.papeis', 2)
            );
    });

    it('marca a linha de quem esta olhando', function (): void {
        Cenario::semearPapeis();

        $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $resposta = $this->actingAs($administrador)->get('/admin/usuarios');

        $linhas = $resposta->viewData('page')['props']['usuarios']['dados'];

        expect($linhas)->toHaveCount(1)
            ->and($linhas[0]['sou_eu'])->toBeTrue()
            ->and($linhas[0]['papel'])->toBe(PapeisSeeder::PAPEL_ADMINISTRADOR)
            ->and($linhas[0]['ativo'])->toBeTrue();
    });

    it('filtra por papel, por situacao e por texto', function (): void {
        Cenario::semearPapeis();

        $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        $organizador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);
        $organizador->forceFill(['name' => 'Marta Organizadora', 'ativo' => false])->save();

        $comFiltro = fn (array $parametros): array => $this->actingAs($administrador)
            ->get('/admin/usuarios?'.http_build_query($parametros))
            ->viewData('page')['props']['usuarios']['dados'];

        expect($comFiltro(['papel' => PapeisSeeder::PAPEL_ORGANIZADOR]))->toHaveCount(1)
            ->and($comFiltro(['situacao' => 'desativados']))->toHaveCount(1)
            ->and($comFiltro(['situacao' => 'ativos']))->toHaveCount(1)
            ->and($comFiltro(['busca' => 'MARTA']))->toHaveCount(1)
            ->and($comFiltro(['busca' => 'ninguem']))->toHaveCount(0);
    });

    it('nao oferece rota para criar nem para excluir conta', function (): void {
        // Conta administrativa nasce por comando (D-51) e nao se apaga: a
        // auditoria guarda `usuario_id`. Se um dia alguem acrescentar uma
        // dessas rotas, este teste avisa.
        expect(Route::getRoutes()->getByName('admin.usuarios.store'))->toBeNull()
            ->and(Route::getRoutes()->getByName('admin.usuarios.destroy'))->toBeNull();
    });
});

describe('quem alcanca a tela', function (): void {
    it('fecha as quatro rotas para quem organiza o evento', function (): void {
        Cenario::semearPapeis();

        $organizador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);
        $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $this->actingAs($organizador)->get('/admin/usuarios')->assertForbidden();
        $this->actingAs($organizador)->get('/admin/papeis')->assertForbidden();

        $this->actingAs($organizador)
            ->put("/admin/usuarios/{$alvo->getKey()}/papel", ['papel' => PapeisSeeder::PAPEL_ORGANIZADOR])
            ->assertForbidden();

        $this->actingAs($organizador)
            ->put("/admin/usuarios/{$alvo->getKey()}/situacao", ['ativo' => false])
            ->assertForbidden();

        // E nada mudou.
        expect($alvo->fresh()->hasRole(PapeisSeeder::PAPEL_ADMINISTRADOR))->toBeTrue()
            ->and($alvo->fresh()->ativo)->toBeTrue();
    });

    it('mostra a matriz de papeis com o texto em portugues de cada permissao', function (): void {
        Cenario::semearPapeis();

        $resposta = $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR))
            ->get('/admin/papeis')
            ->assertOk();

        $props = $resposta->viewData('page')['props'];

        expect($props['permissoes'])->toHaveCount(count(PapeisSeeder::PERMISSOES))
            ->and($props['permissoes'][0]['explicacao'])->toBe(PapeisSeeder::PERMISSOES['painel.ver'])
            ->and($props['papeis'])->toHaveCount(2);

        $porNome = collect($props['papeis'])->keyBy('nome');

        expect($porNome[PapeisSeeder::PAPEL_ADMINISTRADOR]['quantas'])->toBe(count(PapeisSeeder::PERMISSOES))
            ->and($porNome[PapeisSeeder::PAPEL_ORGANIZADOR]['permissoes'])
            ->not->toContain('usuarios.gerenciar');
    });
});

describe('trocar o papel', function (): void {
    it('promove quem organiza a administrador e registra o antes e o depois', function (): void {
        Cenario::semearPapeis();

        $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

        $this->actingAs($administrador)
            ->from('/admin/usuarios')
            ->put("/admin/usuarios/{$alvo->getKey()}/papel", ['papel' => PapeisSeeder::PAPEL_ADMINISTRADOR])
            ->assertRedirect('/admin/usuarios')
            ->assertSessionHasNoErrors();

        expect($alvo->fresh()->hasRole(PapeisSeeder::PAPEL_ADMINISTRADOR))->toBeTrue();

        $registro = LogAuditoria::query()->where('acao', AcaoAuditada::PromoveuUsuario->value)->sole();

        expect($registro->usuario_id)->toBe($administrador->getKey())
            ->and($registro->entidade)->toBe('usuario')
            ->and($registro->entidade_id)->toBe((int) $alvo->getKey())
            ->and($registro->dados['papel'])->toBe([
                'antes' => PapeisSeeder::PAPEL_ORGANIZADOR,
                'depois' => PapeisSeeder::PAPEL_ADMINISTRADOR,
            ]);
    });

    it('nunca guarda senha nem hash no rastro', function (): void {
        Cenario::semearPapeis();

        $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

        $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR))
            ->put("/admin/usuarios/{$alvo->getKey()}/papel", ['papel' => PapeisSeeder::PAPEL_ADMINISTRADOR]);

        $bruto = json_encode(LogAuditoria::query()->sole()->dados, JSON_THROW_ON_ERROR);

        expect($bruto)->not->toContain('password')
            ->and($bruto)->not->toContain('senha')
            ->and($bruto)->not->toContain($alvo->fresh()->password);
    });

    it('recusa um papel que nao existe', function (): void {
        Cenario::semearPapeis();

        $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

        $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR))
            ->put("/admin/usuarios/{$alvo->getKey()}/papel", ['papel' => 'sindico'])
            ->assertSessionHasErrors('papel');

        expect($alvo->fresh()->hasRole(PapeisSeeder::PAPEL_ORGANIZADOR))->toBeTrue();
    });

    it('recusa que a pessoa rebaixe a si mesma, no servidor', function (): void {
        Cenario::semearPapeis();

        $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $this->actingAs($administrador)
            ->put("/admin/usuarios/{$administrador->getKey()}/papel", ['papel' => PapeisSeeder::PAPEL_ORGANIZADOR])
            ->assertSessionHasErrors('papel');

        expect($administrador->fresh()->hasRole(PapeisSeeder::PAPEL_ADMINISTRADOR))->toBeTrue()
            ->and(LogAuditoria::query()->count())->toBe(0);
    });

    it('recusa rebaixar o ultimo administrador ativo', function (): void {
        Cenario::semearPapeis();

        $ator = gerenteDeContas();
        $ultimo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $this->actingAs($ator)
            ->put("/admin/usuarios/{$ultimo->getKey()}/papel", ['papel' => PapeisSeeder::PAPEL_ORGANIZADOR])
            ->assertSessionHasErrors('papel');

        expect($ultimo->fresh()->hasRole(PapeisSeeder::PAPEL_ADMINISTRADOR))->toBeTrue()
            ->and(LogAuditoria::query()->count())->toBe(0);
    });

    it('deixa rebaixar o penultimo administrador', function (): void {
        Cenario::semearPapeis();

        $ator = gerenteDeContas();
        Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        $penultimo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $this->actingAs($ator)
            ->put("/admin/usuarios/{$penultimo->getKey()}/papel", ['papel' => PapeisSeeder::PAPEL_ORGANIZADOR])
            ->assertSessionHasNoErrors();

        expect($penultimo->fresh()->hasRole(PapeisSeeder::PAPEL_ORGANIZADOR))->toBeTrue();
    });
});

describe('trocar a situacao', function (): void {
    it('desativa quem saiu da organizacao e registra o antes e o depois', function (): void {
        Cenario::semearPapeis();

        $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);

        $this->actingAs($administrador)
            ->put("/admin/usuarios/{$alvo->getKey()}/situacao", ['ativo' => false])
            ->assertSessionHasNoErrors();

        expect($alvo->fresh()->ativo)->toBeFalse();

        $registro = LogAuditoria::query()->where('acao', AcaoAuditada::MudouSituacaoDoUsuario->value)->sole();

        expect($registro->usuario_id)->toBe($administrador->getKey())
            ->and($registro->entidade_id)->toBe((int) $alvo->getKey())
            ->and($registro->dados['ativo'])->toBe(['antes' => true, 'depois' => false]);
    });

    it('reativa quem voltou', function (): void {
        Cenario::semearPapeis();

        $alvo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ORGANIZADOR);
        $alvo->forceFill(['ativo' => false])->save();

        $this->actingAs(Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR))
            ->put("/admin/usuarios/{$alvo->getKey()}/situacao", ['ativo' => true])
            ->assertSessionHasNoErrors();

        expect($alvo->fresh()->ativo)->toBeTrue();
    });

    it('recusa que a pessoa desative a propria conta, no servidor', function (): void {
        Cenario::semearPapeis();

        $administrador = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $this->actingAs($administrador)
            ->put("/admin/usuarios/{$administrador->getKey()}/situacao", ['ativo' => false])
            ->assertSessionHasErrors('situacao');

        expect($administrador->fresh()->ativo)->toBeTrue()
            ->and(LogAuditoria::query()->count())->toBe(0);
    });

    it('recusa desativar o ultimo administrador ativo', function (): void {
        Cenario::semearPapeis();

        $ator = gerenteDeContas();
        $ultimo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $this->actingAs($ator)
            ->put("/admin/usuarios/{$ultimo->getKey()}/situacao", ['ativo' => false])
            ->assertSessionHasErrors('situacao');

        expect($ultimo->fresh()->ativo)->toBeTrue()
            ->and(LogAuditoria::query()->count())->toBe(0);
    });
});

describe('disputa real entre processos', function (): void {
    it('nao deixa o sistema chegar a zero administrador quando dois processos mexem no penultimo e no ultimo', function (): void {
        $cenario = cenarioCommitadoDeGovernanca();

        try {
            $saidas = disputarGovernanca($cenario);

            $erros = array_values(array_filter(
                $saidas,
                fn (string $saida): bool => ! in_array($saida, ['ok', 'recusado'], true),
            ));

            expect($erros)->toBe([], 'Algum processo falhou por motivo alheio a disputa: '.implode(' | ', $erros));

            // A afirmacao que da nome ao teste: sobrou administrador ativo.
            $conexao = DB::connection('disputa');

            $papelDeAdministrador = $conexao->table('roles')
                ->where('name', PapeisSeeder::PAPEL_ADMINISTRADOR)
                ->value('id');

            $administradoresAtivos = $conexao->table('users')
                ->where('ativo', true)
                ->whereIn('id', $conexao->table('model_has_roles')
                    ->where('role_id', $papelDeAdministrador)
                    ->select('model_id'))
                ->count();

            expect(array_count_values($saidas)['ok'] ?? 0)->toBe(1)
                ->and(array_count_values($saidas)['recusado'] ?? 0)->toBe(1)
                ->and($administradoresAtivos)->toBe(1);
        } finally {
            limparGovernancaCommitada($cenario);
        }
    });

    it('recusa tambem quando a mesma acao chega duas vezes seguidas', function (): void {
        Cenario::semearPapeis();

        $ator = gerenteDeContas();
        $primeiro = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);
        $segundo = Cenario::usuarioCom(PapeisSeeder::PAPEL_ADMINISTRADOR);

        $governar = app(GovernarConta::class);

        expect($governar->trocarSituacao($primeiro, false, $ator))->toBeNull()
            ->and($governar->trocarSituacao($segundo, false, $ator))->toBe(GovernarConta::RECUSA_ULTIMO_ADMINISTRADOR)
            ->and($segundo->fresh()->ativo)->toBeTrue();
    });
});
