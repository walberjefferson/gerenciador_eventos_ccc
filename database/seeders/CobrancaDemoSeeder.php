<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FormaRecebimento;
use App\Enums\SituacaoEvento;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use App\Models\Lote;
use App\Models\Responsavel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dois eventos para experimentar as formas de cobranca que a Copa CCC nao usa:
 * preco por lote e recebimento pela chave Pix do responsavel do setor.
 *
 * **Ele NAO entra no DatabaseSeeder, e a ausencia e a coisa mais importante
 * deste arquivo.** Os testes de navegador rodam `migrate:fresh --seed` e
 * partem de um mundo com UM evento aberto: a home destaca "o" evento, a
 * portaria acha "a" pessoa, a listagem administrativa conta "as" linhas.
 * Acrescentar dois eventos abertos aquele mundo quebra sete provas que hoje
 * passam — nao porque o produto piorou, mas porque o cenario mudou embaixo
 * delas. Dado de demonstracao nao vale o preco de uma prova.
 *
 * Por isso ele roda sob demanda, como o VolumeSeeder:
 *
 *     sail artisan db:seed --class=CobrancaDemoSeeder
 *
 * O que nasce:
 *
 * 1. **Encontro de Verao CCC 2027** — tres lotes, um de cada estado
 *    (encerrado, vigente, futuro), para ver os tres ao mesmo tempo na tela.
 * 2. **Retiro de Setor CCC 2027** — recebimento pela chave Pix do responsavel
 *    do setor, prazo de sete dias, e todos os setores preparados para receber.
 *
 * Pode ser executado quantas vezes for preciso: nada e duplicado.
 */
class CobrancaDemoSeeder extends Seeder
{
    public const SLUG_LOTES = 'encontro-de-verao-ccc-2027';

