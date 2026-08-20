<?php

declare(strict_types=1);

use App\Console\Commands\ExpirarInscricoesVencidas;
use App\Console\Commands\ReconciliarPagamentosPendentes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Rotinas automaticas
|--------------------------------------------------------------------------
|
| Duas tarefas mantem o estoque de vagas honesto sem ninguem precisar olhar.
|
| 1) Expiracao — de minuto em minuto. Quem nao pagou dentro do prazo perde a
|    reserva e a vaga volta imediatamente para a fila. Um minuto e o menor
|    intervalo que o agendador oferece, e e a precisao que a pessoa da fila
|    sente: no pior caso ela espera 60 segundos a mais pela vaga que vagou.
|    (A criacao de inscricao ainda faz uma varredura sob demanda quando o
|    contador diz "lotado", entao mesmo esses 60 segundos raramente pesam.)
|
| 2) Reconciliacao — a cada cinco minutos. Ela existe para o caso raro de o
|    aviso do provedor se perder; perguntar de cinco em cinco minutos e
|    suficiente para reconhecer o pagamento bem antes do prazo vencer, e
|    educado com o provedor, que cobra limite de consultas por minuto. Ela
|    olha apenas cobrancas perto do vencimento (margem padrao de 15 minutos),
|    entao o volume de consultas e pequeno.
|
| Ambas sao seguras para rodar duas vezes: cada mudanca exige a situacao
| anterior, entao a segunda execucao nao encontra nada para fazer.
|
*/

Schedule::command(ExpirarInscricoesVencidas::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(ReconciliarPagamentosPendentes::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
