<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SituacaoInscricao;
use App\Enums\TipoComunicacao;
use App\Mail\EmailDaInscricao;
use App\Mail\LembretePrazoMail;
use App\Models\ComunicacaoEnviada;
use App\Models\Inscricao;
use App\Services\Comunicacao\RegistrarEnvio;
use App\Services\Inscricoes\GeradorLinkDeAcesso;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Cutuca, uma vez so, quem ainda nao pagou e esta perto de perder a vaga.
 *
 * Nenhum fato do dominio acontece quando o tempo passa — por isso este e o
 * unico dos cinco e-mails que nasce de uma rotina agendada, e nao de um
 * anuncio. A rotina roda a cada quinze minutos e olha para quem esta
 * aguardando pagamento com prazo vencendo dentro da janela configurada
 * (padrao: as proximas 24 horas).
 *
 * Rodar duas vezes seguidas nao manda dois lembretes. Isso nao depende do
 * agendador nem da consulta abaixo: quem recusa a segunda copia e a unicidade
 * de comunicacoes_enviadas. A consulta apenas evita carregar de novo quem ja
 * recebeu — economia de trabalho, nao garantia.
 *
 * Quem ja confirmou, expirou ou foi cancelado nao entra: a consulta so olha
 * para "aguardando pagamento".
 */
class LembrarPrazoPagamento extends Command
{
    protected $signature = 'inscricoes:lembrar-prazo
                            {--janela= : quantas horas antes do prazo o lembrete sai}
                            {--lote= : quantas inscricoes carregar por vez}';

    protected $description = 'Lembra quem esta perto de perder a vaga por falta de pagamento';

    public function handle(RegistrarEnvio $registrar, GeradorLinkDeAcesso $links): int
    {
        $janela = $this->horasDaJanela();
        $lote = $this->tamanhoDoLote();

        if ($janela === false) {
            $this->components->error('A janela precisa ser um numero de horas maior que zero.');

            return self::FAILURE;
        }

        $agora = Carbon::now();
        $limite = $agora->copy()->addHours($janela);
        $enviados = 0;

        $this->paraLembrar($agora, $limite)
            ->chunkById($lote, function (Collection $inscricoes) use (&$enviados, $registrar, $links, $agora): void {
                foreach ($inscricoes as $inscricao) {
                    $enviados += $this->lembrar($inscricao, $agora, $registrar, $links) ? 1 : 0;
                }
            });

        $this->components->info($enviados === 0
            ? 'Nenhum lembrete a enviar: ninguem novo com prazo dentro da janela.'
            : "Lembretes enviados nesta execucao: {$enviados}.");

        return self::SUCCESS;
    }

    /**
     * Quem aguarda pagamento, tem prazo dentro da janela e ainda nao recebeu o
     * lembrete.
     *
     * @return Builder<Inscricao>
     */
    private function paraLembrar(Carbon $agora, Carbon $limite)
    {
        return Inscricao::query()
            ->where('situacao', SituacaoInscricao::AguardandoPagamento->value)
            ->whereNotNull('prazo_pagamento')
            ->whereBetween('prazo_pagamento', [$agora, $limite])
            ->whereNotExists(function ($consulta): void {
                $consulta->selectRaw('1')
                    ->from('comunicacoes_enviadas')
                    ->whereColumn('comunicacoes_enviadas.inscricao_id', 'inscricoes.id')
                    ->where('comunicacoes_enviadas.tipo', TipoComunicacao::LembretePrazo->value)
                    ->where('comunicacoes_enviadas.canal', ComunicacaoEnviada::CANAL_EMAIL);
            })
            ->with('evento');
    }

    private function lembrar(
        Inscricao $inscricao,
        Carbon $agora,
        RegistrarEnvio $registrar,
        GeradorLinkDeAcesso $links,
    ): bool {
        $destino = (string) $inscricao->email;

        if ($destino === '') {
            return false;
        }

        return $registrar->umaVezPor(
            $inscricao,
            TipoComunicacao::LembretePrazo,
            $destino,
            fn () => Mail::to($destino)->send(new LembretePrazoMail(
                nome: EmailDaInscricao::primeiroNome((string) $inscricao->nome_completo),
                evento: (string) $inscricao->evento->nome,
                tempoRestante: $this->tempoRestante($agora, $inscricao->prazo_pagamento),
                valor: EmailDaInscricao::moeda((int) $inscricao->valor_centavos),
                prazo: EmailDaInscricao::momento($inscricao->prazo_pagamento),
                link: $links->para($inscricao),
            )),
        );
    }

    /**
     * "Faltam cerca de 20 horas", em vez de um carimbo de data e hora. E o
     * jeito como a pessoa pensa no assunto.
     */
    private function tempoRestante(Carbon $agora, ?Carbon $prazo): string
    {
        if ($prazo === null) {
            return 'Ainda da tempo';
        }

        $minutos = (int) ceil($agora->diffInMinutes($prazo, absolute: false));

        if ($minutos <= 0) {
            return 'O prazo está terminando agora';
        }

        if ($minutos < 60) {
            return 'Falta menos de uma hora';
        }

        $horas = (int) floor($minutos / 60);

        return $horas === 1
            ? 'Falta cerca de uma hora'
            : "Faltam cerca de {$horas} horas";
    }

    /**
     * @return int|false false quando a opcao informada nao faz sentido
     */
    private function horasDaJanela(): int|false
    {
        $informado = $this->option('janela');

        if ($informado === null || $informado === '') {
            return max(1, (int) config('inscricoes.comunicacao.lembrete.janela_horas', 24));
        }

        if (! is_numeric($informado) || (int) $informado < 1) {
            return false;
        }

        return (int) $informado;
    }

    private function tamanhoDoLote(): int
    {
        $informado = $this->option('lote');

        if ($informado !== null && $informado !== '' && is_numeric($informado) && (int) $informado > 0) {
            return (int) $informado;
        }

        return max(1, (int) config('inscricoes.comunicacao.lembrete.lote', 100));
    }
}
