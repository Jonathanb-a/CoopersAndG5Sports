<?php

namespace App\Exceptions;

use RuntimeException;

/** Se intentó un cambio de estado de pedido que no está permitido. El mensaje se puede mostrar al personal. */
class InvalidOrderTransitionException extends RuntimeException {}
