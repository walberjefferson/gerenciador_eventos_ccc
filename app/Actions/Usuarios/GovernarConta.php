<?php

declare(strict_types=1);

namespace App\Actions\Usuarios;

use App\Enums\AcaoAuditada;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use Database\Seeders\PapeisSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
}
