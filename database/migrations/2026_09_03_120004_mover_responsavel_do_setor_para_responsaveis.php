<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tira do setor os quatro campos que descrevem uma PESSOA e os leva para o
 * cadastro proprio de responsaveis.
 *
 * `cidades` guardava `responsavel_id`, `chave_pix`, `titular_chave_pix` e
 * `telefone_responsavel`. Eram 1:1 e misturavam duas coisas: quem responde e
 * para onde o dinheiro vai. Com mais de um responsavel por setor (RN-R2) esses
 * campos deixam de fazer sentido no setor — eles descrevem gente, nao lugar.
 *
 * **O backfill acontece ANTES do drop, e o drop so acontece depois.** Nao ha
 * volta depois dele: cada setor que hoje tem chave vira um registro em
 * "responsaveis", com o vinculo correspondente, e ninguem precisa recadastrar
 * o que ja cadastrou.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->moverParaResponsaveis();

        // So agora, com tudo copiado, as colunas saem.
        Schema::table('cidades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsavel_id');
            $table->dropColumn(['chave_pix', 'titular_chave_pix', 'telefone_responsavel']);
        });
    }

    /**
     * Copia cada setor que sabia receber para uma ficha de responsavel.
     *
     * Setor sem chave nao vira nada: nao ha pessoa a cadastrar, so um setor
     * que ainda nao esta pronto — e ele continua nao estando (RN-R3).
     */
    private function moverParaResponsaveis(): void
    {
        $agora = now();

        // As fichas ja criadas nesta passada, para que dois setores atendidos
        // pela MESMA pessoa nao virem duas fichas dela. A impressao digital e
        // o conjunto inteiro (conta, nome, chave, telefone): se algum deles
        // difere, sao pessoas diferentes do ponto de vista de quem recebe.
        /** @var array<string, int> $fichas */
        $fichas = [];

        // As contas do painel ja usadas, por causa do unico parcial em
        // "responsaveis.user_id".
        /** @var array<int, true> $contasUsadas */
        $contasUsadas = [];

        $setores = DB::table('cidades')
            ->select(['id', 'nome', 'responsavel_id', 'chave_pix', 'titular_chave_pix', 'telefone_responsavel'])
            ->whereNotNull('chave_pix')
            ->where('chave_pix', '<>', '')
            ->orderBy('id')
            ->get();

        foreach ($setores as $setor) {
            $chave = trim((string) $setor->chave_pix);

            if ($chave === '') {
                continue;
            }

            // Setor com chave mas sem titular cadastrado usa o NOME DO SETOR
            // como nome do responsavel. E menos exato do que o nome de uma
            // pessoa e ainda assim diz alguma coisa a quem paga — e a
            // alternativa seria descartar a chave, que e o pior desfecho
            // possivel numa migracao de recebimento.
            $nome = trim((string) ($setor->titular_chave_pix ?? ''));
            $nome = $nome === '' ? (string) $setor->nome : $nome;
            $nome = mb_substr($nome, 0, 120);

            $telefone = trim((string) ($setor->telefone_responsavel ?? ''));
            $telefone = $telefone === '' ? null : $telefone;

            $conta = $setor->responsavel_id === null ? null : (int) $setor->responsavel_id;

            $impressao = implode('|', [(string) $conta, $nome, $chave, (string) $telefone]);

            if (! array_key_exists($impressao, $fichas)) {
                // A mesma conta do painel em dois setores COM CHAVES
                // DIFERENTES nao cabe numa ficha so: o unico parcial em
                // "user_id" recusaria a segunda. Nesse caso a segunda nasce
                // sem conta — a chave e preservada, que e o que nao pode se
                // perder, e o vinculo da conta com o setor pode ser refeito
                // na tela quando alguem notar.
                $contaDaFicha = ($conta !== null && ! isset($contasUsadas[$conta])) ? $conta : null;

                $fichas[$impressao] = (int) DB::table('responsaveis')->insertGetId([
                    'nome' => $nome,
                    'chave_pix' => mb_substr($chave, 0, 140),
                    'telefone' => $telefone === null ? null : mb_substr($telefone, 0, 40),
                    'user_id' => $contaDaFicha,
                    'ativo' => true,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);

                if ($contaDaFicha !== null) {
                    $contasUsadas[$contaDaFicha] = true;
                }
            }

            DB::table('responsaveis_setores')->insertOrIgnore([
                'cidade_id' => (int) $setor->id,
                'responsavel_id' => $fichas[$impressao],
            ]);
        }
    }

    /**
     * O caminho de volta devolve as colunas e o que der para devolver.
     *
     * Um setor que passou a ter dois responsaveis nao cabe em quatro colunas:
     * a volta traz o primeiro deles, que e o mais proximo do que existia antes.
     * Ela serve para desfazer a migracao logo depois de roda-la, nao para
     * reconstruir meses de cadastro.
     */
    public function down(): void
    {
        Schema::table('cidades', function (Blueprint $table) {
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('chave_pix', 140)->nullable();
            $table->string('titular_chave_pix', 120)->nullable();
            $table->string('telefone_responsavel', 40)->nullable();
        });

        $vinculos = DB::table('responsaveis_setores')
            ->join('responsaveis', 'responsaveis.id', '=', 'responsaveis_setores.responsavel_id')
            ->select([
                'responsaveis_setores.cidade_id',
                'responsaveis.nome',
                'responsaveis.chave_pix',
                'responsaveis.telefone',
                'responsaveis.user_id',
            ])
            // Ordem decrescente porque `keyBy` mantem o ULTIMO de cada
            // cidade: assim o que sobra e o responsavel de menor id, que e o
            // primeiro vinculado.
            ->orderByDesc('responsaveis.id')
            ->get()
            ->keyBy('cidade_id');

        foreach ($vinculos as $cidadeId => $vinculo) {
            DB::table('cidades')->where('id', $cidadeId)->update([
                'responsavel_id' => $vinculo->user_id,
                'chave_pix' => $vinculo->chave_pix,
                'titular_chave_pix' => $vinculo->nome,
                'telefone_responsavel' => $vinculo->telefone,
            ]);
        }
    }
};
