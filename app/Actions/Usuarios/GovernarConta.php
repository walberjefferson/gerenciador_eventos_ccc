<?php

declare(strict_types=1);

namespace App\Actions\Usuarios;

use App\Enums\AcaoAuditada;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Database\Seeders\PapeisSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Quem entra no painel, com que papel, e ate quando.
 *
 * As duas unicas operacoes que governam uma conta administrativa moram aqui, e
 * nao no controller, por dois motivos concretos:
 *
 * 1. **As tres travas precisam valer sempre**, e nao so quando o pedido chega
 *    pela tela. Regra de seguranca escrita dentro de um metodo de controller
 *    vale para o formulario e para mais nada.
 * 2. **A trava do ultimo administrador precisa ser provada com processos de
 *    verdade** (D-84). O teste de concorrencia chama esta classe direto, do
 *    mesmo jeito que o `ConcorrenciaTest` chama a Action da inscricao; nao ha
 *    como duas conexoes disputarem a mesma linha atraves de um controller.
 *
 * **Nenhum metodo daqui cria conta.** Conta administrativa continua nascendo
 * por `php artisan usuario:criar-administrador` (D-51), e nenhum deles apaga
 * usuario: a auditoria guarda `usuario_id`, e apagar deixaria o historico
 * apontando para o vazio.
 *
 * Os metodos devolvem `null` quando a mudanca aconteceu, ou a **frase da
 * recusa** — em portugues, pronta para aparecer na tela. Nao lancam excecao
 * porque recusa aqui nao e defeito: e o sistema funcionando.
 */
class GovernarConta
{
    public const RECUSA_PROPRIO_PAPEL = 'Você não pode mudar o próprio papel. Peça a outra pessoa com acesso de administrador.';

    public const RECUSA_PROPRIA_SITUACAO = 'Você não pode desativar a própria conta. Peça a outra pessoa com acesso de administrador.';

    public const RECUSA_ULTIMO_ADMINISTRADOR = 'Esta é a única conta de administrador ativa. Sem nenhuma, ninguém consegue cadastrar credencial de pagamento nem confirmar pagamento na mão — e a saída seria rodar comando no servidor. Promova outra pessoa antes.';

    public function __construct(private readonly RegistrarAcao $registrarAcao) {}

    /**
     * Troca o papel de uma conta.
     *
     * @param  User  $responsavel  quem apertou o botao; e conferido contra o alvo
     * @return string|null a frase da recusa, ou null se o papel foi trocado
     */
    public function trocarPapel(User $usuario, string $papel, User $responsavel): ?string
    {
        // A conferencia de "sou eu mesmo" vem antes da transacao: ela nao
        // depende de nada no banco, e recusar cedo evita abrir transacao para
        // jogar fora.
        if ($responsavel->is($usuario)) {
            return self::RECUSA_PROPRIO_PAPEL;
        }

        return DB::transaction(function () use ($usuario, $papel, $responsavel): ?string {
            $administradores = $this->administradoresAtivosTravados();

            $papelAtual = $this->papelAtual($usuario);

            $perdeOPapel = $papel !== PapeisSeeder::PAPEL_ADMINISTRADOR
                && in_array((int) $usuario->getKey(), $administradores, true);

            if ($perdeOPapel && count($administradores) <= 1) {
                return self::RECUSA_ULTIMO_ADMINISTRADOR;
            }

            if ($papelAtual === $papel) {
                // Nada mudou. Nao gravar nada e proposital: auditoria cheia de
                // "mudou, mas continuou igual" esconde o que importa.
                return null;
            }

            $usuario->syncRoles([$papel]);

            $this->registrarAcao->__invoke(
                AcaoAuditada::PromoveuUsuario,
                'usuario',
                (int) $usuario->getKey(),
                [
                    'email' => $usuario->email,
                    'papel' => ['antes' => $papelAtual, 'depois' => $papel],
                ],
                responsavel: $responsavel,
            );

            return null;
        });
    }

