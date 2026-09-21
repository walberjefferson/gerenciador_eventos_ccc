<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\DTOs\Inscricoes\DadosEdicaoInscricao;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Inscricoes\InscricaoDuplicadaException;
use App\Exceptions\Inscricoes\SelecaoAtividadesInvalidaException;
use App\Exceptions\Inscricoes\VagasEsgotadasException;
use App\Models\Atividade;
use App\Models\Inscricao;
use App\Services\Inscricoes\ValidadorSelecaoAtividades;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Corrige uma inscricao que ja existe: dados da pessoa, grupo e atividades.
 *
 * Tudo acontece dentro de UMA transacao — conferencia, ajuste de contador e
 * gravacao. Uma recusa no meio do caminho desfaz o conjunto: nunca pode sobrar
 * vaga presa por uma troca de atividade que nao chegou a ser gravada, nem
 * atividade gravada sem a vaga correspondente.
 *
 * TRES COISAS QUE ESTA ACTION NAO FAZ, E A AUSENCIA E A REGRA:
 *
 * 1. **Nao mexe no contador do evento** (RN-E5). Trocar atividade nao cria nem
 *    destroi inscricao: a vaga do evento continua sendo a mesma, presa pela
 *    mesma pessoa.
 * 2. **Nao devolve vaga ao lote** (RN-L6). Vaga de lote so cresce, e a regra
 *    nao muda porque a alteracao veio do painel em vez do formulario.
 * 3. **Nao recalcula valor_centavos.** O preco e fotografado no instante da
 *    inscricao, e este sistema nao cobra por atividade.
 *
 * FURAR A LOTACAO NAO E MEXER NO CONTADOR: E SUBIR A CAPACIDADE. O banco nao
 * aceita meio termo — "atividades_capacidade_check" garante, no schema, que
 * vagas_reservadas + vagas_confirmadas nunca passa de capacidade. Entao nao ha
 * como "passar por cima" do limite escrevendo um contador maior: o PostgreSQL
 * recusa a linha, e recusa com razao. Quando alguem confirma que quer inscrever
 * mesmo assim, o que acontece e o que a frase quer dizer de verdade — aquela
 * atividade passa a caber uma pessoa a mais, e a capacidade sobe junto com o
 * contador, na mesma transacao e no mesmo registro de auditoria. A lotacao
 * continua sendo verdade; o que mudou foi a lotacao, por decisao de gente.
 *
 * A capacidade sobe para caber EXATAMENTE quem esta entrando, e nao um a mais:
 * para todo o resto do sistema — inclusive o formulario publico — a atividade
 * continua cheia no instante seguinte.
 *
 * QUAL CONTADOR TOCAR DEPENDE DA SITUACAO (RN-E3): quem aguarda pagamento tem
 * vaga RESERVADA, quem esta confirmado tem vaga CONFIRMADA, e quem expirou, foi
 * cancelado ou esta na lista de espera **nao tem vaga presa nenhuma** — nesses
 * casos trocar atividade nao toca contador. Corrigir o nome de alguem que
 * cancelou continua sendo legitimo (RN-E8); o que nao existe ali e vaga a
 * mover.
 */
class AtualizarInscricaoAdministrativa
{
    public function __construct(
        private readonly ValidadorSelecaoAtividades $validadorSelecao,
        private readonly ReservarVagas $reservarVagas,
    ) {}

    /**
     * Aplica a correcao e devolve o que mudou nas atividades, para a auditoria.
     *
     * O diff dos campos de cadastro nao vem daqui: ele sai do proprio Eloquent,
     * no controller, comparando o "antes" com o que foi gravado. O que so esta
     * Action sabe dizer e o que aconteceu com as ATIVIDADES — e se alguma
     * lotacao foi furada no caminho.
     *
     * @return array{atividades_adicionadas: array<int, string>, atividades_removidas: array<int, string>, lotacao_excedida: array<int, array{atividade: string, capacidade_antes: int, capacidade_depois: int}>}
     *
     * @throws SelecaoAtividadesInvalidaException
     * @throws InscricaoDuplicadaException
     */
    public function __invoke(Inscricao $inscricao, DadosEdicaoInscricao $dados): array
    {
        return DB::transaction(function () use ($inscricao, $dados): array {
            $inscricao->loadMissing(['evento', 'atividades']);

            $evento = $inscricao->evento;

            // RN-E1 — a selecao nova passa pelas MESMAS regras do formulario
            // publico (RN-03 a RN-08), e com a data de nascimento NOVA: corrigir
            // o ano de nascimento pode tornar proibida uma atividade que ja
            // estava escolhida, e e melhor descobrir isso aqui do que no portao.
            $escolhidas = ($this->validadorSelecao)($evento, $dados->atividadeIds, $dados->dataNascimento);

            $resultado = $this->ajustarAtividades($inscricao, $escolhidas, $dados->permitirExcederCapacidade);

            $this->gravarCadastro($inscricao, $dados);

            $inscricao->atividades()->sync($escolhidas->pluck('id')->all());

            return $resultado;
        });
    }

