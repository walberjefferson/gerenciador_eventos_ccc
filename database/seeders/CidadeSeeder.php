<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cidade;
use App\Models\GrupoParticipante;
use Illuminate\Database\Seeder;

/**
 * O catalogo real da comunidade: os cinco setores e os grupos de cada um.
 *
 * A tabela continua se chamando `cidades` e a coluna, `cidade_id`: o renome
 * para "setor" acontece na tela e nas rotas do administrativo, nunca no banco.
 * Aqui, portanto, "cidade" e o setor e "grupo participante" e o grupo.
 *
 * A `uf` e obrigatoria e entra na chave unica (`nome`, `uf`), entao todos os
 * setores guardam 'AL'. Ela nao aparece mais no rotulo publico: a UF existia
 * para desambiguar cidades homonimas de estados diferentes, e cinco setores da
 * mesma regiao nao tem essa ambiguidade.
 *
 * Pode ser executado quantas vezes for preciso: nada e duplicado. O
 * `entrypoint.sh` o roda a cada subida do container.
 *
 * ELE SO ACRESCENTA. Nenhum registro e apagado daqui: catalogo antigo pode ter
 * inscricao apontando para ele, e apagar dado de gente nao cabe num seeder.
 */
class CidadeSeeder extends Seeder
{
    /**
     * @var array<string, array{uf: string, grupos: array<int, string>}>
     */
    private const CATALOGO = [
        'Setor Batalha' => ['uf' => 'AL', 'grupos' => [
            'Batalha (Povoados)',
            'Batalha (Sede)',
            'Belo Monte',
            'Belo Monte (Povoados)',
            'Jacaré dos Homens',
            'Jaramataia',
            'Monteirópolis',
            'Paus Preto',
        ]],
        'Setor Delmiro' => ['uf' => 'AL', 'grupos' => [
            'Barragem Leste',
            'Mata Grande',
        ]],
        "Setor Olho d'água das Flores" => ['uf' => 'AL', 'grupos' => [
            'Carneiros',
            "Olho d'água das Flores",
            'Palestina',
            'Pão de Açúcar',
            'Senador Rui Palmeira',
        ]],
        'Setor Palmeira' => ['uf' => 'AL', 'grupos' => [
            'Divina Pastora',
            'Mar Vermelho',
            'Palmeira (Nossa Senhora das Graças)',
            'Palmeira (São Vicente)',
            'Paulo Jacinto',
            'Quebrangulo',
        ]],
        'Setor Santana' => ['uf' => 'AL', 'grupos' => [
            'Dois Riachos',
            'Maravilha',
            'Olivença',
            'Poço das Trincheiras (Quandú)',
            'Poço das Trincheiras (Sede)',
            'Santana do Ipanema (Camoxinga)',
            "Santana do Ipanema (Samambaia e Pedra d'água)",
            'Santana do Ipanema (Sede)',
        ]],
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
