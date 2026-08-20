<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscricoes_atividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscricao_id')->constrained('inscricoes')->cascadeOnDelete();
            // restrictOnDelete: uma atividade com gente inscrita nao pode ser
            // apagada por engano; a organizacao desativa em vez de excluir.
            $table->foreignId('atividade_id')->constrained('atividades')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['inscricao_id', 'atividade_id']);
            $table->index(['atividade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscricoes_atividades');
    }
};
