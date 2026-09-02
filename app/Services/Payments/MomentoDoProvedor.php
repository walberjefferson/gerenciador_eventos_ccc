<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Traz para o fuso da aplicacao qualquer data e hora que chegue de um provedor
 * de pagamento.
 *
 * Existe por um detalhe do caminho entre o PHP e o banco que nao aparece em
 * lugar nenhum: o Laravel grava data e hora no formato "Y-m-d H:i:s", SEM o
 * fuso escrito. Quem interpreta o que esta escrito e o PostgreSQL, usando o
 * fuso da sessao — que e o fuso da aplicacao.
 *
 * A consequencia: um instante que chega marcado em UTC (a Efi devolve o
 * horario do Pix em RFC 3339, muitas vezes terminado em "Z") seria escrito com
 * os numeros de Londres e lido pelo banco como se fossem numeros de Maceio.
 * O pagamento das 10h da manha viraria pagamento das 13h — tres horas no
 * futuro, no unico campo que serve de prova de quando o dinheiro entrou.
 *
 * Converter aqui, na fronteira, resolve para todos os provedores de uma vez e
 * nao muda o instante: so troca a forma de escreve-lo pela que o banco vai
 * entender.
 */
final class MomentoDoProvedor
{
    /**
     * Null quando nao veio nada, e tambem quando veio algo que nao e data:
     * inventar um horario para o que nao se entendeu e pior do que nao ter
     * horario nenhum — quem chama trata a ausencia usando o relogio local.
     */
    public static function deTexto(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
