<?php

namespace App\Support;

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * Lee TRUSTED_PROXIES del .env: a qué proxies les cree Laravel los encabezados X-Forwarded-* (esquema https,
 * dominio, puerto e IP del cliente). Por defecto a ninguno: un encabezado X-Forwarded-Proto inventado por
 * cualquiera no cambia los enlaces ni la IP de los límites de intentos.
 */
class TrustedProxies
{
    /**
     * Aplica TRUSTED_PROXIES al middleware de Laravel. Se confía en X-Forwarded-For, -Port y -Proto (IP del cliente,
     * puerto y https), pero NO en X-Forwarded-Host: el dominio de la tienda sale del Host de la petición, y un
     * encabezado reenviado no debe poder cambiarlo.
     */
    public static function configure(Middleware $middleware, ?string $value): void
    {
        $proxies = self::parse($value);

        if ($proxies === null) {
            return;
        }

        $middleware->trustProxies(
            at: $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    /**
     * @return array<int, string>|string|null null = no confiar en ningún proxy; '*' = en cualquiera (solo para
     *                                        local con túnel); una lista de IPs o rangos CIDR = solo en esos.
     */
    public static function parse(?string $value): array|string|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($value === '*' || $value === '**') {
            return '*';
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''));

        return $list === [] ? null : $list;
    }
}
