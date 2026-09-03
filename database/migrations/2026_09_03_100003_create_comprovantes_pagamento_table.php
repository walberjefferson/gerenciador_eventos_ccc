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
        Schema::create('comprovantes_pagamento', function (Blueprint $table) {
            $table->id();
            // "restrict" e nao "cascade": o comprovante e a prova de que alguem
            // disse ter pago. Apagar a inscricao nao pode levar a prova junto.
            $table->foreignId('inscricao_id')->constrained('inscricoes')->restrictOnDelete();
            // O caminho dentro do disco privado. Nunca sob storage/app/public:
            // comprovante tem nome, valor e conta de gente de verdade dentro.
            $table->string('caminho', 255);
            // Como a pessoa chamou o arquivo. Fica AQUI e nao no caminho: nome
            // vindo de terceiro nao entra em nome de arquivo do servidor.
            $table->string('nome_original', 180);
            // Conferido pelo conteudo do arquivo, nunca pela extensao.
            $table->string('mime', 80);
            $table->integer('tamanho_bytes');
            $table->string('situacao', 20);
            $table->timestampTz('enviado_em');
            // Quem conferiu. "nullOnDelete" pelo mesmo motivo do responsavel do
            // setor: a conferencia aconteceu, mesmo que a conta suma depois.
            $table->foreignId('conferido_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('conferido_em')->nullable();
            $table->string('motivo_recusa', 300)->nullable();
            $table->timestampsTz();

            // A fila do participante: "esta inscricao tem algo em aberto?".
            $table->index(['inscricao_id', 'situacao']);
            // A fila de quem confere: tudo o que esta esperando, na ordem de
            // chegada.
            $table->index(['situacao', 'enviado_em']);
        });

        // No maximo UM comprovante aguardando conferencia por inscricao.
        //
        // E a trava contra o duplo clique e contra a fila de comprovantes do
        // mesmo pagamento: mandar outro por cima do que ainda nao foi conferido
        // substitui o anterior (RN-S6). Depois de recusado, um envio novo cria
        // linha nova — e o indice parcial permite isso, porque so olha para as
        // linhas em "enviado".
        DB::statement("CREATE UNIQUE INDEX comprovantes_um_em_aberto_por_inscricao
            ON comprovantes_pagamento (inscricao_id)
            WHERE situacao = 'enviado'");

        // Recusar sem dizer por que deixaria o participante sem saber o que
        // corrigir — e ele nao tem a quem perguntar do outro lado da tela.
        DB::statement("ALTER TABLE comprovantes_pagamento ADD CONSTRAINT comprovantes_recusa_com_motivo_check
            CHECK (situacao <> 'recusado' OR motivo_recusa IS NOT NULL)");

        // Arquivo de zero byte nao e comprovante de nada.
        DB::statement('ALTER TABLE comprovantes_pagamento ADD CONSTRAINT comprovantes_tamanho_check
            CHECK (tamanho_bytes > 0)');

        DB::statement("ALTER TABLE comprovantes_pagamento ADD CONSTRAINT comprovantes_situacao_check
            CHECK (situacao IN ('enviado', 'aceito', 'recusado'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('comprovantes_pagamento');
    }
};
