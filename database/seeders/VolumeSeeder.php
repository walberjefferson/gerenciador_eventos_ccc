<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MetodoPagamento;
use App\Enums\SituacaoEvento;
use App\Enums\SituacaoPagamento;
use App\Models\Inscricao;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enche o banco com um evento de 10.000 inscricoes para medir desempenho.
 *
 * Por que ele existe: os indices do projeto foram criados com raciocinio, mas
 * nunca foram medidos com volume. Com 30 inscricoes de demonstracao qualquer
 * consulta parece rapida — inclusive a que vai travar o painel no dia do
 * evento. Este seeder cria o volume que torna a diferenca visivel.
 *
 * Tres cuidados que explicam o formato do arquivo:
 *
 * 1. **So roda em `local` e `testing`.** Em qualquer outro ambiente ele para
 *    com excecao antes de tocar no banco. Dez mil inscricoes falsas no banco
 *    de producao nao teriam volta.
 *
 * 2. **Insercao em lote, nao pelo caminho normal.** Passar por
 *    `CriarInscricao` dez mil vezes levaria muito tempo e nao e o objetivo:
 *    o que se quer medir e a LEITURA com volume, nao a escrita. As regras de
 *    inscricao continuam sendo provadas pelos testes de sempre.
 *
 * 3. **Os numeros sao coerentes.** Cada situacao ganha o pagamento que lhe
 *    corresponde, os contadores de vaga das atividades batem com as inscricoes
 *    criadas e as datas ficam espalhadas pelo periodo de inscricao. Volume
 *    incoerente produziria plano de execucao que nao acontece na vida real.
 */
class VolumeSeeder extends Seeder
{
    /** Quantas inscricoes o seeder cria. Uma ordem de grandeza acima do evento real esperado. */
    public const TOTAL = 10_000;

    /** O evento criado por este seeder. Serve para as consultas de medicao acharem o alvo. */
    public const SLUG = 'volume-10k';

    /** Quantas linhas vao ao banco por comando. Lotes maiores estouram o limite de parametros do driver. */
    private const LOTE = 500;

