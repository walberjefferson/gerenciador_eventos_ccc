<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comunicacoes_enviadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscricao_id')->constrained('inscricoes')->restrictOnDelete();
            // Qual mensagem foi enviada (App\Enums\TipoComunicacao).
            $table->string('tipo', 40);
            // Por onde ela saiu. Hoje so existe "email"; a coluna nasce aqui
            // para que um segundo canal entre sem migracao e sem reescrever a
            // regra de "uma vez so".
            $table->string('canal', 20)->default('email');
            // O endereco de fato usado. Serve para investigar entrega quando
            // alguem diz "nao recebi": o e-mail pode ter mudado depois.
            $table->string('destino', 190);
            $table->timestampTz('enviada_em');
            $table->timestampsTz();

            // O coracao desta fase. Nao e um "if" no PHP que impede a mensagem
            // repetida — e este indice. Dois processos que peguem o mesmo
            // trabalho ao mesmo tempo passam os dois por qualquer verificacao
            // em memoria; aqui, o segundo esbarra no banco e desiste.
            $table->unique(['inscricao_id', 'tipo', 'canal']);

            // Para olhar o que saiu num periodo sem varrer a tabela inteira.
            $table->index(['tipo', 'enviada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comunicacoes_enviadas');
    }
};
