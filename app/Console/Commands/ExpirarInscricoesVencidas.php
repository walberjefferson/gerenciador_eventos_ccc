<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Inscricoes\ExpirarInscricoesVencidas as ExpirarInscricoes;
use App\Models\Evento;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Devolve para a fila as vagas presas por quem nao pagou dentro do prazo.
 *
 * Roda de minuto em minuto pelo agendador. Nao apaga nada: a inscricao passa a
 * "expirada", a cobranca passa a "prazo vencido" e os contadores de vaga do
 * evento e de cada atividade voltam ao que eram.
 *
 * Rodar duas vezes seguidas nao causa dano: na segunda execucao nao ha mais
 * ninguem aguardando pagamento com prazo vencido, e o comando devolve zero.
 */
class ExpirarInscricoesVencidas extends Command
{
    protected $signature = 'inscricoes:expirar-vencidas
                            {--evento= : limita a varredura a um evento (id ou codigo publico)}';

    protected $description = 'Expira inscricoes com prazo de pagamento vencido e devolve as vagas';

    public function handle(ExpirarInscricoes $expirar): int
    {
        $evento = $this->eventoInformado();

        if ($evento === false) {
            $this->components->error('Evento nao encontrado.');

            return self::FAILURE;
        }

        $expiradas = $expirar($evento, Carbon::now());

        $this->components->info($expiradas === 0
            ? 'Nenhuma inscricao vencida: nada a devolver.'
            : "Inscricoes expiradas nesta execucao: {$expiradas}.");

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
