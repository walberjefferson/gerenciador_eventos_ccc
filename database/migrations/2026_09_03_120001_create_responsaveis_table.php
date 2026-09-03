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
        Schema::create('responsaveis', function (Blueprint $table) {
            $table->id();

            // O nome do titular da chave — e o que aparece no aplicativo de
            // quem paga. Nao e o nome da conta do painel: a conta pode nem
            // existir, e quando existe ela guarda o nome de quem ENTRA no
            // sistema, que nem sempre e o nome que o banco reconhece.
            $table->string('nome', 120);

            // A chave Pix EM CLARO, e de proposito (RN-S3).
            //
            // A chave da credencial do provedor e cifrada porque e segredo de
            // instituicao financeira. Esta e o oposto: ela existe PARA SER
            // MOSTRADA a todo participante do setor, na tela de pagamento.
            // Cifrar o valor que a propria aplicacao publica na pagina
            // seguinte seria teatro — protegeria contra um invasor com acesso
            // ao banco e contra nenhum outro, enquanto tornaria impossivel
            // procurar, conferir e exportar a chave. O que protege esta coluna
            // e o escopo de quem a le, nunca a criptografia.
            $table->string('chave_pix', 140);

            // O contato para a duvida que aparece com o dinheiro ja fora da
            // conta. Opcional: ele ajuda, mas nao impede ninguem de pagar —
            // por isso nao entra em "esta apto a receber".
            $table->string('telefone', 40)->nullable();

            // A conta do painel, QUANDO ELA EXISTE (RN-R1).
            //
            // Nulo quer dizer "recebe, mas nao confere": e o caso real do
            // tesoureiro que nao usa o sistema. "nullOnDelete" e nao
            // "restrict" porque apagar a conta do painel nao pode apagar a
            // pessoa para quem o dinheiro ja foi — o cadastro sobrevive sem o
            // login, exatamente como o de quem nunca teve um.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('ativo')->default(true);
            $table->timestampsTz();

            $table->index(['ativo']);
        });

        // Uma conta do painel corresponde a UM cadastro de responsavel.
        //
        // Duas fichas para o mesmo login fariam o escopo de conferencia
        // (users -> responsaveis -> responsaveis_setores -> cidades) responder
        // duas coisas diferentes para a mesma pessoa. O unico e parcial porque
        // responsavel sem conta e o caso normal, e varios nulos convivem.
        DB::statement('CREATE UNIQUE INDEX responsaveis_user_id_unique
            ON responsaveis (user_id)
            WHERE user_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('responsaveis');
    }
};
