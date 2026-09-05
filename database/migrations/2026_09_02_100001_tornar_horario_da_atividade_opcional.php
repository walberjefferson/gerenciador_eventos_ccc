<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O horário da atividade passa a ser opcional.
 *
 * Nem toda programação tem hora marcada. Um evento de atividade única — uma
 * caminhada, um mutirão, um retiro — acontece "no sábado", e obrigar quem
 * cadastra a inventar 08:00 às 17:00 é pedir um dado que ninguém tem. Quando o
 * horário não existe, a data da atividade passa a ser a do dia a que ela
 * pertence, que o sistema já conhece.
 *
 * O horário é opcional EM PAR: ou os dois campos estão preenchidos, ou os dois
 * estão vazios. Só a hora de início não descreve nada e, pior, deixaria o
 * banco sem como conferir a sobreposição — por isso o CHECK abaixo recusa a
 * metade preenchida, e não só a aplicação.
 *
 * O índice ['comeca_em', 'termina_em'] continua onde estava: o PostgreSQL
 * indexa nulos sem reclamar, e as consultas por período seguem as mesmas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE atividades DROP CONSTRAINT atividades_horario_check');

        DB::statement('ALTER TABLE atividades ALTER COLUMN comeca_em DROP NOT NULL');
        DB::statement('ALTER TABLE atividades ALTER COLUMN termina_em DROP NOT NULL');

        DB::statement('ALTER TABLE atividades ADD CONSTRAINT atividades_horario_check
            CHECK (
                (comeca_em IS NULL AND termina_em IS NULL)
                OR (comeca_em IS NOT NULL AND termina_em IS NOT NULL AND termina_em > comeca_em)
            )');
    }

    /**
     * Voltar atrás só é possível enquanto nenhuma atividade tiver sido gravada
     * sem horário. Se alguma já existir, reverter exigiria inventar uma hora de
     * início — e programação inventada é pior do que migração recusada.
     */
    public function down(): void
    {
        $semHorario = DB::table('atividades')->whereNull('comeca_em')->count();

        if ($semHorario > 0) {
            throw new RuntimeException(
                "Não é possível reverter: {$semHorario} atividade(s) estão gravadas sem horário. "
                .'Defina hora de início e de término para cada uma delas antes de reverter esta migração.'
            );
        }

        DB::statement('ALTER TABLE atividades DROP CONSTRAINT atividades_horario_check');

        DB::statement('ALTER TABLE atividades ALTER COLUMN comeca_em SET NOT NULL');
        DB::statement('ALTER TABLE atividades ALTER COLUMN termina_em SET NOT NULL');

        DB::statement('ALTER TABLE atividades ADD CONSTRAINT atividades_horario_check
            CHECK (termina_em > comeca_em)');
    }
};