    /**
     * Grava os campos de cadastro.
     *
     * O CPF nao esta aqui, e nao esta por decisao: trocar o documento mexeria na
     * chave que impede a mesma pessoa de se inscrever duas vezes no mesmo
     * evento. Tambem nao estao evento, lote, valor, chave de idempotencia nem
     * as colunas de momento — nada disso e correcao de cadastro.
     *
     * @throws InscricaoDuplicadaException
     */
    private function gravarCadastro(Inscricao $inscricao, DadosEdicaoInscricao $dados): void
    {
        $inscricao->fill([
            'nome_completo' => $dados->nomeCompleto,
            'email' => $dados->email,
            'telefone' => $dados->telefone,
            'data_nascimento' => $dados->dataNascimento->toDateString(),
            'sexo' => $dados->sexo,
            // O setor vem junto: ele e a cidade do grupo, e nao coluna da
            // inscricao. Trocar o grupo e trocar o setor, num gesto so.
            'grupo_participante_id' => $dados->grupoParticipanteId,
        ]);

        try {
            $inscricao->save();
        } catch (QueryException $excecao) {
            // O banco recusa dois e-mails iguais entre as inscricoes ATIVAS do
            // mesmo evento. Sem esta traducao, corrigir um e-mail para um que ja
            // existe viraria erro 500 — e quem esta no balcao nao saberia que o
            // problema e esse.
            if (str_contains($excecao->getMessage(), 'inscricoes_email_ativa_unique')) {
                throw InscricaoDuplicadaException::porEmail();
            }

            throw $excecao;
        }
    }

    /**
     * Move as vagas das atividades que entraram e das que sairam — e so delas.
     *
     * RN-E4: atividade que continua escolhida NAO e liberada e presa de novo.
     * Alem de trabalho inutil, isso abriria uma janela real: entre soltar e
     * prender, outra pessoa poderia levar a vaga de quem ja estava dentro.
     *
     * A ordem e a canonica de ReservarVagas e LiberarVagas — atividades em
     * ordem crescente de id, sem excecao. As liberacoes e as reservas viajam na
     * MESMA lista ordenada, e nao em dois blocos: ordem fixa e o que impede duas
     * operacoes simultaneas de travarem uma esperando a outra.
     *
     * @param  Collection<int, Atividade>  $escolhidas
     * @return array{atividades_adicionadas: array<int, string>, atividades_removidas: array<int, string>, lotacao_excedida: array<int, array{atividade: string, capacidade_antes: int, capacidade_depois: int}>}
     *
     * @throws SelecaoAtividadesInvalidaException
     */
    private function ajustarAtividades(Inscricao $inscricao, Collection $escolhidas, bool $podeExceder): array
    {
        $antes = $inscricao->atividades->keyBy('id');
        $depois = $escolhidas->keyBy('id');

        $removidas = $antes->diffKeys($depois);
        $adicionadas = $depois->diffKeys($antes);

        $contador = $this->contadorDaSituacao($inscricao->situacao);

        $excedidas = [];

        if ($contador !== null) {
            // Uma lista so, ordenada por id: e a ordem que evita travamento.
            $movimentos = $removidas->map(fn (Atividade $atividade): array => ['atividade' => $atividade, 'liberar' => true])
                ->concat($adicionadas->map(fn (Atividade $atividade): array => ['atividade' => $atividade, 'liberar' => false]))
                ->sortBy(fn (array $movimento): int => (int) $movimento['atividade']->id)
                ->values();

            foreach ($movimentos as $movimento) {
                /** @var Atividade $atividade */
                $atividade = $movimento['atividade'];

                if ($movimento['liberar'] === true) {
                    $this->liberar($atividade, $contador);

                    continue;
                }

                $excedeu = $this->prender($atividade, $contador, $podeExceder);

                if ($excedeu !== null) {
                    $excedidas[] = $excedeu;
                }
            }
        }

        return [
            'atividades_adicionadas' => $adicionadas->map(fn (Atividade $a): string => (string) $a->nome)->values()->all(),
            'atividades_removidas' => $removidas->map(fn (Atividade $a): string => (string) $a->nome)->values()->all(),
            'lotacao_excedida' => $excedidas,
        ];
    }

