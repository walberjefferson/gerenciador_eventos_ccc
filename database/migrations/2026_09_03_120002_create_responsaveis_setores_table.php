<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsaveis_setores', function (Blueprint $table) {
            // O vinculo N:N da RN-R2: um setor tem varios responsaveis, e um
            // responsavel atende varios setores.
            //
            // "cascadeOnDelete" dos dois lados apaga O VINCULO, e so ele:
            // sumir o setor nao apaga a pessoa, e sumir a pessoa nao apaga o
            // setor. Quem impede que uma pessoa que ja recebeu dinheiro seja
            // apagada e a chave estrangeira de "pagamentos" (RN-R9), nao esta.
            $table->foreignId('cidade_id')->constrained('cidades')->cascadeOnDelete();
            $table->foreignId('responsavel_id')->constrained('responsaveis')->cascadeOnDelete();

            // Sem "id" proprio e sem timestamps: nao ha nada a dizer sobre o
            // vinculo alem de que ele existe. A chave primaria composta e a
            // propria regra "o mesmo par so entra uma vez".
            $table->primary(['cidade_id', 'responsavel_id']);

            // O caminho de volta, que e o do escopo de conferencia: dado o
            // responsavel, quais setores ele atende.
            $table->index(['responsavel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsaveis_setores');
    }
};
