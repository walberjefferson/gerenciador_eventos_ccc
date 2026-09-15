<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O sexo de quem se inscreveu.
     *
     * A COLUNA E ANULAVEL, e o formulario e obrigatorio. Nao sao a mesma
     * pergunta: o formulario fala com quem esta se inscrevendo agora e pode
     * exigir; a coluna precisa acomodar as inscricoes ja gravadas, para as
     * quais ninguem nunca perguntou nada. Inventar "masculino" como padrao para
     * elas produziria uma contagem falsa que ninguem conseguiria distinguir de
     * dado real depois — por isso nem NOT NULL, nem default.
     *
     * O CHECK protege o que o enum do PHP nao alcanca: tinker, seeder e
     * correcao manual em producao. E o mesmo desenho de inscricoes_valor_check,
     * que ja mora nesta tabela.
     *
     * Sem `after()`: o PostgreSQL nao posiciona coluna e o Laravel ignora a
     * instrucao. Ela so sugeriria uma ordem que o banco nao tem.
     *
     * Sem indice novo: o filtro de sexo nunca chega sozinho — vem junto do
     * evento ou da situacao, que ja tem indice —, e uma coluna de duas
     * cardinalidades nao estreita quase nada. Indice que nao e usado custa
     * escrita em toda inscricao criada.
     */
    public function up(): void
    {
        Schema::table('inscricoes', function (Blueprint $table) {
            $table->string('sexo', 20)->nullable();
        });

        DB::statement("ALTER TABLE inscricoes ADD CONSTRAINT inscricoes_sexo_check
            CHECK (sexo IS NULL OR sexo IN ('masculino', 'feminino'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inscricoes DROP CONSTRAINT IF EXISTS inscricoes_sexo_check');

        Schema::table('inscricoes', function (Blueprint $table) {
            $table->dropColumn('sexo');
        });
    }
};
