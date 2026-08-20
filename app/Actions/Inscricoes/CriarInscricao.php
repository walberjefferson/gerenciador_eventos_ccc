<?php

declare(strict_types=1);

namespace App\Actions\Inscricoes;

use App\Actions\Pagamentos\CriarPagamentoDaInscricao;
use App\DTOs\Inscricoes\DadosNovaInscricao;
use App\Enums\SituacaoInscricao;
use App\Events\InscricaoCriada;
use App\Exceptions\Inscricoes\InscricaoDuplicadaException;
use App\Exceptions\Inscricoes\InscricaoIndisponivelException;
use App\Exceptions\Inscricoes\VagasEsgotadasException;
use App\Models\Evento;
use App\Models\GrupoParticipante;
use App\Models\Inscricao;
use App\Services\Inscricoes\ValidadorSelecaoAtividades;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cria uma inscricao: confere as regras, prende as vagas e grava — tudo em uma
 * transacao unica, para que nunca exista inscricao sem vaga nem vaga presa sem
 * inscricao.
 *
 * Se a vaga acabar no meio do caminho, a Action nao desiste de imediato: ela
 * varre as reservas vencidas daquele evento (a vaga pode estar presa por quem
 * ja perdeu o prazo) e tenta a transacao inteira mais uma vez. So depois disso
 * a recusa e definitiva.
 */
class CriarInscricao
{
    public function __construct(
        private readonly ValidadorSelecaoAtividades $validadorSelecao,
        private readonly ReservarVagas $reservarVagas,
        private readonly ExpirarInscricoesVencidas $expirarVencidas,
        private readonly CriarPagamentoDaInscricao $criarPagamento,
    ) {}

    public function __invoke(DadosNovaInscricao $dados): Inscricao
    {
        $evento = Evento::query()->findOrFail($dados->eventoId);

        $this->conferirJanelaDeInscricao($evento);          // RN-01
        $grupo = $this->conferirCidadeEGrupo($dados);       // RN-02
        $this->conferirAceiteDosTermos($dados);             // RN-13

        // RN-12 — o mesmo envio repetido devolve a inscricao ja criada, sem
        // prender vaga de novo.
        if ($jaCriada = $this->buscarPelaChave($evento, $dados)) {
            return $this->comCobranca($jaCriada);
        }

        try {
            $inscricao = $this->tentar($evento, $grupo, $dados);
        } catch (VagasEsgotadasException) {
            // Varredura sob demanda: a vaga pode estar presa por reservas que
            // ja venceram e que o agendador ainda nao alcancou.
            ($this->expirarVencidas)($evento);

            // Uma unica retentativa. Falhando de novo, esta esgotado de verdade.
            $inscricao = $this->tentar($evento, $grupo, $dados);
        }

        return $this->comCobranca($inscricao);
    }

    /**
     * Garante que a inscricao tenha a sua cobranca aberta, com o mesmo prazo
     * dela.
     *
     * Fica fora da transacao de proposito: emitir a cobranca conversa com um
     * servico externo, e servico externo nao pode segurar uma transacao de
     * banco aberta. Se a emissao falhar, a inscricao ja existe e a cobranca e
     * emitida na proxima tentativa — a chamada e repetivel.
     */
    private function comCobranca(Inscricao $inscricao): Inscricao
    {
        ($this->criarPagamento)($inscricao);

        return $inscricao;
    }

    private function tentar(Evento $evento, GrupoParticipante $grupo, DadosNovaInscricao $dados): Inscricao
    {
        try {
            return DB::transaction(fn (): Inscricao => $this->gravar($evento, $grupo, $dados));
        } catch (QueryException $excecao) {
            return $this->traduzirViolacaoDeUnicidade($excecao, $evento, $dados);
        }
    }

