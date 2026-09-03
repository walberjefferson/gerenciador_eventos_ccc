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
 * Sao quatro papeis, e cada um existe porque tem gente de verdade para
 * ocupa-lo: "administrador", que responde pelo sistema inteiro; "organizador",
 * que toca o evento no dia a dia; "portaria", que abre o portao no dia do
 * evento; e "responsavel-setor", que confere os comprovantes de pagamento do
 * proprio setor. Perfil sem ninguem para ocupar e complexidade sem dono — foi
 * por isso que durante seis fases foram apenas dois.
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
     * Quem responde por um setor e confere o pagamento de quem se inscreveu
     * por ali.
     *
     * Ele existe por causa dos eventos que recebem pela chave Pix do
     * responsavel do setor: nesses, o dinheiro cai na conta de uma pessoa, e e
     * essa pessoa — nao o administrador, que nao ve o extrato dela — quem pode
     * dizer se entrou. Sem este papel, ou o administrador confirmaria pagamento
     * que nao viu, ou cada responsavel precisaria de um administrador ao lado.
     *
     * E um papel de DOIS alcances, e o segundo e o que importa: ele ve a lista
     * de inscricoes e confere comprovante — mas so do setor dele. O recorte nao
     * e um filtro de tela que ele possa mudar; e escopo aplicado no servidor,
     * em ComprovantePagamentoPolicy e em FiltroDeInscricoes (RN-S9).
     */
    public const PAPEL_RESPONSAVEL_SETOR = 'responsavel-setor';

    /**
     * Todas as permissoes que existem, com a explicacao de cada uma.
     *
     * @var array<string, string>
     */
    public const PERMISSOES = [
        'painel.ver' => 'Abrir o painel com os numeros do evento',
        // "catalogo.gerenciar" passou a cobrir TAMBEM o cadastro de
        // responsaveis — quem recebe o Pix dos setores. Nao nasceu permissao
        // nova porque responsavel e catalogo, como setor e grupo: e a mesma
        // lista global, na mesma tela lateral, feita pela mesma pessoa.
        // Permissao separada so faria sentido para apartar quem cadastra chave
        // Pix de quem cadastra setor — e isso e uma decisao de seguranca que a
        // organizacao ainda nao pediu, nao uma decisao de organizacao de menu.
        'catalogo.gerenciar' => 'Cadastrar setores, grupos de participantes e responsaveis',
        'eventos.gerenciar' => 'Cadastrar evento, dias, grupos, atividades e conflitos',
        'inscricoes.ver' => 'Consultar a lista de inscricoes',
        'inscricoes.exportar' => 'Baixar a lista de inscricoes',
        'inscricoes.cancelar' => 'Cancelar a inscricao de outra pessoa',
        'pagamentos.confirmar-manual' => 'Declarar na mao que um pagamento entrou',
        'pagamentos.conferir-comprovante' => 'Conferir o comprovante enviado por quem se inscreveu no proprio setor',
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
     * "pagamentos.conferir-comprovante" E DA MESMA FAMILIA da confirmacao
     * manual acima — as duas terminam com uma inscricao confirmada sem que
     * provedor nenhum tenha reconhecido nada —, mas ela e bem mais estreita, e a
     * diferenca precisa estar escrita. A confirmacao manual age sobre QUALQUER
     * inscricao, a partir de nada: basta alguem afirmar que o dinheiro entrou.
     * A conferencia de comprovante so age sobre inscricao do PROPRIO SETOR de
     * quem confere, e so a partir de um arquivo que o participante enviou —
     * quer dizer, ela exige uma prova e um vinculo, e a outra nao exige nem um
     * nem outro.
     *
     * Ela fica fora do organizador pelo mesmo motivo que a irma: nao porque
     * seja perigosa demais para ele, mas porque nao e trabalho dele. Quem
     * confere e quem responde pelo setor — e essa pessoa tem papel proprio.
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
        'pagamentos.conferir-comprovante',
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

    /**
     * Tudo o que o responsavel de setor alcanca — duas permissoes, e so.
     *
     * "inscricoes.ver" ja existia e nao ganhou significado novo: o que muda e
     * que, para quem tem ESTE papel, a lista de inscricoes chega recortada pelo
     * setor dele antes de sair do banco. Sem ela, a fila de comprovantes
     * mostraria nomes de pessoas que ele nao poderia abrir.
     *
     * "inscricoes.exportar" fica de fora por escrito: conferir pagamento e
     * olhar uma inscricao por vez; baixar a planilha e levar a lista embora.
     * "inscricoes.cancelar" tambem: devolver vaga de alguem e decisao de quem
     * organiza o evento, nao de quem confere o dinheiro dele.
     *
     * @var array<int, string>
     */
    public const PERMISSOES_DO_RESPONSAVEL_DE_SETOR = [
        'inscricoes.ver',
        'pagamentos.conferir-comprovante',
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

        $responsavelDeSetor = Role::findOrCreate(self::PAPEL_RESPONSAVEL_SETOR, 'web');
        $responsavelDeSetor->syncPermissions(self::PERMISSOES_DO_RESPONSAVEL_DE_SETOR);

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
