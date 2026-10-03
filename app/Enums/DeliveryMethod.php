<?php

namespace App\Enums;

enum DeliveryMethod: string
{
    case Pickup = 'recoger';
    case Local = 'local';
    case National = 'nacional';
}
