<?php

declare(strict_types=1);

namespace App\Actions\Presenca;

use App\Enums\AcaoAuditada;
use App\Enums\SituacaoInscricao;
use App\Exceptions\Presenca\IngressoRecusado;
use App\Models\Evento;
use App\Models\Ingresso;
use App\Models\User;
use App\Services\Auditoria\RegistrarAcao;
use App\Services\Ingressos\GeradorDeCodigo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A entrada de uma pessoa no evento, conferida no portao.
 *
 * As quatro recusas acontecem NESTA ORDEM, e a ordem e a propria regra:
 *
 * 1. **O codigo nao existe.** Antes de qualquer outra coisa — nao ha do que
 *    falar.
 * 2. **O ingresso e de outro evento.** Vem antes da conferencia da inscricao
 *    porque e a resposta mais util: a pessoa esta com o ingresso do ano
 *    passado na mao, e dizer "inscricao nao confirmada" a mandaria procurar o
 *    problema no lugar errado.
 * 3. **A inscricao nao esta mais confirmada.** Cancelada depois de paga, por
 *    exemplo: a vaga ja voltou para a fila, e o ingresso morreu junto.
 * 4. **Alguem ja entrou com este ingresso.** E a ULTIMA das recusas, e nao a
 *    primeira, porque so faz sentido falar da entrada anterior depois de o
 *    ingresso ter passado por tudo o mais.
 *
 * Passando pelas quatro, a entrada e gravada e a Action devolve o que a tela
 * precisa dizer em voz alta: o nome de quem entrou, o grupo e as atividades
 * escolhidas.
 *
 * A GRAVACAO E PROTEGIDA CONTRA A LEITURA DUPLA. Duas pessoas conferindo o
 * mesmo ingresso ao mesmo tempo — o que acontece de verdade num portao com
 * dois voluntarios — passariam as duas pela conferencia de "ja utilizado" e as
 * duas gravariam. Por isso a linha e travada dentro da transacao e a pergunta
 * e refeita: a segunda encontra o ingresso ja usado e recebe a recusa normal,
 * com a hora que a primeira acabou de gravar.
 */
class RegistrarPresenca
{
    public function __construct(private readonly RegistrarAcao $auditar) {}

    /**
     * @return array{
     *     aceito: true,
     *     ingresso_id: int,
     *     codigo_formatado: string,
     *     usado_em: string,
     *     usado_por: string,
     *     participante: array{nome: string, grupo: string|null, atividades: array<int, string>}
     * }
     *
     * @throws IngressoRecusado quando o portao diz nao — sempre com o motivo
     */
    public function __invoke(string $codigoDigitado, Evento $evento, User $responsavel, ?Carbon $momento = null): array
    {
        $codigo = GeradorDeCodigo::normalizar($codigoDigitado);

        // 1. O codigo existe?
        $ingresso = Ingresso::query()
            ->with(['inscricao.evento', 'inscricao.grupoParticipante', 'inscricao.atividades', 'usadoPor'])
            ->where('codigo', $codigo)
            ->first();

        if (! $ingresso instanceof Ingresso) {
            throw IngressoRecusado::naoEncontrado();
        }

        $inscricao = $ingresso->inscricao;

        // 2. E deste evento? A portaria opera UM evento escolhido no alto da
        //    tela; sem esta conferencia, o ingresso do ano passado entraria.
        if ($inscricao->evento_id !== $evento->getKey()) {
            throw IngressoRecusado::deOutroEvento($ingresso, (string) $inscricao->evento?->nome);
        }

        // 3. A inscricao continua confirmada?
        if ($inscricao->situacao !== SituacaoInscricao::Confirmada) {
            throw IngressoRecusado::inscricaoNaoConfirmada($inscricao);
        }

        // 4. Alguem ja entrou? (a conferencia de fora da transacao existe para
        //    responder rapido no caso comum; a que vale e a de dentro)
        if ($ingresso->estaUsado()) {
            throw IngressoRecusado::jaUtilizado($ingresso);
        }

        $agora = $momento ?? Carbon::now();

        DB::transaction(function () use ($ingresso, $responsavel, $agora): void {
            /** @var Ingresso $travado */
            $travado = Ingresso::query()
                ->whereKey($ingresso->getKey())
                ->lockForUpdate()
                ->first();

            if ($travado->estaUsado()) {
                // A outra pessoa do portao chegou primeiro, por milissegundos.
                // A recusa e a mesma de sempre, com a hora que ela gravou.
                throw IngressoRecusado::jaUtilizado($travado->load('usadoPor'));
            }

            $travado->forceFill([
                'usado_em' => $agora,
                'usado_por' => $responsavel->getKey(),
            ])->save();

            $ingresso->setRawAttributes($travado->getAttributes());
        });

        // O rastro fica fora da transacao de proposito: auditoria e
        // testemunha, nao porteiro (ver RegistrarAcao). Se a gravacao do
        // rastro falhar, a pessoa ja entrou e continua tendo entrado.
        ($this->auditar)(
            AcaoAuditada::RegistrouPresenca,
            'ingresso',
            (int) $ingresso->getKey(),
            [
                'inscricao_id' => (int) $inscricao->getKey(),
                'evento_id' => (int) $evento->getKey(),
                'usado_em' => $agora->toIso8601String(),
            ],
            responsavel: $responsavel,
        );

        return [
            'aceito' => true,
            'ingresso_id' => (int) $ingresso->getKey(),
            'codigo_formatado' => $ingresso->codigoFormatado(),
            'usado_em' => $agora->format('d/m/Y H:i'),
            'usado_por' => $responsavel->name,
            'participante' => [
                'nome' => (string) $inscricao->nome_completo,
                'grupo' => $inscricao->grupoParticipante?->nome,
                'atividades' => $inscricao->atividades->pluck('nome')->all(),
            ],
        ];
    }
}
