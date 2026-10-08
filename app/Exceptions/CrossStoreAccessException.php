<?php

namespace App\Exceptions;

/**
 * Se intentó tocar un pedido (o mezclar un pedido con una pieza) de una tienda que no le toca a quien
 * lo hace. El mensaje no dice nada del pedido. Extiende InvalidOrderTransitionException para que las
 * pantallas que ya atrapan esa excepción lo traten como cualquier otro cambio no permitido.
 */
class CrossStoreAccessException extends InvalidOrderTransitionException {}
