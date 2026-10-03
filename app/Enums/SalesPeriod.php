<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/** Periodo del dashboard de ventas. Siempre termina hoy (en la zona horaria de la app). */
enum SalesPeriod: string implements HasLabel
{
    case Today = 'today';
    case Week = 'week';
    case Month30 = 'days30';
    case ThisMonth = 'month';

    public function getLabel(): string
    {
        return match ($this) {
            self::Today => 'Hoy',
            self::Week => '7 días',
            self::Month30 => '30 días',
            self::ThisMonth => 'Este mes',
        };
    }

    /** @return array{CarbonInterface, CarbonInterface} inicio y fin del periodo */
    public function range(): array
    {
        $now = now();

        $from = match ($this) {
            self::Today => $now->copy()->startOfDay(),
            self::Week => $now->copy()->subDays(6)->startOfDay(),
            self::Month30 => $now->copy()->subDays(29)->startOfDay(),
            self::ThisMonth => $now->copy()->startOfMonth(),
        };

        return [$from, $now->copy()->endOfDay()];
    }

    /** El periodo guardado en los filtros del dashboard (7 días por defecto). */
    public static function fromFilters(?array $filters): self
    {
        return self::tryFrom((string) ($filters['period'] ?? '')) ?? self::Week;
    }
}
