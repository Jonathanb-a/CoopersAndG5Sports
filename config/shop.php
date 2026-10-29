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
    | cash_reserve_hours: horas que un pedido en efectivo espera su confirmación antes de vencer.
    | cash_reserve_max_days: tope de días, contados desde que se creó el pedido, a los que puede llegar
    | su vencimiento (por darle más tiempo o asignarle fecha de entrega).
    | max_open_reservations: máximo de pedidos apartados sin pagar que un cliente puede tener a la vez
    | en una misma tienda.
    | order_prefixes: prefijo del número de pedido por tienda (slug => prefijo): CP-00042, G5-00042.
    | Una tienda sin prefijo usa su slug en mayúsculas.
    |
    */

    'reserve_hours' => 48,

    'cash_reserve_hours' => 72,

    'cash_reserve_max_days' => 7,

    'max_open_reservations' => 3,

    /*
    |--------------------------------------------------------------------------
    | Dominios adicionales (demo con túnel)
    |--------------------------------------------------------------------------
    |
    | STORE_DOMAIN_ALIASES=dominio=slug,otro=slug: dominios que se resuelven a una tienda sin tocar
    | stores.domain (por ejemplo el dominio fijo de ngrok para Copper's). Solo para demos locales;
    | en producción, el dominio real va en stores.domain.
    |
    */

    'domain_aliases' => (string) env('STORE_DOMAIN_ALIASES', ''),

    'order_prefixes' => [
        'coppers' => 'CP',
        'g5' => 'G5',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tiendas
    |--------------------------------------------------------------------------
    |
    | Las dos tiendas que crean app:setup-produccion y el DatabaseSeeder (slug => datos). El dominio
    | sale de COPPERS_DOMAIN y G5_DOMAIN; en producción son obligatorios. Sin ellos el seeder usa los
    | dominios locales (coppers.localhost y g5.localhost).
    |
    */

    'stores' => [
        'coppers' => ['name' => "Copper's", 'theme' => 'coppers', 'domain' => env('COPPERS_DOMAIN')],
        'g5' => ['name' => 'Sports G5', 'theme' => 'g5', 'domain' => env('G5_DOMAIN')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Owner inicial
    |--------------------------------------------------------------------------
    |
    | Cuenta del owner que crean app:setup-produccion y el DatabaseSeeder. Se leen por config (no con env())
    | para que funcionen también con php artisan config:cache.
    |
    */

    'admin_email' => env('ADMIN_EMAIL'),

    'admin_password' => env('ADMIN_PASSWORD'),

];
