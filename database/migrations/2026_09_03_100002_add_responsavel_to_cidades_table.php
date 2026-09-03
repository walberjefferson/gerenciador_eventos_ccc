<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cidades', function (Blueprint $table) {
            // Quem responde pelo setor: a pessoa que recebe o Pix e confere os
            // comprovantes de quem se inscreveu por ali.
            //
            // "nullOnDelete" e nao "restrict": se a conta administrativa dessa
            // pessoa for apagada um dia, o setor continua existindo — com todos
            // os grupos e inscricoes pendurados nele — apenas sem responsavel.
            // O contrario faria o setor virar refem de um usuario.
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();

            // A chave Pix do responsavel, EM CLARO e de proposito.
            //
            // A chave da credencial do provedor e cifrada porque e segredo de
            // instituicao financeira. Esta e o oposto: ela existe para ser
            // MOSTRADA a todo participante daquele setor, na tela de pagamento.
            // Cifrar o que a propria tela publica seria teatro. O que protege
            // esta coluna e o escopo de quem a le, nunca a criptografia.
            $table->string('chave_pix', 140)->nullable();

            // O nome que vai aparecer no aplicativo do banco de quem paga. Sem
            // ele a pessoa transfere para um nome que nao reconhece e desiste.
            $table->string('titular_chave_pix', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cidades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsavel_id');
            $table->dropColumn(['chave_pix', 'titular_chave_pix']);
        });
    }
};
