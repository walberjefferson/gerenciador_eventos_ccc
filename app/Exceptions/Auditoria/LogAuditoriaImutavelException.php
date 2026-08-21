<?php

declare(strict_types=1);

namespace App\Exceptions\Auditoria;

use RuntimeException;

/**
 * Alguem tentou alterar ou apagar um registro de auditoria pela aplicacao.
 *
 * Nao e um erro de usuario: nenhuma tela oferece esse caminho. Se esta excecao
 * aparecer, e porque codigo novo tentou fazer o que a Fase 9 decidiu que nunca
 * seria possivel — e a excecao existe para que essa tentativa apareca em vez
 * de passar em silencio.
 */
class LogAuditoriaImutavelException extends RuntimeException {}
