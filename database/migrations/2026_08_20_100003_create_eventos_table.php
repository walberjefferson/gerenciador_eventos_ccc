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
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->ulid('codigo_publico')->unique();
            $table->string('nome', 160);
            $table->string('slug', 160)->unique();
            $table->text('descricao')->nullable();
            $table->string('banner_caminho', 255)->nullable();
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->timestampTz('inscricoes_abrem_em');
            $table->timestampTz('inscricoes_fecham_em');
            $table->integer('capacidade')->nullable();
            $table->bigInteger('valor_centavos');
            $table->char('moeda', 3)->default('BRL');
            $table->integer('prazo_pagamento_minutos')->default(1440);
            $table->string('situacao', 40)->default('rascunho');
            $table->text('regulamento');
            $table->string('versao_termos', 40);
            $table->string('contato_email', 160);
            $table->string('contato_telefone', 40)->nullable();
            $table->jsonb('configuracoes')->default('{}');
            $table->integer('vagas_reservadas')->default(0);
            $table->integer('vagas_confirmadas')->default(0);
            $table->timestampsTz();

            $table->index(['situacao', 'inscricoes_abrem_em', 'inscricoes_fecham_em']);
        });

        // Ultima linha de defesa contra venda de vaga a mais: se algum caminho
        // de codigo errar a contabilidade, o banco recusa a gravacao.
        DB::statement('ALTER TABLE eventos ADD CONSTRAINT eventos_capacidade_check
            CHECK (capacidade IS NULL OR vagas_reservadas + vagas_confirmadas <= capacidade)');

        DB::statement('ALTER TABLE eventos ADD CONSTRAINT eventos_vagas_nao_negativas_check
            CHECK (vagas_reservadas >= 0 AND vagas_confirmadas >= 0)');

        DB::statement('ALTER TABLE eventos ADD CONSTRAINT eventos_periodo_check
            CHECK (data_fim >= data_inicio)');

        DB::statement('ALTER TABLE eventos ADD CONSTRAINT eventos_inscricoes_periodo_check
            CHECK (inscricoes_fecham_em > inscricoes_abrem_em)');
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos');
    }
};
