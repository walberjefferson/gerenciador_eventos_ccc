<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * As credenciais do provedor de pagamento, cadastradas pela tela.
 *
 * Uma linha por ambiente (homologacao e producao), com os cinco campos
 * sigilosos cifrados pela aplicacao antes de chegarem aqui. O banco nao sabe
 * ler nenhum deles: quem abrir a tabela por fora ve texto embaralhado.
 *
 * Por isso todos os campos sigilosos sao "text" e nao "string": o texto
 * cifrado e bem maior que o valor original, e o conteudo do certificado nao
 * tem tamanho previsivel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciais_pagamento', function (Blueprint $table) {
            $table->id();

            // Qual provedor. Hoje so existe "efi"; a coluna existe para o dia
            // em que houver outro, e e ela que da sentido as restricoes
            // abaixo — a unicidade e o "um ativo so" valem POR provedor.
            $table->string('gateway', 40);

            // "homologacao" ou "producao". O CHECK vem depois, em SQL: o
            // Blueprint nao tem verbo para restricao de valor.
            $table->string('ambiente', 20);

            // Os cinco campos sigilosos. Cifrados em repouso pelo mesmo
            // mecanismo que ja cifra o CPF do participante (D-08): a chave da
            // aplicacao, que vive fora do banco. Backup de banco vazado,
            // sozinho, nao entrega credencial de instituicao financeira.
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();

            // O CONTEUDO do certificado, nao o caminho. Guardar o caminho
            // exigiria que o arquivo sobrevivesse a redeploy e a container
            // efemero, e ele nao sobrevive (DA-25). O arquivo em disco vira
            // cache: pode sumir a qualquer momento e e reescrito no uso.
            $table->text('certificado')->nullable();

            // Nao sigilosos: existem so para a tela dizer qual certificado
            // esta guardado e ate quando ele vale.
            $table->string('certificado_nome', 190)->nullable();
            $table->timestampTz('certificado_expira_em')->nullable();

            $table->text('chave_pix')->nullable();
            $table->text('webhook_hmac')->nullable();

            // Qual ambiente o sistema usa de verdade. Ver o indice parcial
            // logo abaixo: quem impede o segundo ativo e o banco.
            $table->boolean('ativo')->default(false);

            // Quem mexeu por ultimo. "restrict" como as demais: apagar a conta
            // de alguem nao pode levar junto o registro de quem configurou o
            // recebimento do evento.
            $table->foreignId('atualizado_por_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestampsTz();

            // Nao existem dois cadastros do mesmo ambiente do mesmo provedor.
            $table->unique(['gateway', 'ambiente']);
        });

        // O CHECK de ambiente. Coluna livre aceitaria "produção" com acento,
        // "PROD" ou um espaco a mais — e o sistema cairia para homologacao
        // silenciosamente, cobrando de mentira quem devia pagar de verdade.
        DB::statement(
            "ALTER TABLE credenciais_pagamento
             ADD CONSTRAINT credenciais_pagamento_ambiente_check
             CHECK (ambiente IN ('homologacao', 'producao'))"
        );

        // **Um ativo por provedor, garantido pelo banco.**
        //
        // Mesma licao da D-66: verificacao em PHP perde para duas requisicoes
        // simultaneas, e o estrago aqui seria cobrar em producao com
        // credencial de homologacao (ou o contrario). O indice unico parcial
        // so enxerga as linhas com ativo = true, entao a segunda ativacao
        // esbarra no banco antes de existir.
        DB::statement(
            'CREATE UNIQUE INDEX credenciais_pagamento_um_ativo_por_gateway
             ON credenciais_pagamento (gateway)
             WHERE ativo = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciais_pagamento');
    }
};