    public const SLUG_SETOR = 'retiro-de-setor-ccc-2027';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'CobrancaDemoSeeder so roda em local ou testing: ele cria chave Pix ficticia. Nada foi criado.'
            );

            return;
        }

        $this->eventoComLotes();
        $this->eventoQueRecebePeloSetor();

        $this->command?->info('Eventos de cobranca prontos: '.self::SLUG_LOTES.' e '.self::SLUG_SETOR.'.');
    }

    /**
     * O evento de preco escalonado.
     *
     * Os tres lotes sao datados em relacao a HOJE, e nao em datas fixas: um
     * seeder com data cravada envelhece — seis meses depois todos os lotes
     * aparecem encerrados e a demonstracao mostra uma tela morta. Assim o
     * primeiro esta sempre vencido, o segundo sempre vigente e o terceiro
     * sempre a frente, seja qual for o dia em que alguem rodar isto.
     */
    private function eventoComLotes(): void
    {
        $agora = Carbon::now();
        $inicio = $agora->copy()->addMonths(4);

        $evento = Evento::query()->firstOrCreate(
            ['slug' => self::SLUG_LOTES],
            [
                'codigo_publico' => (string) Str::ulid(),
                'nome' => 'Encontro de Verão CCC 2027',
                'descricao' => 'Encontro de dois dias com inscrição por lotes: quanto mais cedo, mais barato.',
                'local' => 'Colônia de Férias Boa Vista',
                'local_detalhe' => 'Ônibus saindo da paróquia às 6h.',
                'itens_incluidos' => [
                    'Hospedagem nas duas noites',
                    'Todas as refeições',
                    'Material do encontro',
                ],
                'perguntas_frequentes' => [
                    [
                        'pergunta' => 'O que acontece quando o lote vira?',
                        'resposta' => 'O preço passa a ser o do lote seguinte. Vale o lote do momento em que a inscrição é enviada, e esse valor fica registrado na sua inscrição — não muda depois.',
                    ],
                ],
                'data_inicio' => $inicio->toDateString(),
                'data_fim' => $inicio->copy()->addDays(2)->toDateString(),
                'inscricoes_abrem_em' => $agora->copy()->subMonth(),
                'inscricoes_fecham_em' => $agora->copy()->addMonths(3),
                'capacidade' => 300,
                // O valor do evento continua existindo: e o que valeria se
                // ninguem tivesse cadastrado lote nenhum (RN-L8).
                'valor_centavos' => 25_000,
                'moeda' => 'BRL',
                'prazo_pagamento_minutos' => 1440,
                'situacao' => SituacaoEvento::InscricoesAbertas,
                'regulamento' => 'Regulamento do Encontro de Verão CCC 2027.',
                'versao_termos' => '2027.1',
                'contato_email' => 'contato@encontroccc.example.com',
                'contato_telefone' => '(82) 90000-0000',
                'configuracoes' => [],
            ],
        );

        $dia = $this->dia($evento, 1, 'Dia 1 — Chegada', $inicio);

        $oficinas = $this->grupo($dia, [
            'nome' => 'Oficinas',
            'descricao' => 'Escolha uma oficina para o primeiro dia.',
            'obrigatorio' => true,
            'min_selecoes' => 1,
            'max_selecoes' => 1,
            'posicao' => 1,
        ]);

        $this->atividade($oficinas, 'Oficina de música', $inicio, '14:00', '16:00', 1, 60);
        $this->atividade($oficinas, 'Oficina de artes', $inicio, '14:00', '16:00', 2, 60);

        // Encerrado: a data ja passou. Aparece riscado, com o preco que quem
        // chegou cedo pagou — e e esse contraste que faz a pessoa entender que
        // o lote de agora tambem vai virar.
        $this->lote($evento, 1, '1º lote — promocional', 18_000, $agora->copy()->subWeek(), null);

        // Vigente: dentro da data e com vaga sobrando.
        $this->lote($evento, 2, '2º lote', 25_000, $agora->copy()->addWeeks(3), 150);

        // Futuro: so entra quando o segundo acabar, por data ou por vaga.
        $this->lote($evento, 3, '3º lote — última chamada', 32_000, $agora->copy()->addMonths(3), null);
    }

    /**
     * O evento que recebe pela chave Pix do responsavel do setor.
     *
     * O preparo dos setores vem antes de proposito: sem chave e sem
     * responsavel a tela de pagamento nao teria o que mostrar, e o participante
     * ficaria sem para onde pagar.
     */
    private function eventoQueRecebePeloSetor(): void
    {
        $this->prepararSetores();

        $agora = Carbon::now();
        $inicio = $agora->copy()->addMonths(5);

        $evento = Evento::query()->firstOrCreate(
            ['slug' => self::SLUG_SETOR],
            [
                'codigo_publico' => (string) Str::ulid(),
                'nome' => 'Retiro de Setor CCC 2027',
                'descricao' => 'Retiro com pagamento direto ao responsável do seu setor, por Pix, com envio de comprovante.',
                'local' => 'Casa de Retiros São José',
                'local_detalhe' => 'Cada setor organiza a própria carona.',
                'itens_incluidos' => ['Hospedagem', 'Refeições', 'Material do retiro'],
                'perguntas_frequentes' => [
                    [
                        'pergunta' => 'Para quem eu pago?',
                        'resposta' => 'Para o responsável do seu setor, pela chave Pix que aparece na tela de pagamento. Depois é só enviar o comprovante por ali mesmo — ele confere e a sua inscrição fica confirmada.',
                    ],
                ],
                'data_inicio' => $inicio->toDateString(),
                'data_fim' => $inicio->copy()->addDays(2)->toDateString(),
                'inscricoes_abrem_em' => $agora->copy()->subWeek(),
                'inscricoes_fecham_em' => $agora->copy()->addMonths(4),
                'capacidade' => 120,
                'valor_centavos' => 20_000,
                'moeda' => 'BRL',
                // Sete dias: conferencia feita por pessoa nao cabe em 24 horas,
                // e a RN-S8 exige no minimo dois dias neste modo.
                'prazo_pagamento_minutos' => 10_080,
                'situacao' => SituacaoEvento::InscricoesAbertas,
                'forma_recebimento' => FormaRecebimento::Setor,
                'regulamento' => 'Regulamento do Retiro de Setor CCC 2027.',
                'versao_termos' => '2027.1',
                'contato_email' => 'contato@retiroccc.example.com',
                'contato_telefone' => '(82) 90000-0001',
                'configuracoes' => [],
            ],
        );

        $dia = $this->dia($evento, 1, 'Dia 1 — Acolhida', $inicio);

        $encontros = $this->grupo($dia, [
            'nome' => 'Encontros',
            'descricao' => 'Escolha um encontro.',
            'obrigatorio' => true,
            'min_selecoes' => 1,
            'max_selecoes' => 1,
            'posicao' => 1,
        ]);

        $this->atividade($encontros, 'Encontro de jovens', $inicio, '15:00', '17:00', 1, 60);
        $this->atividade($encontros, 'Encontro de famílias', $inicio, '15:00', '17:00', 2, 60);
    }

    /**
     * Da a cada setor ativo DOIS responsaveis, com chave e telefone.
     *
     * TODOS os setores ativos, e nao apenas um: a regra do cadastro de evento
     * (RN-S4) recusa a forma "setor" enquanto existir setor ativo sem ninguem
     * apto, porque um participante daquele setor nao teria para onde pagar.
     *
     * **Dois, e nao um, e a diferenca que faz a demonstracao mostrar alguma
     * coisa.** Com um responsavel so o sorteio da RN-R4 teria sempre a mesma
     * resposta, e quem abrisse a demonstracao veria um sistema que parece nao
     * sortear nada. Com dois, as inscricoes se dividem entre eles, a fila de
     * conferencia mostra nomes diferentes na coluna de quem recebeu (RN-R7) e
     * os dois conseguem conferir o setor inteiro (RN-R6).
     *
     * O primeiro de cada setor tem conta no painel; o segundo NAO tem, de
     * proposito: e o caso do tesoureiro que recebe e nao usa o sistema (RN-R1),
     * e ele so aparece na demonstracao se estiver nela.
     *
     * A senha e a mesma do AdminDemoSeeder, e pelo mesmo motivo: e conta de
     * desenvolvimento, num seeder que ja se recusa a rodar fora de local.
     */
    private function prepararSetores(): void
    {
        Cidade::query()->ativos()->get()->each(function (Cidade $setor, int $indice): void {
            if ($setor->estaPreparadaParaReceber()) {
                return;
            }

            $apelido = Str::slug($setor->nome);

            $conta = User::query()->firstOrCreate(
                ['email' => "responsavel.{$apelido}@exemplo.test"],
                [
                    'name' => 'Responsável do '.$setor->nome,
                    'password' => Hash::make('password'),
                    'ativo' => true,
                ],
            );

            $conta->syncRoles([PapeisSeeder::PAPEL_RESPONSAVEL_SETOR]);

            $comConta = Responsavel::query()->firstOrCreate(
                ['user_id' => $conta->getKey()],
                [
                    'nome' => $conta->name,
                    'chave_pix' => "responsavel.{$apelido}@exemplo.test",
                    // Formatado como a pessoa digitaria, porque e assim que a
                    // coluna guarda e assim que a tela mostra.
                    'telefone' => sprintf('(82) 9%04d-%04d', 1000 + $indice, 1000 + $indice),
                    'ativo' => true,
                ],
            );

            $semConta = Responsavel::query()->firstOrCreate(
                ['chave_pix' => "tesouraria.{$apelido}@exemplo.test"],
                [
                    'nome' => 'Tesouraria do '.$setor->nome,
                    'telefone' => sprintf('(82) 9%04d-%04d', 2000 + $indice, 2000 + $indice),
                    'user_id' => null,
                    'ativo' => true,
                ],
            );

            $setor->responsaveis()->syncWithoutDetaching([
                $comConta->getKey(),
                $semConta->getKey(),
            ]);
        });
    }

    private function dia(Evento $evento, int $posicao, string $nome, Carbon $data): DiaEvento
    {
        return DiaEvento::query()->firstOrCreate(
            ['evento_id' => $evento->id, 'posicao' => $posicao],
            ['nome' => $nome, 'data' => $data->toDateString(), 'ativo' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function grupo(DiaEvento $dia, array $dados): GrupoAtividade
    {
        return GrupoAtividade::query()->firstOrCreate(
            ['dia_evento_id' => $dia->id, 'nome' => $dados['nome']],
            $dados + ['ativo' => true],
        );
    }

    private function atividade(
        GrupoAtividade $grupo,
        string $nome,
        Carbon $data,
        string $comeca,
        string $termina,
        int $posicao,
        int $capacidade,
    ): Atividade {
        return Atividade::query()->firstOrCreate(
            ['grupo_atividade_id' => $grupo->id, 'nome' => $nome],
            [
                'comeca_em' => Carbon::parse($data->toDateString().' '.$comeca),
                'termina_em' => Carbon::parse($data->toDateString().' '.$termina),
                'posicao' => $posicao,
                'capacidade' => $capacidade,
                'ativo' => true,
                'configuracoes' => [],
            ],
        );
    }

    /**
     * Um degrau de preco. Idempotente pela posicao, como os dias.
     */
    private function lote(
        Evento $evento,
        int $posicao,
        string $nome,
        int $valorCentavos,
        ?Carbon $disponivelAte,
        ?int $quantidade,
    ): Lote {
        return Lote::query()->firstOrCreate(
            ['evento_id' => $evento->id, 'posicao' => $posicao],
            [
                'nome' => $nome,
                'valor_centavos' => $valorCentavos,
                'disponivel_ate' => $disponivelAte,
                'quantidade' => $quantidade,
            ],
        );
    }
}
