<?php

declare(strict_types=1);

namespace Tests\Feature\Inscricoes;

use App\Actions\Inscricoes\CriarInscricao;
use App\DTOs\Inscricoes\DadosNovaInscricao;
use App\Models\Atividade;
use App\Models\Cidade;
use App\Models\DiaEvento;
use App\Models\Evento;
use App\Models\GrupoAtividade;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Monta um evento de teste parecido com o real e entrega os dados de uma
 * inscricao valida, para que cada teste precise dizer apenas o que quer mudar.
 *
 * Programacao do dia (um mes a frente):
 *   Modalidade esportiva (obrigatorio, de 1 a 2 escolhas)
 *     - Futebol    09:00 as 11:00
 *     - Volei      11:00 as 13:00  (encosta no futebol, mas nao se sobrepoe)
 *     - Natacao    10:00 as 12:00  (sobrepoe o futebol pela metade)
 *     - Aquecimento 09:30 as 10:00 (acontece inteiro dentro do futebol)
 *   Trilha (opcional, no maximo 1)
 *     - Trilha da Pedra 14:00 as 18:00
 */
final class Cenario
{
    public Evento $evento;

    public DiaEvento $dia;

    public GrupoAtividade $esportes;

    public GrupoAtividade $trilhas;

    public Atividade $futebol;

    public Atividade $volei;

    public Atividade $natacao;

    public Atividade $aquecimento;

    public Atividade $trilha;

    public Cidade $cidade;

    public GrupoParticipante $grupoParticipante;

    public Carbon $dataDoDia;

    /**
     * @param  array<string, mixed>  $atributosDoEvento
     */
    public static function montar(array $atributosDoEvento = []): self
    {
        $cenario = new self;

        $cenario->evento = Evento::factory()->create($atributosDoEvento);
        $cenario->dataDoDia = Carbon::now()->addMonth()->startOfDay();

        $cenario->dia = DiaEvento::factory()->for($cenario->evento)->create([
            'nome' => 'Sábado',
            'data' => $cenario->dataDoDia->toDateString(),
            'posicao' => 1,
        ]);

        $cenario->esportes = GrupoAtividade::factory()
            ->for($cenario->dia)
            ->obrigatorio(1, 2)
            ->create(['nome' => 'Modalidade esportiva', 'posicao' => 1]);

        $cenario->trilhas = GrupoAtividade::factory()
            ->for($cenario->dia)
            ->opcional(0, 1)
            ->create(['nome' => 'Trilha', 'posicao' => 2]);

        $cenario->futebol = $cenario->atividade($cenario->esportes, 'Futebol', 9, 11);
        $cenario->volei = $cenario->atividade($cenario->esportes, 'Vôlei', 11, 13);
        $cenario->natacao = $cenario->atividade($cenario->esportes, 'Natação', 10, 12);
        $cenario->aquecimento = $cenario->atividade($cenario->esportes, 'Aquecimento', 9.5, 10);
        $cenario->trilha = $cenario->atividade($cenario->trilhas, 'Trilha da Pedra', 14, 18);

        // O nome vem da fabrica (sempre unico): mais de um cenario pode viver no
        // mesmo teste, e o catalogo de cidades nao aceita nome repetido na UF.
        $cenario->cidade = Cidade::factory()->create(['uf' => 'SP']);
        $cenario->grupoParticipante = GrupoParticipante::factory()
            ->for($cenario->cidade)
            ->create(['nome' => 'Grupo Central']);

        return $cenario;
    }

    /**
     * Cria uma atividade do cenario no horario informado, em horas do dia.
     */
    public function atividade(GrupoAtividade $grupo, string $nome, float $comeca, float $termina): Atividade
    {
        return Atividade::factory()->for($grupo)->create([
            'nome' => $nome,
            'comeca_em' => $this->hora($comeca),
            'termina_em' => $this->hora($termina),
        ]);
    }

    public function hora(float $hora): Carbon
    {
        return $this->dataDoDia->copy()->addMinutes((int) round($hora * 60));
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    public function payload(array $sobrescritas = []): array
    {
        return array_merge([
            'evento_id' => $this->evento->id,
            'cidade_id' => $this->cidade->id,
            'grupo_participante_id' => $this->grupoParticipante->id,
            'nome_completo' => 'Maria da Silva',
            'email' => 'maria.silva@example.com',
            'telefone' => '(16) 98888-7777',
            'documento' => '529.982.247-25',
            'data_nascimento' => Carbon::now()->subYears(30)->toDateString(),
            'atividades' => [$this->futebol->id],
            'aceite_termos' => true,
            'chave_idempotencia' => (string) Str::uuid(),
        ], $sobrescritas);
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     */
    public function dados(array $sobrescritas = []): DadosNovaInscricao
    {
        return DadosNovaInscricao::deArray($this->payload($sobrescritas));
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     */
    public function inscrever(array $sobrescritas = []): Inscricao
    {
        return app(CriarInscricao::class)($this->dados($sobrescritas));
    }

    /**
     * Dados de outra pessoa, para os testes de disputa por vaga.
     *
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    public function outraPessoa(int $indice, array $sobrescritas = []): array
    {
        return $this->payload(array_merge([
            'nome_completo' => "Participante {$indice}",
            'email' => "participante{$indice}@example.com",
            'documento' => self::cpfValido($indice),
        ], $sobrescritas));
    }

    /**
     * Gera um CPF ficticio com digitos verificadores corretos.
     */
    public static function cpfValido(int $semente): string
    {
        $digitos = str_pad((string) ($semente % 1000000000), 9, '0', STR_PAD_LEFT);

        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $digitos[$i] * (($posicao + 1) - $i);
            }

            $digitos .= (string) (((10 * $soma) % 11) % 10);
        }

        return $digitos;
    }
}
