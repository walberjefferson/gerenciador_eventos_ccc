<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onde o evento acontece.
 *
 * Ate aqui o sistema sabia quando e quanto custa, mas nao sabia ONDE — e
 * "onde" e uma das tres perguntas que alguem faz antes de decidir se vai. Quem
 * se inscrevia descobria o lugar por fora do sistema, no grupo do WhatsApp.
 *
 * Sao duas colunas porque sao duas coisas diferentes: `local` e o nome curto
 * que cabe numa linha ao lado da data ("Sitio Santa Clara"), e `local_detalhe`
 * e o que a pessoa precisa saber para chegar la ("Cerca de 20 minutos do
 * centro. Estacionamento no local."). Um campo so obrigaria a escolher entre
 * caber na linha e ser util.
 *
 * As duas nascem aceitando nulo: existem eventos ja cadastrados, e um campo
 * obrigatorio aqui impediria de salvar qualquer um deles ate alguem preencher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $tabela): void {
            $tabela->string('local', 160)->nullable()->after('descricao');
            $tabela->string('local_detalhe', 255)->nullable()->after('local');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $tabela): void {
            $tabela->dropColumn(['local', 'local_detalhe']);
        });
    }
};
