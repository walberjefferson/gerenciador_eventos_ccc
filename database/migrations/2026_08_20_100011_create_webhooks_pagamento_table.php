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
        Schema::create('webhooks_pagamento', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40);
            // Identificador do aviso no provedor. E ele que impede processar o
            // mesmo aviso duas vezes: provedores reenviam o aviso quando nao
            // recebem a confirmacao a tempo.
            $table->string('id_evento_externo', 190)->nullable();
            $table->string('tipo_evento', 80)->nullable();
            $table->jsonb('payload');
            $table->boolean('assinatura_valida')->default(false);
            $table->timestampTz('recebido_em');
            $table->timestampTz('processado_em')->nullable();
            $table->string('situacao', 20)->default('recebido');
            $table->text('erro')->nullable();
            $table->timestampsTz();

            $table->index(['situacao', 'recebido_em']);
        });

        DB::statement('CREATE UNIQUE INDEX webhooks_pagamento_evento_externo_unique
            ON webhooks_pagamento (gateway, id_evento_externo)
            WHERE id_evento_externo IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks_pagamento');
    }
};
