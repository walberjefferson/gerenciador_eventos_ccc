<?php

declare(strict_types=1);

namespace App\Exceptions\Pagamentos;

use RuntimeException;

/**
 * O evento recebe pela chave Pix do setor, mas NENHUM responsavel do setor da
 * inscricao esta apto a receber — ou nem setor a inscricao tem.
 *
 * O nome da classe ficou do tempo do responsavel unico e continua certo pelo
 * que importa: o que falta e chave. O que mudou e o plural — agora sao varias
 * pessoas que poderiam ter uma, e a mensagem precisa dizer isso, senao manda
 * procurar um campo que nao existe mais no setor (RN-R10).
 *
 * Isto nao deveria acontecer: o formulario do evento recusa a forma "setor"
 * enquanto existir setor ativo sem nenhum responsavel apto (RN-S4 com a
 * redacao da RN-R3). Ainda assim a excecao existe, porque entre o cadastro do
 * evento e a inscricao da pessoa alguem pode ter desativado o ultimo
 * responsavel ou apagado a chave dele, e a alternativa seria emitir um Pix
 * apontando para o vazio. Falhar alto e melhor do que cobrar por uma chave que
 * nao existe.
 */
class SetorSemChavePixException extends RuntimeException {}
