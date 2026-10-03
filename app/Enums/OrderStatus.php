<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Reserved = 'apartado';
    case Paid = 'pagado';
    case Shipped = 'enviado';
    case Delivered = 'entregado';
    case Cancelled = 'cancelado';
}
