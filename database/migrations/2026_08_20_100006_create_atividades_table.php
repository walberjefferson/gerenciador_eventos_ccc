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
        Schema::create('atividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_atividade_id')->constrained('grupos_atividades')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->timestampTz('comeca_em');
            $table->timestampTz('termina_em');
            $table->integer('capacidade')->nullable();
            $table->smallInteger('idade_minima')->nullable();
            $table->smallInteger('idade_maxima')->nullable();
            $table->smallInteger('posicao')->default(1);
            $table->boolean('ativo')->default(true);
            $table->jsonb('configuracoes')->default('{}');
            $table->integer('vagas_reservadas')->default(0);
            $table->integer('vagas_confirmadas')->default(0);
            $table->timestampsTz();

            $table->index(['grupo_atividade_id', 'ativo']);
            $table->index(['comeca_em', 'termina_em']);
        });

        DB::statement('ALTER TABLE atividades ADD CONSTRAINT atividades_horario_check
            CHECK (termina_em > comeca_em)');

        DB::statement('ALTER TABLE atividades ADD CONSTRAINT atividades_capacidade_check
            CHECK (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas <= capacidade)');

        DB::statement('ALTER TABLE atividades ADD CONSTRAINT atividades_vagas_nao_negativas_check
            CHECK (vagas_reservadas >= 0 AND vagas_confirmadas >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('atividades');
    }
};
