<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Carrito
    |--------------------------------------------------------------------------
    |
    | Máximo de piezas de una misma talla en el carrito. Evita cantidades absurdas, sobre todo en
    | productos sobre pedido, que no tienen tope de stock.
    |
    */

    'max_line_quantity' => 10,

    /*
    |--------------------------------------------------------------------------
    | Pedidos
    |--------------------------------------------------------------------------
    |
    | reserve_hours: horas que un pedido apartado espera el pago por transferencia antes de vencer.
    | max_open_reservations: máximo de pedidos apartados sin pagar que un cliente puede tener a la vez
    | en una misma tienda.
    | order_prefixes: prefijo del número de pedido por tienda (slug => prefijo): CP-00042, G5-00042.
    | Una tienda sin prefijo usa su slug en mayúsculas.
    |
    */

    'reserve_hours' => 48,

    'max_open_reservations' => 3,

    'order_prefixes' => [
        'coppers' => 'CP',
        'g5' => 'G5',
    ],

];
