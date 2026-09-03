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
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            // "restrict" e nao "cascade": lote tem dinheiro combinado dentro
            // dele, e apagar um evento nao pode levar junto o degrau de preco
            // pelo qual alguem entrou.
            $table->foreignId('evento_id')->constrained('eventos')->restrictOnDelete();
            $table->string('nome', 80);
            $table->integer('posicao');
            $table->bigInteger('valor_centavos');
            // Os dois limites sao opcionais, um de cada vez: um lote pode
            // encerrar por data, por vaga, ou pelo que vier primeiro.
            $table->timestampTz('disponivel_ate')->nullable();
            $table->integer('quantidade')->nullable();
            // Contador mantido por comando atomico (ver
            // App\Actions\Inscricoes\ReservarVagas). Nunca sobe nem desce com
            // leitura seguida de gravacao — e nunca desce.
            $table->integer('vagas_ocupadas')->default(0);
            $table->timestampsTz();

            // A posicao ordena a sucessao dos lotes e nao se repete dentro do
            // evento, no mesmo molde de dias_evento. Este indice unico ja e o
            // indice de leitura por (evento_id, posicao): nao ha um segundo.
            $table->unique(['evento_id', 'posicao']);
        });

        // Ultima linha de defesa contra vender mais vagas do que o lote tem: se
        // algum caminho de codigo errar a contabilidade, o banco recusa.
        DB::statement('ALTER TABLE lotes ADD CONSTRAINT lotes_quantidade_check
            CHECK (quantidade IS NULL OR (quantidade > 0 AND vagas_ocupadas <= quantidade))');

        DB::statement('ALTER TABLE lotes ADD CONSTRAINT lotes_vagas_nao_negativas_check
            CHECK (vagas_ocupadas >= 0)');

        DB::statement('ALTER TABLE lotes ADD CONSTRAINT lotes_valor_check
            CHECK (valor_centavos >= 0)');

        // Todo lote encerra (RN-L1). Um lote sem data e sem quantidade nunca
        // acabaria — e um lote que nao acaba nao e um lote: e o preco do
        // evento, que ja mora em eventos.valor_centavos.
        DB::statement('ALTER TABLE lotes ADD CONSTRAINT lotes_tem_limite_check
            CHECK (disponivel_ate IS NOT NULL OR quantidade IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