    /**
     * Qual coluna guarda a vaga desta inscricao — ou nenhuma (RN-E3).
     */
    private function contadorDaSituacao(SituacaoInscricao $situacao): ?string
    {
        return match ($situacao) {
            SituacaoInscricao::AguardandoPagamento => 'vagas_reservadas',
            SituacaoInscricao::Confirmada => 'vagas_confirmadas',
            // Expirada, cancelada e lista de espera nao tem vaga presa: nao ha
            // contador a tocar, e tocar um seria inventar vaga do nada.
            default => null,
        };
    }

    /**
     * Devolve a vaga da atividade que saiu.
     *
     * A condicao "> 0" e a mesma de LiberarVagas: repetir a operacao nao gera
     * contador negativo nem vaga do nada.
     */
    private function liberar(Atividade $atividade, string $contador): void
    {
        DB::update(
            "UPDATE atividades
                SET {$contador} = {$contador} - 1, updated_at = now()
              WHERE id = ? AND {$contador} > 0",
            [$atividade->id],
        );
    }

    /**
     * Prende a vaga da atividade que entrou. Devolve o registro da lotacao
     * aumentada quando foi preciso aumenta-la — e `null` quando coube.
     *
     * A primeira tentativa SEMPRE respeita a capacidade, mesmo com a
     * confirmacao em maos (RN-E2): se ainda ha vaga, ninguem furou fila nenhuma
     * e nao ha o que registrar. Furar so acontece quando de fato nao cabia.
     *
     * Nao existe "consultar e depois gravar" em nenhum dos dois caminhos: o
     * UPDATE condicional e o que impede vender a mais.
     *
     * @return array{atividade: string, capacidade_antes: int, capacidade_depois: int}|null
     *
     * @throws SelecaoAtividadesInvalidaException
     */
    private function prender(Atividade $atividade, string $contador, bool $podeExceder): ?array
    {
        if ($contador === 'vagas_reservadas') {
            try {
                // O mesmo comando que o formulario publico usa. Reaproveitar e
                // o que garante que a condicao de capacidade seja escrita uma
                // vez so no sistema inteiro.
                $this->reservarVagas->reservarNaAtividade($atividade);

                return null;
            } catch (VagasEsgotadasException) {
                // Nao cabia. Se ha confirmacao para furar, o caminho continua
                // abaixo; se nao ha, a recusa sai logo em seguida.
            }
        } else {
            // Inscricao ja confirmada: a vaga nasce paga. O total ocupado
            // continua sendo reservadas + confirmadas, entao a condicao de
            // capacidade e a mesma — muda so a coluna que cresce.
            $linhas = DB::update(
                'UPDATE atividades
                    SET vagas_confirmadas = vagas_confirmadas + 1, updated_at = now()
                  WHERE id = ?
                    AND (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas < capacidade)',
                [$atividade->id],
            );

            if ($linhas > 0) {
                return null;
            }
        }

        if (! $podeExceder) {
            // A recusa vai agrupada em "atividades" porque e do campo de
            // atividades que a pessoa precisa cuidar — o mesmo lugar onde as
            // recusas de conflito e idade ja aparecem. E ela diz que ha saida:
            // sem isso, quem precisa mesmo colocar mais uma pessoa numa
            // atividade cheia acharia que o sistema simplesmente nao deixa.
            throw SelecaoAtividadesInvalidaException::com([
                "{$atividade->nome} está com a lotação esgotada. Para inscrever mesmo assim, marque a confirmação de que a lotação pode ser excedida.",
            ]);
        }

        // A capacidade sobe junto, no mesmo comando: qualquer ordem diferente
        // deixaria a linha, por um instante, num estado que o CHECK recusa.
        // "capacidade IS NOT NULL" e cinto de seguranca — atividade sem limite
        // nunca chega ate aqui, porque a tentativa de cima teria funcionado.
        DB::update(
            "UPDATE atividades
                SET capacidade = vagas_reservadas + vagas_confirmadas + 1,
                    {$contador} = {$contador} + 1,
                    updated_at = now()
              WHERE id = ? AND capacidade IS NOT NULL",
            [$atividade->id],
        );

        $depois = (int) DB::table('atividades')->where('id', $atividade->id)->value('capacidade');

        return [
            'atividade' => (string) $atividade->nome,
            'capacidade_antes' => (int) $atividade->capacidade,
            'capacidade_depois' => $depois,
        ];
    }
}