    /**
     * Ativa ou desativa uma conta.
     *
     * @return string|null a frase da recusa, ou null se a situacao mudou
     */
    public function trocarSituacao(User $usuario, bool $ativo, User $responsavel): ?string
    {
        if ($responsavel->is($usuario)) {
            return self::RECUSA_PROPRIA_SITUACAO;
        }

        return DB::transaction(function () use ($usuario, $ativo, $responsavel): ?string {
            $administradores = $this->administradoresAtivosTravados();

            // So depois da trava a linha e relida: dentro da transacao, este
            // comando enxerga o que quem estava na frente ja confirmou.
            $usuario->refresh();

            $perdeOAcesso = ! $ativo && in_array((int) $usuario->getKey(), $administradores, true);

            if ($perdeOAcesso && count($administradores) <= 1) {
                return self::RECUSA_ULTIMO_ADMINISTRADOR;
            }

            if ($usuario->ativo === $ativo) {
                return null;
            }

            $antes = $usuario->ativo;

            $usuario->ativo = $ativo;
            $usuario->save();

            $this->registrarAcao->__invoke(
                AcaoAuditada::MudouSituacaoDoUsuario,
                'usuario',
                (int) $usuario->getKey(),
                [
                    'email' => $usuario->email,
                    'ativo' => ['antes' => $antes, 'depois' => $ativo],
                ],
                responsavel: $responsavel,
            );

            return null;
        });
    }

    /**
     * O papel que a conta tem agora, lido do banco.
     *
     * A relacao e relida de proposito, em vez de usar o que ja estiver
     * carregado no objeto: dentro da transacao, o valor precisa ser o do banco,
     * e nao o retrato que alguem carregou antes de a trava existir.
     */
    private function papelAtual(User $usuario): ?string
    {
        $papel = $usuario->roles()->pluck('name')->first();

        return $papel === null ? null : (string) $papel;
    }

    /**
     * Trava as linhas dos administradores ativos e devolve os identificadores.
     *
     * **Por que a trava e depois a contagem, em dois comandos.** A trava
     * (`FOR UPDATE`) faz quem chegar depois esperar; mas a linha travada e a
     * de `users`, e trocar de papel nao mexe em `users` — mexe em
     * `model_has_roles`. Se a contagem viesse do mesmo comando da trava, o
     * segundo processo acordaria com o retrato que tinha antes de esperar, e
     * dois rebaixamentos simultaneos chegariam a zero administrador.
     *
     * Com a contagem num comando seguinte, dentro da mesma transacao, o
     * PostgreSQL em READ COMMITTED da a ela um retrato novo — que ja inclui o
     * que o primeiro processo confirmou. E o mesmo cuidado que a reserva de
     * vaga toma (D-04), adaptado ao fato de que o dado disputado mora em outra
     * tabela.
     *
     * A ordem por `id` na trava e o que impede duas transacoes de travarem uma
     * esperando a outra (D-05).
     *
     * @return list<int>
     */
    private function administradoresAtivosTravados(): array
    {
        $this->administradoresAtivos()
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get(['users.id']);

        return $this->administradoresAtivos()
            ->pluck('users.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return Builder<User>
     */
    private function administradoresAtivos(): Builder
    {
        $tabelaDePapeis = (string) config('permission.table_names.roles', 'roles');

        return User::query()
            ->ativos()
            ->whereHas(
                'roles',
                fn (Builder $consulta): Builder => $consulta->where(
                    $tabelaDePapeis.'.name',
                    PapeisSeeder::PAPEL_ADMINISTRADOR,
                ),
            );
    }

    /**
     * Cria uma conta administrativa pela tela.
     *
     * Ate aqui a conta so nascia por `usuario:criar-administrador`, dentro do
     * container (D-51). O dono do produto reverteu essa decisao: quem responde
     * pelo sistema passa a cadastrar a equipe sem depender de alguem com acesso
     * ao servidor. O COMANDO CONTINUA EXISTINDO, e continua sendo o unico
     * caminho para a PRIMEIRA conta — quando ainda nao ha ninguem para abrir a
     * tela, nao ha tela.
     *
     * O e-mail e verificado na hora: quem cadastra a conta esta dizendo, com o
     * proprio acesso, que aquela pessoa e da equipe. Exigir que ela confirme o
     * e-mail depois so a impediria de entrar sem provar nada a mais.
     *
     * @param  array{name: string, email: string, password: string, papel: string}  $dados
     */
    public function criar(array $dados, User $responsavel): User
    {
        return DB::transaction(function () use ($dados, $responsavel): User {
            $usuario = new User;

            // `ativo` e `email_verified_at` ficam FORA do `$fillable` de
            // proposito — quem entra no sistema nao e campo de formulario que
            // se preenche em massa (ver o comentario no proprio Model). Por
            // isso os tres primeiros vao por `fill` e os dois de acesso, por
            // `forceFill`: a intencao de conceder acesso fica explicita aqui,
            // em vez de escondida numa lista de campos preenchiveis.
            $usuario->fill([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'password' => Hash::make($dados['password']),
            ]);

            // O e-mail nasce confirmado: quem cadastrou a conta esta dizendo,
            // com o proprio acesso, que aquela pessoa e da equipe. Sem isto ela
            // cairia na exigencia de e-mail verificado do grupo /admin e nao
            // entraria em lugar nenhum, sem o sistema explicar por que.
            $usuario->forceFill(['email_verified_at' => now(), 'ativo' => true])->save();

            $usuario->assignRole($dados['papel']);

            // O mesmo formato que o comando ja usava (CriarAdministrador:96):
            // e-mail e papel entram, a senha nao existe para a auditoria.
            $this->registrarAcao->__invoke(
                AcaoAuditada::CriouUsuarioAdministrativo,
                'usuario',
                (int) $usuario->getKey(),
                ['email' => $usuario->email, 'papel' => $dados['papel'], 'origem' => 'tela'],
                responsavel: $responsavel,
            );

            return $usuario;
        });
    }

    /**
     * Corrige nome e e-mail de uma conta.
     *
     * Vale inclusive para a PROPRIA conta de quem esta mexendo — diferente do
     * papel e da situacao, corrigir o proprio nome nao tranca ninguem para fora.
     *
     * O e-mail e o login: trocar o e-mail de uma conta muda por onde ela entra,
     * e por isso a acao e sensivel e o antes/depois fica gravado.
     *
     * @return string|null sempre null hoje; a assinatura ja devolve recusa para
     *                     o dia em que houver uma, como as outras desta classe
     */
    public function atualizarDados(User $usuario, string $nome, string $email, User $responsavel): ?string
    {
        $antes = ['name' => $usuario->name, 'email' => $usuario->email];

        if ($antes['name'] === $nome && $antes['email'] === $email) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $nome, $email, $antes, $responsavel): ?string {
            $usuario->forceFill(['name' => $nome, 'email' => $email])->save();

            $this->registrarAcao->__invoke(
                AcaoAuditada::AlterouDadosDoUsuario,
                'usuario',
                (int) $usuario->getKey(),
                [
                    'nome' => ['antes' => $antes['name'], 'depois' => $nome],
                    'email' => ['antes' => $antes['email'], 'depois' => $email],
                ],
                responsavel: $responsavel,
            );

            return null;
        });
    }

