<?php

declare(strict_types=1);

namespace App\Exceptions\Pagamentos;

use RuntimeException;

/**
 * O evento recebe pela chave Pix do setor, mas o setor da inscricao nao tem
 * chave — ou nem setor tem.
 *
 * Isto nao deveria acontecer: o formulario do evento recusa a forma "setor"
 * enquanto existir setor ativo despreparado (RN-S4). Ainda assim a excecao
 * existe, porque entre o cadastro do evento e a inscricao da pessoa alguem pode
 * ter apagado a chave, e a alternativa seria emitir um Pix apontando para o
 * vazio. Falhar alto e melhor do que cobrar por uma chave que nao existe.
 */
class SetorSemChavePixException extends RuntimeException {}
