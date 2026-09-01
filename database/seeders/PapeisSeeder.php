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
 * Sao tres papeis, e cada um existe porque tem gente de verdade para ocupa-lo:
 * "administrador", que responde pelo sistema inteiro; "organizador", que toca
 * o evento no dia a dia; e "portaria", que abre o portao no dia do evento.
 * Perfil sem ninguem para ocupar e complexidade sem dono — foi por isso que
 * durante seis fases foram apenas dois.
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
     * Quem fica no portao no dia do evento.
     *
     * E o papel mais estreito do sistema, e de proposito: ele alcanca UMA
     * tela. Quem esta no portao costuma ser voluntario, com o celular na mao,
     * emprestado de outra pessoa, no meio de uma fila — e o que ele precisa e
     * conferir ingresso. Lista de inscritos, dado pessoal, dinheiro e
     * auditoria nao passam por ali, e nao passam porque nao ha para que.
     */
    public const PAPEL_PORTARIA = 'portaria';

    /**
     * Todas as permissoes que existem, com a explicacao de cada uma.
     *
     * @var array<string, string>
     */
    public const PERMISSOES = [
        'painel.ver' => 'Abrir o painel com os numeros do evento',
        'catalogo.gerenciar' => 'Cadastrar setores e grupos de participantes',
        'eventos.gerenciar' => 'Cadastrar evento, dias, grupos, atividades e conflitos',
        'inscricoes.ver' => 'Consultar a lista de inscricoes',
        'inscricoes.exportar' => 'Baixar a lista de inscricoes',
        'inscricoes.cancelar' => 'Cancelar a inscricao de outra pessoa',
        'pagamentos.confirmar-manual' => 'Declarar na mao que um pagamento entrou',
        'usuarios.gerenciar' => 'Criar e ajustar contas administrativas',
        'auditoria.ver' => 'Ler o historico de quem fez o que (Fase 9)',
        'pagamentos.credenciais' => 'Cadastrar a credencial e o certificado do provedor de pagamento (Fase 8b)',
        'pagamentos.avisos-ver' => 'Ler os avisos automaticos que o provedor de pagamento enviou',
        'presenca.registrar' => 'Registrar a entrada de quem chega, conferindo o ingresso na portaria',
        'presenca.desfazer' => 'Desfazer uma entrada registrada por engano na portaria',
    ];

    /**
     * O que o organizador NAO alcanca.
     *
     * "pagamentos.confirmar-manual" e a unica acao do sistema que declara
     * "entrou dinheiro" sem que fonte externa tenha reconhecido nada; quanto
     * menos gente puder, melhor. "usuarios.gerenciar" e "auditoria.ver" tratam
     * de quem entra e do historico, e tambem ficam so com o administrador.
     *
     * "pagamentos.credenciais" e a mais restrita de todas: ela abre a tela que
     * guarda a credencial da instituicao financeira e decide para qual conta o
     * dinheiro do evento vai. Quem organiza o evento no dia a dia nao precisa
     * dela nem uma vez.
     *
     * "pagamentos.avisos-ver" acompanha as duas de cima: o aviso do provedor e
     * conversa entre o sistema e a instituicao financeira. Quem responde pelo
     * sistema precisa saber se o provedor ainda esta chamando; quem organiza o
     * evento no dia a dia trabalha com a inscricao, que ja mostra o resultado
     * dessa conversa.
     *
     * "presenca.desfazer" NAO entra nesta lista, e a ausencia e decisao: quem
     * organiza o evento esta la no dia, e e quem conserta o engano do portao.
     * Tirar isso do organizador significaria que todo engano de portaria
     * esperaria o administrador aparecer — com a fila parada.
     *
     * @var array<int, string>
     */
    public const FORA_DO_ORGANIZADOR = [
        'pagamentos.confirmar-manual',
        'usuarios.gerenciar',
        'auditoria.ver',
        'pagamentos.credenciais',
        'pagamentos.avisos-ver',
    ];

    /**
     * Tudo o que o papel "portaria" alcanca — uma permissao, e so.
     *
     * "presenca.desfazer" fica de fora por escrito. Desfazer e exatamente o
     * caminho que transforma um ingresso ja usado em carona para outra pessoa,
     * e quem esta no portao, sob pressao de fila e de gente conhecida pedindo
     * jeitinho, e a pessoa mais mal colocada do sistema para tomar essa
     * decisao. Quem desfaz e quem organiza o evento — que esta no mesmo lugar,
     * no mesmo dia, e nao tem a fila olhando.
     *
     * Se um dia a decisao mudar, e uma linha aqui. Mas ela sera uma decisao, e
     * nao um descuido.
     *
     * @var array<int, string>
     */
    public const PERMISSOES_DA_PORTARIA = [
        'presenca.registrar',
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

        $portaria = Role::findOrCreate(self::PAPEL_PORTARIA, 'web');
        $portaria->syncPermissions(self::PERMISSOES_DA_PORTARIA);

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
