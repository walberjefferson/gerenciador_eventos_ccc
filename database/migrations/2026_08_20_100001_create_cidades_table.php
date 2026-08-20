<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cidades', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->char('uf', 2);
            $table->boolean('ativo')->default(true);
            $table->timestampsTz();

            $table->unique(['nome', 'uf']);
            $table->index(['ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cidades');
    }
};
