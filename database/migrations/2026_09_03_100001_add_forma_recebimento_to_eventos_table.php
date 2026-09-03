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
        Schema::table('eventos', function (Blueprint $table) {
            // Por onde o dinheiro deste evento entra: "gateway" e o caminho de
            // sempre — o provedor emite a cobranca e reconhece o pagamento — e
            // "setor" e o caminho em que a pessoa paga direto na chave Pix do
            // responsavel pelo setor dela e alguem confere o comprovante.
            //
            // O padrao e "gateway" de proposito: todo evento que ja existe
            // continua se comportando exatamente como se comportava (RN-S12).
            $table->string('forma_recebimento', 20)->default('gateway');
        });

        // Ultima linha de defesa: o Enum FormaRecebimento diz a mesma coisa em
        // PHP, mas quem escreve direto no banco tambem precisa ser recusado.
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_forma_recebimento_check
            CHECK (forma_recebimento IN ('gateway', 'setor'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE eventos DROP CONSTRAINT IF EXISTS eventos_forma_recebimento_check');

        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn('forma_recebimento');
        });
    }
};
