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
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->ulid('codigo_publico')->unique();
            $table->foreignId('inscricao_id')->constrained('inscricoes')->restrictOnDelete();
            // Quem emitiu a cobranca (o provedor simulado ou, no futuro, uma
            // instituicao real). Fica gravado para que trocar de fornecedor nao
            // confunda a leitura do historico.
            $table->string('gateway', 40);
            // Identificador da cobranca no provedor. Fica nulo enquanto a
            // cobranca ainda nao foi emitida.
            $table->string('id_externo', 190)->nullable();
            $table->string('metodo', 30);
            // Dinheiro sempre em centavos e sempre inteiro: numero quebrado
            // acumula erro de arredondamento.
            $table->bigInteger('valor_centavos');
            $table->string('situacao', 30)->default('pendente');
            // O texto do "Pix copia e cola". Nao e segredo: e o mesmo que o
            // participante ve na tela.
            $table->text('pix_copia_e_cola')->nullable();
            $table->timestampTz('expira_em')->nullable();
            $table->timestampTz('pago_em')->nullable();
            $table->timestampTz('cancelado_em')->nullable();
            $table->timestampTz('estornado_em')->nullable();
            $table->bigInteger('valor_estornado_centavos')->nullable();
            $table->jsonb('metadados')->default('{}');
            $table->timestampsTz();

            $table->index(['inscricao_id', 'situacao']);
            $table->index(['situacao', 'expira_em']);
        });

        // O mesmo identificador do provedor nunca pode virar dois pagamentos.
        // A unicidade e parcial porque a cobranca so ganha identificador depois
        // de emitida.
        DB::statement('CREATE UNIQUE INDEX pagamentos_gateway_id_externo_unique
            ON pagamentos (gateway, id_externo)
            WHERE id_externo IS NOT NULL');

        DB::statement('ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_valor_check
            CHECK (valor_centavos >= 0)');

        DB::statement('ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_valor_estornado_check
            CHECK (valor_estornado_centavos IS NULL
                OR (valor_estornado_centavos >= 0 AND valor_estornado_centavos <= valor_centavos))');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');
    }
};
