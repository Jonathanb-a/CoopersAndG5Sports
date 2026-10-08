<?php

namespace App\Enums;

/**
 * Quién actúa cuando no hay una persona: los comandos y trabajos que recorren todas las tiendas a
 * propósito (por ejemplo orders:expire-unpaid). Es explícito: un actor null o desconocido no pasa el
 * candado de tienda (StoreGuard). En el historial se muestra como «Sistema».
 */
enum SystemActor
{
    case System;
}
