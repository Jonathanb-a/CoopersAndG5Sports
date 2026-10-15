<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * HTML de las páginas de contenido, saneado con una lista cerrada: solo títulos, párrafos, listas,
 * negritas, cursivas, citas y enlaces http(s), mailto, tel o relativos. Sin scripts, estilos, iframes,
 * imágenes, atributos on* ni style/class (el aspecto lo pone el tema). Se aplica al guardar y al mostrar.
 */
class SafeHtml
{
    private const TEXT = ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'hr'];

    public static function clean(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        static $sanitizer = null;

        $sanitizer ??= new HtmlSanitizer(self::config());

        return trim($sanitizer->sanitize($html));
    }

    private static function config(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('a', ['href', 'title'])
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            // Los enlaces externos no deben poder controlar la página que los abre.
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength(200000);

        foreach (self::TEXT as $element) {
            $config = $config->allowElement($element);
        }

        // Estas se quitan con todo y su contenido.
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form'] as $element) {
            $config = $config->dropElement($element);
        }

        return $config;
    }

    /** Texto plano de un HTML, en una línea (para meta description). */
    public static function excerpt(?string $html, int $length = 155): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string) $html)), ENT_QUOTES | ENT_HTML5)));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }
}
