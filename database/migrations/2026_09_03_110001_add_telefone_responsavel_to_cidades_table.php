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
            // O telefone de quem responde pelo setor.
            //
            // Quem paga por Pix direto para a conta de uma pessoa precisa saber
            // com quem falar quando alguma coisa nao fecha: o valor saiu errado,
            // o comprovante nao foi aceito, o prazo esta acabando. Sem esse
            // numero na tela, a duvida vira desistencia — e o dinheiro ja saiu.
            //
            // Fica no SETOR, e nao na conta do responsavel, por dois motivos:
            // "users" nao guarda telefone, e o contato e do setor para aquele
            // assunto. Trocar o responsavel nao invalida o numero de plantao.
            //
            // Quarenta caracteres e o mesmo tamanho de eventos.contato_telefone
            // e de inscricoes.telefone: o campo guarda o texto pontuado, como
            // a pessoa digitou.
            $table->string('telefone_responsavel', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cidades', function (Blueprint $table) {
            $table->dropColumn('telefone_responsavel');
        });
    }
};