    private function gravar(Evento $evento, GrupoParticipante $grupo, DadosNovaInscricao $dados): Inscricao
    {
        // RN-03 a RN-08 — a combinacao escolhida precisa ser possivel.
        $atividades = ($this->validadorSelecao)($evento, $dados->atividadeIds, $dados->dataNascimento);

        // RN-09 e RN-10 — evento primeiro, depois cada atividade em ordem
        // crescente de id.
        ($this->reservarVagas)($evento, $atividades);

        $agora = Carbon::now();

        $inscricao = Inscricao::create([
            'evento_id' => $evento->id,
            'grupo_participante_id' => $grupo->id,
            'nome_completo' => $dados->nomeCompleto,
            'email' => $dados->email,
            'telefone' => $dados->telefone,
            'documento' => $dados->documento,
            'documento_hash' => Inscricao::hashDocumento($dados->documento),
            'data_nascimento' => $dados->dataNascimento->toDateString(),
            'situacao' => SituacaoInscricao::AguardandoPagamento,
            // Fotografia do preco e do regulamento no instante da inscricao.
            'valor_centavos' => $evento->valor_centavos,
            'versao_termos' => $evento->versao_termos,
            'termos_aceitos_em' => $agora,
            'chave_idempotencia' => $dados->chaveIdempotencia,
            'prazo_pagamento' => $agora->copy()->addMinutes($evento->prazo_pagamento_minutos),
        ]);

        if ($atividades->isNotEmpty()) {
            $inscricao->atividades()->attach($atividades->pluck('id')->all());
        }

        InscricaoCriada::dispatch($inscricao);

        return $inscricao->load('atividades');
    }

    /**
     * RN-01 — a janela de inscricao precisa estar aberta pelas datas e pela
     * situacao do evento.
     */
    private function conferirJanelaDeInscricao(Evento $evento): void
    {
        $agora = Carbon::now();

        if ($evento->inscricoes_abrem_em > $agora) {
            throw InscricaoIndisponivelException::inscricoesAindaNaoAbriram();
        }

        if (! $evento->inscricoesEstaoAbertas($agora)) {
            throw InscricaoIndisponivelException::inscricoesEncerradas();
        }
    }

    /**
     * RN-02 — o grupo escolhido precisa ser da cidade escolhida, e os dois
     * precisam estar ativos.
     */
    private function conferirCidadeEGrupo(DadosNovaInscricao $dados): GrupoParticipante
    {
        $grupo = GrupoParticipante::query()
            ->with('cidade')
            ->find($dados->grupoParticipanteId);

        if ($grupo === null
            || ! $grupo->ativo
            || $grupo->cidade_id !== $dados->cidadeId
            || $grupo->cidade === null
            || ! $grupo->cidade->ativo
        ) {
            throw InscricaoIndisponivelException::grupoNaoPertenceACidade();
        }

        return $grupo;
    }

    /**
     * RN-13 — o aceite do regulamento e obrigatorio tambem aqui, e nao apenas
     * no formulario: a requisicao pode chegar sem passar por ele.
     */
    private function conferirAceiteDosTermos(DadosNovaInscricao $dados): void
    {
        if (! $dados->aceitouTermos) {
            throw InscricaoIndisponivelException::termosNaoAceitos();
        }
    }

    private function buscarPelaChave(Evento $evento, DadosNovaInscricao $dados): ?Inscricao
    {
        return Inscricao::query()
            ->where('evento_id', $evento->id)
            ->where('chave_idempotencia', $dados->chaveIdempotencia)
            ->first();
    }

    /**
     * Traduz a recusa do banco em algo que o participante entende (RN-11) ou,
     * no caso da chave de idempotencia, devolve a inscricao que a outra
     * requisicao gravou (RN-12).
     */
    private function traduzirViolacaoDeUnicidade(
        QueryException $excecao,
        Evento $evento,
        DadosNovaInscricao $dados,
    ): Inscricao {
        if ($this->violou($excecao, 'inscricoes_evento_id_chave_idempotencia_unique')) {
            $jaCriada = $this->buscarPelaChave($evento, $dados);

            if ($jaCriada !== null) {
                return $jaCriada;
            }
        }

        if ($this->violou($excecao, 'inscricoes_email_ativa_unique')) {
            throw InscricaoDuplicadaException::porEmail();
        }

        if ($this->violou($excecao, 'inscricoes_documento_ativa_unique')) {
            throw InscricaoDuplicadaException::porDocumento();
        }

        throw $excecao;
    }

    private function violou(QueryException $excecao, string $indice): bool
    {
        return str_contains($excecao->getMessage(), $indice);
    }
}
