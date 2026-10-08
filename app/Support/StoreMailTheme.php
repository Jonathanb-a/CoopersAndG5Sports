<?php

namespace App\Support;

/**
 * Colores y tipografía de cada tienda para sus correos (mismos valores que el tema de la página,
 * ver CLAUDE.md). Los correos no cargan fuentes web: se usan las del sistema que más se parecen.
 */
class StoreMailTheme
{
    /** @var array<string, array<string, string>> */
    private const THEMES = [
        'coppers' => [
            'bg' => '#FAF7F2',
            'surface' => '#FFFFFF',
            'border' => '#E8E2D9',
            'text' => '#1C1C1C',
            'muted' => '#6B6560',
            'accent' => '#B8692A',
            'on_accent' => '#FFFFFF',
            'radius' => '3px',
            'heading_font' => "'Cormorant Garamond', Georgia, 'Times New Roman', serif",
            'heading_transform' => 'none',
            'heading_weight' => '600',
            'body_font' => 'Inter, Arial, Helvetica, sans-serif',
        ],
        'g5' => [
            'bg' => '#0E0E10',
            'surface' => '#1A1A1F',
            'border' => '#2A2A31',
            'text' => '#F5F5F5',
            'muted' => '#A3A3AD',
            'accent' => '#35F0EE',
            'on_accent' => '#000000',
            'radius' => '6px',
            'heading_font' => "Anton, Impact, 'Arial Black', sans-serif",
            'heading_transform' => 'uppercase',
            'heading_weight' => '700',
            'body_font' => 'Barlow, Arial, Helvetica, sans-serif',
        ],
    ];

    /** Paleta del tema; una tienda con un tema desconocido usa la de Copper's. */
    public static function for(?string $theme): array
    {
        return self::THEMES[$theme] ?? self::THEMES['coppers'];
    }

    /** Dirección pública del logo de la tienda (public/brand/{tema}/logo.png), o null si todavía no existe. */
    public static function logoUrl(?string $theme, string $baseUrl): ?string
    {
        if (! $theme || ! isset(self::THEMES[$theme]) || ! is_file(public_path("brand/{$theme}/logo.png"))) {
            return null;
        }

        return rtrim($baseUrl, '/')."/brand/{$theme}/logo.png";
    }
}
