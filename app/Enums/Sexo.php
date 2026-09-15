<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * O sexo de quem se inscreve.
 *
 * Sao duas opcoes, por decisao do dono do produto, e elas nao decidem nada:
 * o campo nao entra em preco, vaga, conflito, idade minima nem escopo de setor
 * (RN-X4). E dado de cadastro — a organizacao precisa dele para separar
 * alojamento e conferir a lista, e so.
 *
 * Este arquivo e a FONTE UNICA do par valor/rotulo. O formulario publico, o
 * filtro administrativo, a lista, a ficha e o CSV leem daqui: um rotulo
 * escrito a mao em outro lugar seria uma segunda verdade esperando divergir.
 */
enum Sexo: string
{
    case Masculino = 'masculino';

    case Feminino = 'feminino';

    public function rotulo(): string
    {
        return match ($this) {
            self::Masculino => 'Masculino',
            self::Feminino => 'Feminino',
        };
    }

    /**
     * Os pares valor/rotulo que os seletores da tela consomem.
     *
     * @return array<int, array{valor: string, rotulo: string}>
     */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $sexo): array => ['valor' => $sexo->value, 'rotulo' => $sexo->rotulo()],
            self::cases(),
        );
    }
}
