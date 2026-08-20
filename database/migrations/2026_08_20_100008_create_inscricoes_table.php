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
        Schema::create('inscricoes', function (Blueprint $table) {
            $table->id();
            $table->ulid('codigo_publico')->unique();
            $table->foreignId('evento_id')->constrained('eventos')->restrictOnDelete();
            $table->foreignId('grupo_participante_id')->constrained('grupos_participantes')->restrictOnDelete();
            $table->string('nome_completo', 160);
            $table->string('email', 190);
            $table->string('telefone', 40);
            // Guardado cifrado (cast "encrypted" no model): o vazamento do banco
            // nao entrega numeros de CPF legiveis.
            $table->text('documento');
            // Impressao digital irreversivel do mesmo CPF, usada apenas para
            // comparar. Texto cifrado muda a cada gravacao e nao serve a indice.
            $table->char('documento_hash', 64);
            $table->date('data_nascimento');
            $table->string('situacao', 40)->default('aguardando_pagamento');
            // Fotografia do preco no momento da inscricao: mudar o valor do
            // evento depois nao altera o que a pessoa deve pagar.
            $table->bigInteger('valor_centavos');
            $table->string('versao_termos', 40);
            $table->timestampTz('termos_aceitos_em');
            $table->uuid('chave_idempotencia');
            $table->timestampTz('prazo_pagamento')->nullable();
            $table->timestampTz('confirmada_em')->nullable();
            $table->timestampTz('expirada_em')->nullable();
            $table->timestampTz('cancelada_em')->nullable();
            $table->string('motivo_cancelamento', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['evento_id', 'chave_idempotencia']);
            $table->index(['situacao', 'prazo_pagamento']);
            $table->index(['evento_id', 'situacao']);
        });

        // Uma inscricao ATIVA por e-mail por evento. A unicidade e parcial de
        // proposito: depois que a inscricao expira ou e cancelada, a pessoa
        // pode tentar de novo com o mesmo e-mail.
        DB::statement("CREATE UNIQUE INDEX inscricoes_email_ativa_unique
            ON inscricoes (evento_id, lower(email))
            WHERE situacao IN ('aguardando_pagamento', 'confirmada')");

        DB::statement("CREATE UNIQUE INDEX inscricoes_documento_ativa_unique
            ON inscricoes (evento_id, documento_hash)
            WHERE situacao IN ('aguardando_pagamento', 'confirmada')");

        DB::statement('ALTER TABLE inscricoes ADD CONSTRAINT inscricoes_valor_check
            CHECK (valor_centavos >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inscricoes');
    }
};
