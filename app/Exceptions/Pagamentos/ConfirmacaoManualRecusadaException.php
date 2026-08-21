<?php

declare(strict_types=1);

namespace App\Exceptions\Pagamentos;

use RuntimeException;

/**
 * A confirmacao manual de pagamento foi recusada.
 *
 * A mensagem e escrita para quem esta na tela administrativa, nao para quem
 * programa: ela precisa explicar por que o sistema nao aceitou e o que fazer
 * em seguida.
 */
class ConfirmacaoManualRecusadaException extends RuntimeException {}
