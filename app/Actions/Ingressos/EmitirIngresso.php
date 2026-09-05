<?php

declare(strict_types=1);

namespace App\Actions\Ingressos;

use App\Enums\SituacaoInscricao;
use App\Models\Ingresso;
use App\Models\Inscricao;
use App\Services\Ingressos\GeradorDeCodigo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Faz nascer o ingresso de uma inscricao confirmada.
 *
 * E idempotente por construcao, e isso nao e detalhe: o aviso do provedor de
 * pagamento pode chegar duas vezes, a reconciliacao pode passar no mesmo
 * instante e o comando de backfill pode ser executado quantas vezes quiserem.
 * Chamar duas vezes devolve o MESMO registro — nunca um segundo ingresso.
 *
 * A garantia vem em duas camadas, e a de baixo e a que vale: a unicidade de
 * "inscricao_id" no banco. A consulta em PHP que vem antes existe apenas para
 * evitar o trabalho inutil; ela sozinha nao segura duas requisicoes
 * simultaneas, e por isso a colisao tambem e tratada.
 */
class EmitirIngresso
{
    /**
     * Quantas vezes insistir quando o codigo sorteado ja existir.
     *
     * Com ~60 bits de entropia, uma colisao e praticamente impossivel — mas
     * "praticamente" nao e "nunca", e a alternativa a insistir seria devolver
     * erro a quem acabou de pagar.
     */
    private const TENTATIVAS = 5;

    public function __construct(private readonly GeradorDeCodigo $gerarCodigo) {}

    /**
     * @throws InvalidArgumentException quando a inscricao ainda nao esta confirmada
     */
    public function __invoke(Inscricao $inscricao, ?Carbon $momento = null): Ingresso
    {
        if ($inscricao->situacao !== SituacaoInscricao::Confirmada) {
            // Ingresso e o comprovante de que a vaga esta paga. Emitir antes
            // disso criaria uma credencial valida para quem ainda pode expirar.
            throw new InvalidArgumentException(
                'So inscricao confirmada tem ingresso. Situacao atual: '.$inscricao->situacao->value.'.'
            );
        }

        $existente = $this->doBanco($inscricao);

        if ($existente instanceof Ingresso) {
            return $existente;
        }

        for ($tentativa = 1; $tentativa <= self::TENTATIVAS; $tentativa++) {
            try {
                return Ingresso::create([
                    'inscricao_id' => $inscricao->getKey(),
                    'codigo' => ($this->gerarCodigo)(),
                    'emitido_em' => $momento ?? Carbon::now(),
                ]);
            } catch (UniqueConstraintViolationException $colisao) {
                // Duas unicidades podem ter recusado a gravacao, e a diferenca
                // importa: se ja existe ingresso para esta inscricao, alguem
                // chegou primeiro e o trabalho esta feito; se foi o codigo que
                // repetiu, sorteamos outro.
                $existente = $this->doBanco($inscricao);

                if ($existente instanceof Ingresso) {
                    return $existente;
                }

                if ($tentativa === self::TENTATIVAS) {
                    throw new RuntimeException(
                        'Nao consegui sortear um codigo de ingresso livre para a inscricao '
                        .$inscricao->codigo_publico.'.',
                        previous: $colisao,
                    );
                }
            }
        }

        // Inalcancavel: o laco acima ou devolve, ou lanca.
        throw new RuntimeException('Emissao de ingresso terminou sem resultado.');
    }

    private function doBanco(Inscricao $inscricao): ?Ingresso
    {
        return Ingresso::query()->where('inscricao_id', $inscricao->getKey())->first();
    }
}
