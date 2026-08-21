<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria os papeis e as permissoes do lado administrativo.
 *
 * Sao apenas dois papeis, de proposito: "administrador", que responde pelo
 * sistema inteiro, e "organizador", que toca o evento no dia a dia. Perfil
 * sem ninguem para ocupar e complexidade sem dono.
 *
 * O seeder e idempotente: rodar duas vezes nao duplica nada nem tira
 * permissao de quem ja tem.
 */
class PapeisSeeder extends Seeder
{
    /** Responsavel pelo sistema. Pode tudo, inclusive mexer em dinheiro. */
    public const PAPEL_ADMINISTRADOR = 'administrador';

    /** Quem toca o evento no dia a dia. Nao confirma pagamento na mao. */
    public const PAPEL_ORGANIZADOR = 'organizador';

    /**
     * Todas as permissoes que existem, com a explicacao de cada uma.
     *
     * @var array<string, string>
     */
    public const PERMISSOES = [
        'painel.ver' => 'Abrir o painel com os numeros do evento',
        'catalogo.gerenciar' => 'Cadastrar cidades e grupos de participantes',
        'eventos.gerenciar' => 'Cadastrar evento, dias, grupos, atividades e conflitos',
        'inscricoes.ver' => 'Consultar a lista de inscricoes',
        'inscricoes.exportar' => 'Baixar a lista de inscricoes',
        'inscricoes.cancelar' => 'Cancelar a inscricao de outra pessoa',
        'pagamentos.confirmar-manual' => 'Declarar na mao que um pagamento entrou',
        'usuarios.gerenciar' => 'Criar e ajustar contas administrativas',
        'auditoria.ver' => 'Ler o historico de quem fez o que (Fase 9)',
    ];

    /**
     * O que o organizador NAO alcanca.
     *
     * "pagamentos.confirmar-manual" e a unica acao do sistema que declara
     * "entrou dinheiro" sem que fonte externa tenha reconhecido nada; quanto
     * menos gente puder, melhor. "usuarios.gerenciar" e "auditoria.ver" tratam
     * de quem entra e do historico, e tambem ficam so com o administrador.
     *
     * @var array<int, string>
     */
    public const FORA_DO_ORGANIZADOR = [
        'pagamentos.confirmar-manual',
        'usuarios.gerenciar',
        'auditoria.ver',
    ];

    public function run(): void
    {
        // O pacote guarda permissao em cache. Sem limpar antes, o seeder pode
        // ler um retrato velho e recriar o que ja existe.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSOES) as $nome) {
            Permission::findOrCreate($nome, 'web');
        }

        $administrador = Role::findOrCreate(self::PAPEL_ADMINISTRADOR, 'web');
        $administrador->syncPermissions(array_keys(self::PERMISSOES));

        $organizador = Role::findOrCreate(self::PAPEL_ORGANIZADOR, 'web');
        $organizador->syncPermissions(self::permissoesDoOrganizador());

        // E limpa de novo no fim: quem chamar o seeder ja enxerga o estado novo.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * As permissoes que o organizador recebe.
     *
     * @return array<int, string>
     */
    public static function permissoesDoOrganizador(): array
    {
        return array_values(array_diff(array_keys(self::PERMISSOES), self::FORA_DO_ORGANIZADOR));
    }
}
