<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cidade;
use App\Models\GrupoParticipante;
use Illuminate\Database\Seeder;

/**
 * Catalogo inicial de cidades e de grupos de participantes.
 *
 * Pode ser executado quantas vezes for preciso: nada e duplicado.
 */
class CidadeSeeder extends Seeder
{
    /**
     * @var array<string, array{uf: string, grupos: array<int, string>}>
     */
    private const CATALOGO = [
        'São Paulo' => ['uf' => 'SP', 'grupos' => ['Zona Norte', 'Zona Sul', 'Centro']],
        'Campinas' => ['uf' => 'SP', 'grupos' => ['Barão Geraldo', 'Centro']],
        'Ribeirão Preto' => ['uf' => 'SP', 'grupos' => ['Centro', 'Vila Tibério']],
        'Belo Horizonte' => ['uf' => 'MG', 'grupos' => ['Pampulha', 'Savassi']],
        'Curitiba' => ['uf' => 'PR', 'grupos' => ['Batel', 'Boa Vista']],
    ];

    public function run(): void
    {
        foreach (self::CATALOGO as $nome => $dados) {
            $cidade = Cidade::query()->firstOrCreate(
                ['nome' => $nome, 'uf' => $dados['uf']],
                ['ativo' => true],
            );

            foreach ($dados['grupos'] as $grupo) {
                GrupoParticipante::query()->firstOrCreate(
                    ['cidade_id' => $cidade->id, 'nome' => $grupo],
                    ['ativo' => true],
                );
            }
        }
    }
}
