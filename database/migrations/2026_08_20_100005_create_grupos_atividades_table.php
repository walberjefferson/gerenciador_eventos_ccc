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
        Schema::create('grupos_atividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dia_evento_id')->constrained('dias_evento')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->boolean('obrigatorio')->default(false);
            $table->smallInteger('min_selecoes')->default(0);
            $table->smallInteger('max_selecoes')->nullable();
            $table->smallInteger('posicao')->default(1);
            $table->boolean('ativo')->default(true);
            $table->timestampsTz();

            $table->index(['dia_evento_id', 'ativo']);
        });

        DB::statement('ALTER TABLE grupos_atividades ADD CONSTRAINT grupos_atividades_min_check
            CHECK (min_selecoes >= 0)');

        DB::statement('ALTER TABLE grupos_atividades ADD CONSTRAINT grupos_atividades_max_check
            CHECK (max_selecoes IS NULL OR max_selecoes >= min_selecoes)');

        // Grupo obrigatorio com minimo zero nao obriga nada: configuracao
        // contraditoria que o banco recusa.
        DB::statement('ALTER TABLE grupos_atividades ADD CONSTRAINT grupos_atividades_obrigatorio_check
            CHECK (NOT obrigatorio OR min_selecoes >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos_atividades');
    }
};
