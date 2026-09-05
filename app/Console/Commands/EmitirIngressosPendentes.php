<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Ingressos\EmitirIngresso;
use App\Enums\SituacaoInscricao;
use App\Models\Evento;
use App\Models\Inscricao;
use Illuminate\Console\Command;
use Throwable;

/**
 * Da ingresso a quem ja estava confirmado antes de o ingresso existir.
 *
 * Toda inscricao confirmada de agora em diante nasce com ingresso, pelo
 * ouvinte de InscricaoConfirmada. As que foram confirmadas ANTES desta entrega
 * — e as poucas em que a emissao falhou e ficou registrada no log — sao
 * atendidas por aqui.
 *
 * E comando, e nao migration de dados, de proposito: migration que cria
 * registro so roda uma vez, e nao da para repetir quando alguem precisa
 * conferir o resultado. Este comando pode ser executado quantas vezes se
 * quiser — quem ja tem ingresso e simplesmente pulado.
 */
class EmitirIngressosPendentes extends Command
{
    protected $signature = 'ingressos:emitir-pendentes
                            {--evento= : limita a um evento (id ou codigo publico)}';

    protected $description = 'Emite o ingresso das inscricoes confirmadas que ainda nao tem um';

    public function handle(EmitirIngresso $emitirIngresso): int
    {
        $evento = $this->eventoInformado();

        if ($evento === false) {
            $this->components->error('Evento nao encontrado.');

            return self::FAILURE;
        }

        $emitidos = 0;
        $falhas = 0;

        Inscricao::query()
            ->where('situacao', SituacaoInscricao::Confirmada->value)
            ->when($evento instanceof Evento, fn ($consulta) => $consulta->where('evento_id', $evento->getKey()))
            ->whereDoesntHave('ingresso')
            // Em faixas de identificador: uma varredura grande nao pode
            // carregar o evento inteiro na memoria.
            ->chunkById(200, function ($inscricoes) use ($emitirIngresso, &$emitidos, &$falhas): void {
                foreach ($inscricoes as $inscricao) {
                    try {
                        $emitirIngresso($inscricao);
                        $emitidos++;
                    } catch (Throwable $falha) {
                        $falhas++;

                        $this->components->warn(
                            "Inscricao {$inscricao->codigo_publico}: {$falha->getMessage()}"
                        );
                    }
                }
            });

        $this->components->info($emitidos === 0
            ? 'Nenhuma inscricao confirmada estava sem ingresso.'
            : "Ingressos emitidos nesta execucao: {$emitidos}.");

        if ($falhas > 0) {
            $this->components->error("Inscricoes que nao receberam ingresso: {$falhas}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return Evento|null|false false quando o evento pedido nao existe
     */
    private function eventoInformado(): Evento|null|false
    {
        $referencia = $this->option('evento');

        if ($referencia === null || $referencia === '') {
            return null;
        }

        $evento = Evento::query()
            ->where('codigo_publico', $referencia)
            ->when(ctype_digit((string) $referencia), fn ($consulta) => $consulta->orWhere('id', (int) $referencia))
            ->first();

        return $evento ?? false;
    }
}
