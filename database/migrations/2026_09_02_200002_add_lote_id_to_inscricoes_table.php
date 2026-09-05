<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De qual lote esta inscricao veio.
     *
     * Nulo quando o evento nao tem lote nenhum — e ai continua valendo o preco
     * do proprio evento, exatamente como antes.
     *
     * A coluna valor_centavos NAO muda de papel: ela e a fotografia do preco no
     * instante da inscricao e continua sendo a unica fonte de verdade do que
     * aquela pessoa deve pagar. "lote_id" responde outra pergunta — de qual
     * degrau ela veio — e serve a relatorio e conferencia, nunca a cobranca.
     */
    public function up(): void
    {
        Schema::table('inscricoes', function (Blueprint $table) {
            $table->foreignId('lote_id')
                ->nullable()
                ->after('grupo_participante_id')
                ->constrained('lotes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inscricoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_id');
        });
    }
};