    /**
     * Define a senha de outra conta, na hora.
     *
     * DECISAO DO DONO DO PRODUTO, tomada com a ressalva a vista: a partir daqui
     * existe um momento em que duas pessoas conhecem a mesma senha, e o rastro
     * da auditoria — que diz "fulano fez" — passa a depender de fulano trocar
     * essa senha depois. O caminho recomendado continua sendo o link de
     * redefinicao (`enviarRedefinicaoDeSenha`), em que ninguem alem da propria
     * pessoa chega a saber a senha.
     *
     * As sessoes abertas da conta CAEM: `setRememberToken` novo invalida o
     * "lembrar-me", e a senha trocada invalida a sessao pelo hash que o Laravel
     * guarda nela. Senha redefinida com a sessao antiga viva seria uma troca
     * que nao troca nada.
     */
    public function definirSenha(User $usuario, string $senha, User $responsavel): void
    {
        DB::transaction(function () use ($usuario, $senha, $responsavel): void {
            $usuario->forceFill([
                'password' => Hash::make($senha),
                'remember_token' => Str::random(60),
            ])->save();

            // A senha NAO entra na auditoria — nem ela, nem o hash. O que fica
            // registrado e que a redefinicao aconteceu e por qual caminho.
            $this->registrarAcao->__invoke(
                AcaoAuditada::RedefiniuSenhaDeUsuario,
                'usuario',
                (int) $usuario->getKey(),
                ['email' => $usuario->email, 'caminho' => 'senha definida na tela'],
                responsavel: $responsavel,
            );
        });
    }

    /**
     * Manda para a pessoa o e-mail de redefinicao que o Laravel ja tem.
     *
     * E o caminho preferido: quem administra resolve o "nao consigo entrar" sem
     * chegar a saber a senha de ninguem.
     */
    public function enviarRedefinicaoDeSenha(User $usuario, User $responsavel): void
    {
        Password::sendResetLink(['email' => $usuario->email]);

        $this->registrarAcao->__invoke(
            AcaoAuditada::RedefiniuSenhaDeUsuario,
            'usuario',
            (int) $usuario->getKey(),
            ['email' => $usuario->email, 'caminho' => 'link enviado por e-mail'],
            responsavel: $responsavel,
        );
    }
}
