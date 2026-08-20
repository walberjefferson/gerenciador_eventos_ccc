<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dias_evento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->date('data');
            $table->smallInteger('posicao')->default(1);
            $table->boolean('ativo')->default(true);
            $table->timestampsTz();

            $table->unique(['evento_id', 'posicao']);
            $table->index(['evento_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dias_evento');
    }
};
