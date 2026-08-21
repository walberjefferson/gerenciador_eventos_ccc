<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_auditoria', function (Blueprint $table) {
            $table->id();

            // Quem fez. Nulo de proposito: rotina agendada e comando de
            // terminal tambem deixam rastro, e nesses casos nao ha gente por
            // tras. "restrict" porque apagar a conta de alguem nao pode levar
            // junto a prova do que essa pessoa fez.
            $table->foreignId('usuario_id')->nullable()->constrained('users')->restrictOnDelete();

            // O verbo do que aconteceu (App\Enums\AcaoAuditada).
            $table->string('acao', 60);

            // O que foi afetado: o nome curto da entidade ("inscricao",
            // "evento", "atividade") e o identificador dela. Nao e uma chave
            // estrangeira: o registro precisa sobreviver mesmo se um dia a
            // linha original for removida — auditoria que some junto com o
            // que ela auditava nao serve de auditoria.
            $table->string('entidade', 60);
            $table->unsignedBigInteger('entidade_id')->nullable();

            // A justificativa escrita por quem agiu, quando a acao pede uma.
            $table->string('motivo', 500)->nullable();

            // O antes/depois DO QUE MUDOU, sem dado sensivel. Nunca CPF, nunca
            // hash de documento, nunca senha, token ou Pix completo: quando o
            // campo e sensivel, guardamos que ele mudou, e nao o conteudo.
            $table->jsonb('dados')->nullable();

            // De onde veio o pedido. Ajuda a distinguir "o organizador de
            // sempre" de "alguem usando a conta do organizador".
            $table->string('ip', 45)->nullable();
            $table->string('agente', 255)->nullable();

            // So "created_at". Nao existe "updated_at" porque o registro nao
            // se altera: a tabela e append-only, e o model recusa update e
            // delete (App\Models\LogAuditoria).
            $table->timestampTz('created_at')->useCurrent();

            // A tela de auditoria olha por periodo, por pessoa e por acao.
            // Sao os tres unicos caminhos de leitura que existem, e por isso
            // sao exatamente os tres indices que existem.
            $table->index(['created_at']);
            $table->index(['usuario_id', 'created_at']);
            $table->index(['acao', 'created_at']);
            // Para a pergunta "o que ja fizeram com esta inscricao?".
            $table->index(['entidade', 'entidade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_auditoria');
    }
};
