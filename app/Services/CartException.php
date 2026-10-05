<?php

namespace App\Services;

use RuntimeException;

/** Algo no se pudo hacer en el carrito. El mensaje está en español y se puede mostrar tal cual al cliente. */
class CartException extends RuntimeException {}