    /**
     * A distribuicao das situacoes. Nao e uniforme de proposito: um evento real
     * tem muito mais confirmada do que cancelada, e o plano de execucao muda
     * conforme a seletividade de cada valor.
     *
     * `lista_espera` fica em zero porque a lista de espera nao existe no
     * sistema (esta fora do escopo); inventar volume para ela produziria
     * medicao de uma consulta que ninguem faz.
     *
     * @var array<string, int>
     */
    private const DISTRIBUICAO = [
        'confirmada' => 5_500,
        'aguardando_pagamento' => 2_000,
        'expirada' => 1_800,
        'cancelada' => 700,
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'VolumeSeeder so pode rodar em local ou testing. Ambiente atual: '.app()->environment()
            );
        }

        $inicio = microtime(true);

        $cidades = $this->cidades();
        $grupos = $this->gruposDeParticipantes($cidades);
        $evento = $this->evento();
        $atividades = $this->programacao($evento);

        $this->command?->info('Criando '.number_format(self::TOTAL, 0, ',', '.').' inscricoes...');

        $contadores = $this->inscricoes($evento, $grupos, $atividades);

        $this->ajustarContadores($evento, $contadores);

        DB::statement('ANALYZE');

        $segundos = round(microtime(true) - $inicio, 1);
        $this->command?->info("Pronto em {$segundos}s. Evento: ".self::SLUG);
    }

    /**
     * @return array<int, int> ids das cidades
     */
    private function cidades(): array
    {
        $nomes = [
            ['Sao Paulo', 'SP'], ['Campinas', 'SP'], ['Rio de Janeiro', 'RJ'],
            ['Belo Horizonte', 'MG'], ['Curitiba', 'PR'], ['Porto Alegre', 'RS'],
            ['Salvador', 'BA'], ['Recife', 'PE'], ['Fortaleza', 'CE'], ['Goiania', 'GO'],
        ];

        $ids = [];

        foreach ($nomes as [$nome, $uf]) {
            DB::table('cidades')->updateOrInsert(
                ['nome' => $nome, 'uf' => $uf],
                ['ativo' => true, 'created_at' => now(), 'updated_at' => now()],
            );

            $ids[] = (int) DB::table('cidades')
                ->where('nome', $nome)->where('uf', $uf)->value('id');
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $cidades
     * @return array<int, int> ids dos grupos de participantes
     */
    private function gruposDeParticipantes(array $cidades): array
    {
        $ids = [];

        foreach ($cidades as $posicao => $cidadeId) {
            for ($n = 1; $n <= 4; $n++) {
                $nome = "Grupo de volume {$posicao}-{$n}";

                DB::table('grupos_participantes')->updateOrInsert(
                    ['cidade_id' => $cidadeId, 'nome' => $nome],
                    ['ativo' => true, 'created_at' => now(), 'updated_at' => now()],
                );

                $ids[] = (int) DB::table('grupos_participantes')
                    ->where('cidade_id', $cidadeId)->where('nome', $nome)->value('id');
            }
        }

        return $ids;
    }

    private function evento(): object
    {
        $existente = DB::table('eventos')->where('slug', self::SLUG)->first();

        if ($existente !== null) {
            return $existente;
        }

        $id = DB::table('eventos')->insertGetId([
            'codigo_publico' => (string) Str::ulid(),
            'nome' => 'Encontro de Volume CCC 2026',
            'slug' => self::SLUG,
            'descricao' => 'Evento sintetico usado apenas para medir desempenho com volume real.',
            'data_inicio' => '2026-11-06',
            'data_fim' => '2026-11-08',
            'inscricoes_abrem_em' => '2026-08-01 00:00:00-03',
            'inscricoes_fecham_em' => '2026-10-31 23:59:00-03',
            'capacidade' => 12_000,
            'valor_centavos' => 15_000,
            'moeda' => 'BRL',
            'prazo_pagamento_minutos' => 1_440,
            'situacao' => SituacaoEvento::InscricoesAbertas->value,
            'regulamento' => 'Regulamento sintetico do evento de volume.',
            'versao_termos' => '2026.1',
            'contato_email' => 'volume@example.com',
            'contato_telefone' => '(11) 90000-0000',
            'configuracoes' => '{}',
            'vagas_reservadas' => 0,
            'vagas_confirmadas' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $evento = DB::table('eventos')->find($id);

        if ($evento === null) {
            throw new RuntimeException('Nao foi possivel criar o evento de volume.');
        }

        return $evento;
    }

    /**
     * Tres dias, dois grupos por dia, seis atividades por grupo: 36 atividades.
     *
     * O tamanho da programacao importa para a medicao da pagina publica, que
     * carrega dias, grupos e atividades de uma vez.
     *
     * @return array<int, array<int, int>> ids de atividade agrupados por dia
     */
    private function programacao(object $evento): array
    {
        $existentes = DB::table('atividades')
            ->join('grupos_atividades', 'grupos_atividades.id', '=', 'atividades.grupo_atividade_id')
            ->join('dias_evento', 'dias_evento.id', '=', 'grupos_atividades.dia_evento_id')
            ->where('dias_evento.evento_id', $evento->id)
            ->orderBy('dias_evento.posicao')
            ->orderBy('atividades.id')
            ->get(['atividades.id', 'dias_evento.posicao']);

        if ($existentes->isNotEmpty()) {
            return $existentes
                ->groupBy(fn (object $linha): int => (int) $linha->posicao)
                ->map(fn ($linhas) => $linhas->map(fn (object $linha): int => (int) $linha->id)->all())
                ->values()
                ->all();
        }

        $porDia = [];

        for ($dia = 1; $dia <= 3; $dia++) {
            $data = Carbon::parse('2026-11-05')->addDays($dia);

            $diaId = DB::table('dias_evento')->insertGetId([
                'evento_id' => $evento->id,
                'nome' => "Dia {$dia}",
                'data' => $data->toDateString(),
                'posicao' => $dia,
                'ativo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $atividades = [];

            for ($grupo = 1; $grupo <= 2; $grupo++) {
                $grupoId = DB::table('grupos_atividades')->insertGetId([
                    'dia_evento_id' => $diaId,
                    'nome' => "Dia {$dia} — bloco {$grupo}",
                    'descricao' => 'Bloco sintetico de atividades.',
                    'obrigatorio' => $grupo === 1,
                    'min_selecoes' => $grupo === 1 ? 1 : 0,
                    'max_selecoes' => 1,
                    'posicao' => $grupo,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                for ($n = 1; $n <= 6; $n++) {
                    $atividades[] = DB::table('atividades')->insertGetId([
                        'grupo_atividade_id' => $grupoId,
                        'nome' => "Atividade {$dia}.{$grupo}.{$n}",
                        'descricao' => 'Atividade sintetica usada na medicao de desempenho.',
                        'comeca_em' => $data->copy()->setTime(7 + $grupo * 4, 0)->toDateTimeString(),
                        'termina_em' => $data->copy()->setTime(9 + $grupo * 4, 0)->toDateTimeString(),
                        'capacidade' => 900,
                        'posicao' => $n,
                        'ativo' => true,
                        'configuracoes' => '{}',
                        'vagas_reservadas' => 0,
                        'vagas_confirmadas' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $porDia[] = $atividades;
        }

        return $porDia;
    }

    /**
     * O grosso do trabalho: inscricoes, escolhas de atividade e pagamentos.
     *
     * @param  array<int, int>  $grupos
     * @param  array<int, array<int, int>>  $atividadesPorDia
     * @return array{reservadas: array<int, int>, confirmadas: array<int, int>, evento_reservadas: int, evento_confirmadas: int}
     */
    private function inscricoes(object $evento, array $grupos, array $atividadesPorDia): array
    {
        $abertura = Carbon::parse('2026-08-01 08:00:00');
        $situacoes = $this->situacoesSorteadas();

        $reservadasPorAtividade = [];
        $confirmadasPorAtividade = [];
        $eventoReservadas = 0;
        $eventoConfirmadas = 0;

        $linhasInscricao = [];
        $escolhas = [];
        $pagamentos = [];

        // Os ids sao calculados aqui, e nao deixados para a sequencia do banco:
        // sem saber o id de antemao nao daria para montar as escolhas de
        // atividade e os pagamentos no mesmo lote de insercao.
        $proximoId = ((int) DB::table('inscricoes')->max('id')) + 1;

        for ($i = 0; $i < self::TOTAL; $i++) {
            $id = $proximoId + $i;
            $situacao = $situacoes[$i];
            $criadaEm = $abertura->copy()->addMinutes((int) round($i * 12.9));
            $prazo = $criadaEm->copy()->addDay();
            $documento = $this->cpfSintetico($i);

            $linhasInscricao[] = [
                'id' => $id,
                'codigo_publico' => (string) Str::ulid(),
                'evento_id' => $evento->id,
                'grupo_participante_id' => $grupos[$i % count($grupos)],
                'nome_completo' => 'Participante de Volume '.($i + 1),
                'email' => 'volume+'.($i + 1).'@example.com',
                'telefone' => '(11) 9'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                'documento' => Crypt::encryptString($documento),
                'documento_hash' => Inscricao::hashDocumento($documento),
                'data_nascimento' => Carbon::parse('1970-01-01')->addDays($i % 15_000)->toDateString(),
                'situacao' => $situacao,
                'valor_centavos' => 15_000,
                'versao_termos' => '2026.1',
                'termos_aceitos_em' => $criadaEm->toDateTimeString(),
                'chave_idempotencia' => (string) Str::uuid(),
                'prazo_pagamento' => $prazo->toDateTimeString(),
                'confirmada_em' => $situacao === 'confirmada' ? $criadaEm->copy()->addHours(3)->toDateTimeString() : null,
                'expirada_em' => $situacao === 'expirada' ? $prazo->toDateTimeString() : null,
                'cancelada_em' => $situacao === 'cancelada' ? $criadaEm->copy()->addDays(2)->toDateTimeString() : null,
                'motivo_cancelamento' => $situacao === 'cancelada' ? 'Cancelamento sintetico de volume.' : null,
                'created_at' => $criadaEm->toDateTimeString(),
                'updated_at' => $criadaEm->toDateTimeString(),
            ];

            // Uma atividade por dia: nunca gera conflito de horario, que e o que
            // uma inscricao real tambem nao pode ter.
            $quantosDias = 1 + ($i % 3);

            for ($dia = 0; $dia < $quantosDias; $dia++) {
                $atividadesDoDia = $atividadesPorDia[$dia];
                $atividadeId = $atividadesDoDia[($i + $dia * 7) % count($atividadesDoDia)];

                $escolhas[] = [
                    'inscricao_id' => $id,
                    'atividade_id' => $atividadeId,
                    'created_at' => $criadaEm->toDateTimeString(),
                    'updated_at' => $criadaEm->toDateTimeString(),
                ];

                if ($situacao === 'confirmada') {
                    $confirmadasPorAtividade[$atividadeId] = ($confirmadasPorAtividade[$atividadeId] ?? 0) + 1;
                } elseif ($situacao === 'aguardando_pagamento') {
                    $reservadasPorAtividade[$atividadeId] = ($reservadasPorAtividade[$atividadeId] ?? 0) + 1;
                }
            }

            if ($situacao === 'confirmada') {
                $eventoConfirmadas++;
            } elseif ($situacao === 'aguardando_pagamento') {
                $eventoReservadas++;
            }

            foreach ($this->pagamentosDa($id, $situacao, $criadaEm, $prazo, $i) as $pagamento) {
                $pagamentos[] = $pagamento;
            }

            if (count($linhasInscricao) >= self::LOTE) {
                $this->descarregar($linhasInscricao, $escolhas, $pagamentos);
            }
        }

        $this->descarregar($linhasInscricao, $escolhas, $pagamentos);

        DB::statement('SELECT setval(\'inscricoes_id_seq\', (SELECT max(id) FROM inscricoes))');

        return [
            'reservadas' => $reservadasPorAtividade,
            'confirmadas' => $confirmadasPorAtividade,
            'evento_reservadas' => $eventoReservadas,
            'evento_confirmadas' => $eventoConfirmadas,
        ];
    }

    /**
     * As situacoes ja embaralhadas, para que os registros nao saiam agrupados
     * por situacao na ordem fisica da tabela. Tabela ordenada por acaso daria
     * um plano otimista que producao nao reproduz.
     *
     * @return array<int, string>
     */
    private function situacoesSorteadas(): array
    {
        $situacoes = [];

        foreach (self::DISTRIBUICAO as $situacao => $quantidade) {
            $situacoes = array_merge($situacoes, array_fill(0, $quantidade, $situacao));
        }

        // Embaralhamento determinista: a mesma medicao pode ser refeita.
        mt_srand(20_260_821);
        shuffle($situacoes);

        return $situacoes;
    }

    /**
     * O pagamento que corresponde a situacao da inscricao.
     *
     * Uma em cada dez inscricoes confirmadas ganha DUAS cobrancas — uma que
     * venceu e outra que foi paga. E o caso que faz a consulta "a cobranca mais
     * recente" trabalhar de verdade; sem ele a medicao seria otimista.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pagamentosDa(int $inscricaoId, string $situacao, Carbon $criadaEm, Carbon $prazo, int $indice): array
    {
        // Todas as colunas aparecem em todas as linhas, mesmo as nulas: a
        // insercao em lote exige que cada linha tenha exatamente as mesmas
        // colunas, na mesma ordem.
        $base = [
            'codigo_publico' => '',
            'inscricao_id' => $inscricaoId,
            'gateway' => 'fake',
            'id_externo' => null,
            'metodo' => MetodoPagamento::Pix->value,
            'valor_centavos' => 15_000,
            'situacao' => SituacaoPagamento::Pendente->value,
            'pix_copia_e_cola' => '00020126volume'.$inscricaoId,
            'expira_em' => $prazo->toDateTimeString(),
            'pago_em' => null,
            'cancelado_em' => null,
            'estornado_em' => null,
            'valor_estornado_centavos' => null,
            'metadados' => '{}',
            'created_at' => $criadaEm->toDateTimeString(),
            'updated_at' => $criadaEm->toDateTimeString(),
        ];

        $pagamentos = [];

        if ($situacao === 'confirmada' && $indice % 10 === 0) {
            $pagamentos[] = array_replace($base, [
                'codigo_publico' => (string) Str::ulid(),
                'id_externo' => 'volume-'.$inscricaoId.'-a',
                'situacao' => SituacaoPagamento::Expirado->value,
                'cancelado_em' => $prazo->toDateTimeString(),
            ]);
        }

        $pagamentos[] = array_replace($base, match ($situacao) {
            'confirmada' => [
                'codigo_publico' => (string) Str::ulid(),
                'id_externo' => 'volume-'.$inscricaoId.'-b',
                'situacao' => SituacaoPagamento::Pago->value,
                'pago_em' => $criadaEm->copy()->addHours(3)->toDateTimeString(),
            ],
            'aguardando_pagamento' => [
                'codigo_publico' => (string) Str::ulid(),
                'id_externo' => 'volume-'.$inscricaoId.'-b',
                'situacao' => SituacaoPagamento::Pendente->value,
            ],
            'expirada' => [
                'codigo_publico' => (string) Str::ulid(),
                'id_externo' => 'volume-'.$inscricaoId.'-b',
                'situacao' => SituacaoPagamento::Expirado->value,
                'cancelado_em' => $prazo->toDateTimeString(),
            ],
            default => [
                'codigo_publico' => (string) Str::ulid(),
                'id_externo' => 'volume-'.$inscricaoId.'-b',
                'situacao' => SituacaoPagamento::Cancelado->value,
                'cancelado_em' => $criadaEm->copy()->addDays(2)->toDateTimeString(),
            ],
        });

        return $pagamentos;
    }

    /**
     * @param  array<int, array<string, mixed>>  $inscricoes
     * @param  array<int, array<string, mixed>>  $escolhas
     * @param  array<int, array<string, mixed>>  $pagamentos
     */
    private function descarregar(array &$inscricoes, array &$escolhas, array &$pagamentos): void
    {
        if ($inscricoes === []) {
            return;
        }

        DB::transaction(function () use (&$inscricoes, &$escolhas, &$pagamentos): void {
            DB::table('inscricoes')->insert($inscricoes);

            foreach (array_chunk($escolhas, self::LOTE) as $parte) {
                DB::table('inscricoes_atividades')->insert($parte);
            }

            foreach (array_chunk($pagamentos, self::LOTE) as $parte) {
                DB::table('pagamentos')->insert($parte);
            }
        });

        $inscricoes = [];
        $escolhas = [];
        $pagamentos = [];
    }

    /**
     * Deixa os contadores de vaga batendo com as inscricoes criadas.
     *
     * O contador gravado na atividade e a fonte da verdade do dominio: se ele
     * ficasse zerado, o painel mostraria "900 vagas livres" numa atividade com
     * 400 pessoas dentro, e a medicao mediria uma tela mentindo.
     *
     * @param  array{reservadas: array<int, int>, confirmadas: array<int, int>, evento_reservadas: int, evento_confirmadas: int}  $contadores
     */
    private function ajustarContadores(object $evento, array $contadores): void
    {
        $atividades = array_unique(array_merge(
            array_keys($contadores['reservadas']),
            array_keys($contadores['confirmadas']),
        ));

        foreach ($atividades as $atividadeId) {
            DB::table('atividades')->where('id', $atividadeId)->update([
                'vagas_reservadas' => $contadores['reservadas'][$atividadeId] ?? 0,
                'vagas_confirmadas' => $contadores['confirmadas'][$atividadeId] ?? 0,
            ]);
        }

        DB::table('eventos')->where('id', $evento->id)->update([
            'vagas_reservadas' => $contadores['evento_reservadas'],
            'vagas_confirmadas' => $contadores['evento_confirmadas'],
        ]);
    }

    /**
     * CPF sintetico, com digitos verificadores corretos.
     *
     * Precisa ser valido porque a impressao digital do documento tem indice
     * unico parcial: numeros repetidos ou malformados dariam colisao no meio
     * da carga e a medicao nunca chegaria ao fim.
     */
    private function cpfSintetico(int $indice): string
    {
        $base = str_pad((string) (100_000_000 + $indice), 9, '0', STR_PAD_LEFT);
        $digitos = array_map('intval', str_split($base));

        foreach ([10, 11] as $peso) {
            $soma = 0;

            foreach ($digitos as $posicao => $digito) {
                $soma += $digito * ($peso - $posicao);
            }

            $resto = $soma % 11;
            $digitos[] = $resto < 2 ? 0 : 11 - $resto;
        }

        return implode('', $digitos);
    }
}
