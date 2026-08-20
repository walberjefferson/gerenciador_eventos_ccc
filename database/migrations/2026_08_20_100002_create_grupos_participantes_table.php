<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cidade_id')->constrained('cidades')->restrictOnDelete();
            $table->string('nome', 120);
            $table->boolean('ativo')->default(true);
            $table->timestampsTz();

            $table->unique(['cidade_id', 'nome']);
            $table->index(['cidade_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos_participantes');
    }
};
