<?php

namespace App\Support;

/**
 * Enlaces de WhatsApp (wa.me) de la tienda. Único lugar donde se limpia el teléfono y se arma
 * la dirección: lo usan la página de producto, el encabezado y pie de la tienda y la confirmación
 * del pedido.
 */
class WhatsAppLink
{
    private const MEXICO = '52';

    /**
     * Teléfono en formato internacional, solo dígitos: quita espacios, guiones, paréntesis y el "+".
     * Un número de 10 dígitos se toma como mexicano y se le antepone 52. Null si no hay un número usable.
     */
    public static function number(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) < 10) {
            return null;
        }

        return strlen($digits) === 10 ? self::MEXICO.$digits : $digits;
    }

    /**
     * https://wa.me/{número}?text={mensaje}. El mensaje va codificado (acentos en UTF-8, saltos de
     * línea como %0A, espacios como %20). Sin mensaje devuelve solo la base. Null si no hay número.
     */
    public static function url(?string $phone, ?string $message = null): ?string
    {
        $number = self::number($phone);

        if ($number === null) {
            return null;
        }

        $base = "https://wa.me/{$number}";
        $message = trim((string) $message);

        return $message === '' ? $base : $base.'?text='.rawurlencode($message);
    }
}
