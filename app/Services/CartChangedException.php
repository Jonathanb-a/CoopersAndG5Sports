<?php

namespace App\Services;

use RuntimeException;

/**
 * El carrito o el cupón cambiaron justo antes de apartar (se agotó una talla, cambió un precio,
 * venció el cupón...), así que el pedido no se creó. Los avisos van en español, listos para mostrarse.
 */
class CartChangedException extends RuntimeException
{
    /** @param  list<string>  $notices */
    public function __construct(public readonly array $notices)
    {
        parent::__construct(implode(' ', $notices));
    }
}
