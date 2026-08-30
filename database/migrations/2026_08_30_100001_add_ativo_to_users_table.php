<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coluna que diz se a conta administrativa ainda entra.
 *
 * A alternativa seria excluir a conta, e ela foi descartada: a auditoria
 * guarda `usuario_id`, e apagar o usuario deixaria todo o historico dele
 * apontando para o vazio — justamente o rastro que a Fase 9 existiu para
 * tornar inapagavel.
 *
 * A coluna nasce com `default(true)` e NOT NULL de proposito. O
 * `docker/entrypoint.sh` aplica as migrations a cada subida do container: se o
 * padrao fosse falso ou nulo, a primeira subida depois deste commit trancaria
 * do lado de fora TODA conta que ja existia — inclusive a unica de
 * administrador —, e a saida seria rodar SQL no servidor.
 *
 * O indice existe porque a lista de usuarios filtra por situacao e a
 * conferencia do login le esta coluna em toda tentativa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabela): void {
            $tabela->boolean('ativo')->default(true)->after('password');
            $tabela->index('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabela): void {
            $tabela->dropIndex(['ativo']);
            $tabela->dropColumn('ativo');
        });
    }
};
