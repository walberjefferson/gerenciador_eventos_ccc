<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            // Quem foi sorteado para receber ESTA cobranca (RN-R4).
            //
            // **A coluna mora no pagamento, e nao na inscricao.** E a
            // consequencia direta de sortear de novo a cada cobranca emitida
            // (RN-R5): o escolhido pertence aquela cobranca, nao a pessoa. Uma
            // cobranca vencida guarda para sempre quem era o responsavel dela,
            // e a cobranca nova guarda o seu — o historico conta a verdade em
            // vez de ser reescrito. Se um dia a decisao virar "fica preso a
            // inscricao", esta coluna migra para "inscricoes".
            //
            // Nula em toda cobranca do modo gateway, que nao sorteia nada, e
            // nas que ja existiam antes desta migracao.
            //
            // "restrictOnDelete": responsavel que ja recebeu nao se apaga
            // (RN-R9). O banco recusa, e a tela oferece o caminho certo —
            // desativar, que tira do sorteio sem tocar em cobranca emitida.
            $table->foreignId('responsavel_id')->nullable()->constrained('responsaveis')->restrictOnDelete();

            // A consulta do sorteio: quantas cobrancas de um evento apontam
            // para cada candidato.
            $table->index(['responsavel_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            $table->dropIndex(['responsavel_id']);
            $table->dropConstrainedForeignId('responsavel_id');
        });
    }
};
