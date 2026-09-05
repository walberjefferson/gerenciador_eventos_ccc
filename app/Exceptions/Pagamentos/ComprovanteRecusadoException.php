<?php

declare(strict_types=1);

namespace App\Exceptions\Pagamentos;

use RuntimeException;

/**
 * O envio ou a conferencia de um comprovante foi recusado.
 *
 * A mensagem e escrita para quem esta na tela — o participante que tentou
 * enviar, ou quem tentou conferir —, e nao para quem programa: ela precisa
 * dizer por que nao deu e o que fazer em seguida.
 */
class ComprovanteRecusadoException extends RuntimeException {}
