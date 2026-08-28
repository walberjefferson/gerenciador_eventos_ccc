<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O que a pagina do evento responde alem da programacao.
 *
 * Sao duas listas que ate agora nao existiam em lugar nenhum e viviam no boca a
 * boca: o que a inscricao inclui (camiseta, almoco, seguro) e as perguntas que
 * a organizacao responde no WhatsApp toda semana. Sao justamente as duvidas que
 * fazem alguem adiar a inscricao — e adiar, aqui, e o mesmo que desistir.
 *
 * Ficam em `jsonb`, e nao em tabelas proprias, por uma razao: sao listas
 * ordenadas de texto que so existem para serem exibidas nesta tela. Nenhuma
 * outra parte do sistema as consulta, nenhuma regra depende delas, e nada as
 * relaciona com outra coisa. Uma tabela por lista traria duas migrations, dois
 * models, duas telas de ordenacao e nenhuma pergunta nova respondida.
 *
 * As duas aceitam nulo: os eventos ja cadastrados continuam validos, e a secao
 * correspondente simplesmente nao aparece enquanto ninguem preencher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $tabela): void {
            $tabela->jsonb('itens_incluidos')->nullable()->after('regulamento');
            $tabela->jsonb('perguntas_frequentes')->nullable()->after('itens_incluidos');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $tabela): void {
            $tabela->dropColumn(['itens_incluidos', 'perguntas_frequentes']);
        });
    }
};
