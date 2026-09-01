<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingressos', function (Blueprint $table) {
            $table->id();

            // Uma inscricao, um ingresso — e quem garante isso e o banco, nao
            // o codigo. Aviso repetido do provedor chega a chamar a emissao
            // duas vezes; a segunda esbarra aqui e a Action devolve o que ja
            // existia. Verificacao em PHP nao segura duas requisicoes
            // simultaneas.
            $table->foreignId('inscricao_id')
                ->unique()
                ->constrained('inscricoes')
                ->cascadeOnDelete();

            // O que vai dentro do QR Code e o que se digita na portaria.
            // Doze caracteres em base32 de Crockford (~60 bits), sorteados —
            // NUNCA derivados do codigo_publico, que ja viajou em e-mails
            // antigos e em URLs de acompanhamento.
            $table->string('codigo', 16)->unique();

            $table->timestampTz('emitido_em');

            // Preenchidos juntos, na entrada aceita. Vazios de novo quando
            // alguem com permissao desfaz um engano do portao.
            $table->timestampTz('usado_em')->nullable();
            $table->foreignId('usado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            // A contagem de presentes de um evento passa por aqui: quantos
            // ingressos com "usado_em" preenchido existem entre as inscricoes
            // daquele evento.
            $table->index(['usado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingressos');
    }
};
