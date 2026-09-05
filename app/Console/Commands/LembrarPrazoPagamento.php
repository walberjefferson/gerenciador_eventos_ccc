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
 * Cutuca, uma vez so, quem ainda nao pagou e ja gastou metade do tempo que
 * tinha.
 *
 * Nenhum fato do dominio acontece quando o tempo passa — por isso este e o
 * unico dos cinco e-mails que nasce de uma rotina agendada, e nao de um
 * anuncio.
 *
 * O momento do aviso e proporcional, nao fixo: sai quando resta a fracao
 * configurada do prazo daquela inscricao (padrao 0.5, ou seja, metade). Quem
 * teve 24 horas e avisado faltando 12; quem teve uma hora e avisado faltando
 * 30 minutos. Uma antecedencia fixa nao serviria, porque cada evento escolhe
 * o seu prazo: 24 horas de antecedencia sobre um prazo de 24 horas fariam o
 * lembrete chegar junto com o e-mail de inscricao recebida.
 *
 * O prazo concedido e medido na propria inscricao — de quando ela foi criada
 * ate o prazo dela —, e nao no evento. Assim, mudar o prazo do evento depois
 * nao reescreve a conta de quem ja se inscreveu.
 *
 * Rodar duas vezes seguidas nao manda dois lembretes. Isso nao depende do
 * agendador nem da consulta abaixo: quem recusa a segunda copia e a unicidade
 * de comunicacoes_enviadas. A consulta apenas evita carregar de novo quem ja
 * recebeu — economia de trabalho, nao garantia.
 *
 * Quem ja confirmou, expirou ou foi cancelado nao entra: a consulta so olha
 * para "aguardando pagamento". Quem ja perdeu o prazo tambem nao — para esse,
 * o aviso e outro.
 */
class LembrarPrazoPagamento extends Command
{
    protected $signature = 'inscricoes:lembrar-prazo
                            {--fracao= : quanto do prazo precisa restar para o aviso sair (0.5 = metade)}
                            {--lote= : quantas inscricoes carregar por vez}';

    protected $description = 'Lembra quem ja gastou metade do prazo e ainda nao pagou';

    public function handle(RegistrarEnvio $registrar, GeradorLinkDeAcesso $links): int
    {
        $fracao = $this->fracaoRestante();
        $lote = $this->tamanhoDoLote();

        if ($fracao === false) {
            $this->components->error('A fracao precisa ser um numero maior que zero e no maximo 1.');

            return self::FAILURE;
        }

        $agora = Carbon::now();
        $enviados = 0;

        $this->paraLembrar($agora, $fracao)
            ->chunkById($lote, function (Collection $inscricoes) use (&$enviados, $registrar, $links, $agora): void {
                foreach ($inscricoes as $inscricao) {
                    $enviados += $this->lembrar($inscricao, $agora, $registrar, $links) ? 1 : 0;
                }
            });

        $this->components->info($enviados === 0
            ? 'Nenhum lembrete a enviar: ninguem novo passou da metade do prazo.'
            : "Lembretes enviados nesta execucao: {$enviados}.");

        return self::SUCCESS;
    }

    /**
     * Quem aguarda pagamento, ja gastou a parte combinada do proprio prazo,
     * ainda tem prazo a gastar e ainda nao recebeu o lembrete.
     *
     * A conta acontece no banco, e nao em PHP, porque ela precisa ser um
     * filtro: carregar todo mundo que aguarda pagamento para descartar a
     * maioria em memoria seria varrer o evento inteiro a cada quinze minutos.
     *
     * "prazo_pagamento - created_at" e o prazo que aquela inscricao recebeu.
     * Multiplicado pela fracao, da quanto dele ainda pode restar; somado ao
     * inicio, da o instante a partir do qual o aviso pode sair.
     *
     * @return Builder<Inscricao>
     */
    private function paraLembrar(Carbon $agora, float $fracao)
    {
        return Inscricao::query()
            ->where('situacao', SituacaoInscricao::AguardandoPagamento->value)
            ->whereNotNull('prazo_pagamento')
            ->whereNotNull('created_at')
            // Quem ja perdeu o prazo nao recebe lembrete: recebe o aviso de
            // prazo vencido, pela rotina de expiracao.
            ->where('prazo_pagamento', '>', $agora)
            // O tipo do parametro vai escrito: o PostgreSQL so sabe
            // multiplicar intervalo por "double precision", e um parametro sem
            // tipo declarado ele recusa antes de tentar.
            ->whereRaw(
                'created_at + (prazo_pagamento - created_at) * CAST(? AS double precision) <= ?',
                [1 - $fracao, $agora]
            )
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
     * Quanto do prazo precisa restar para o aviso sair.
     *
     * O limite superior e 1: uma fracao maior que o proprio prazo faria o
     * lembrete sair antes de a inscricao existir, o que nao quer dizer nada.
     *
     * @return float|false false quando a opcao informada nao faz sentido
     */
    private function fracaoRestante(): float|false
    {
        $informado = $this->option('fracao');

        if ($informado === null || $informado === '') {
            $padrao = (float) config('inscricoes.comunicacao.lembrete.fracao_restante', 0.5);

            return $padrao > 0 && $padrao <= 1 ? $padrao : 0.5;
        }

        if (! is_numeric($informado)) {
            return false;
        }

        $fracao = (float) $informado;

        return $fracao > 0 && $fracao <= 1 ? $fracao : false;
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
