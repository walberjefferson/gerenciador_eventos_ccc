<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflitos_atividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('atividade_a_id')->constrained('atividades')->cascadeOnDelete();
            $table->foreignId('atividade_b_id')->constrained('atividades')->cascadeOnDelete();
            $table->string('motivo', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['atividade_a_id', 'atividade_b_id']);
            $table->index(['atividade_b_id']);
        });

        // Par normalizado: o menor identificador vem sempre primeiro. Sem isso,
        // (3, 7) e (7, 3) seriam duas linhas para o mesmo conflito e a
        // unicidade nao protegeria nada.
        DB::statement('ALTER TABLE conflitos_atividades ADD CONSTRAINT conflitos_atividades_par_normalizado_check
            CHECK (atividade_a_id < atividade_b_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('conflitos_atividades');
    }
};
