<?php

namespace App\Services;

use RuntimeException;

/**
 * El cliente ya tiene el máximo de pedidos apartados sin pagar en la tienda
 * (shop.max_open_reservations), así que el pedido no se creó. El mensaje está en español y se
 * puede mostrar tal cual.
 */
class ReservationLimitException extends RuntimeException {}
