<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A coluna que liga o aviso do provedor a cobranca de que ele fala.
 *
 * O identificador da cobranca (o "txid", na Efi) sempre esteve no aviso, mas
 * so dentro do payload, em `pix[0].txid`. Enquanto ele morava so ali, ligar
 * aviso e cobranca dependia de alguem abrir o JSON e ler com o olho — e
 * conciliar dinheiro e exatamente o trabalho de cruzar essas duas pontas.
 * Coluna propria e o que torna esse cruzamento uma consulta.
 *
 * O indice e COMUM, e nao unico, de proposito: o mesmo txid pode gerar mais de
 * um aviso legitimo. A Efi reenvia, e uma cobranca pode receber avisos de
 * transferencias diferentes (identificadores "fim a fim" distintos). Unicidade
 * aqui recusaria aviso de verdade — e aviso recusado e dinheiro que entrou sem
 * ninguem ficar sabendo. Quem impede processar o mesmo aviso duas vezes
 * continua sendo `webhooks_pagamento_evento_externo_unique`.
 *
 * O preenchimento dos avisos ja gravados le o mesmo lugar do payload. Aviso
 * cujo payload nao tenha esse formato — o do provedor simulado, por exemplo —
 * simplesmente fica nulo: a migration nunca pode cair por causa do formato de
 * um aviso antigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks_pagamento', function (Blueprint $tabela): void {
            $tabela->string('id_externo', 190)->nullable()->after('id_evento_externo');
            $tabela->index(['gateway', 'id_externo']);
        });

        // O `->>` devolve NULL quando o caminho nao existe ou quando o pedaco
        // do meio nao e do tipo esperado, entao formato estranho vira nulo em
        // vez de erro. O limite de tamanho e a segunda tranca: um txid maior
        // que a coluna estouraria a migration no lugar de ficar de fora.
        DB::statement("UPDATE webhooks_pagamento
            SET id_externo = payload->'pix'->0->>'txid'
            WHERE payload->'pix'->0->>'txid' IS NOT NULL
              AND length(payload->'pix'->0->>'txid') <= 190");
    }

    public function down(): void
    {
        Schema::table('webhooks_pagamento', function (Blueprint $tabela): void {
            $tabela->dropIndex(['gateway', 'id_externo']);
            $tabela->dropColumn('id_externo');
        });
    }
};
