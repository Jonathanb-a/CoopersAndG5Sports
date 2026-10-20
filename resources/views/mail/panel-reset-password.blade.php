<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Restablece tu contraseña del panel de {{ $store }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $theme['bg'] }};color:{{ $theme['text'] }};font-family:{!! $theme['body_font'] !!};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $theme['bg'] }};">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background:{{ $theme['surface'] }};border:1px solid {{ $theme['border'] }};border-radius:{{ $theme['radius'] }};">
                <tr>
                    <td align="center" style="padding:28px 32px 8px;">
                        @if ($logo)
                            <span style="display:inline-block;padding:8px 12px;border-radius:{{ $theme['radius'] }};background:{{ $theme['logo_bg'] }};"><img src="{{ $logo }}" alt="{{ $store }}" height="80" style="display:block;height:80px;width:auto;border:0;"></span>
                        @else
                            <span style="font-family:{!! $theme['heading_font'] !!};font-size:28px;font-weight:{{ $theme['heading_weight'] }};text-transform:{{ $theme['heading_transform'] }};color:{{ $theme['text'] }};">{{ $store }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 0;">
                        <h1 style="margin:0 0 16px;font-family:{!! $theme['heading_font'] !!};font-size:30px;line-height:1.15;font-weight:{{ $theme['heading_weight'] }};text-transform:{{ $theme['heading_transform'] }};color:{{ $theme['text'] }};">
                            Restablece tu contraseña
                        </h1>
                        <p style="margin:0 0 12px;font-size:16px;line-height:1.55;color:{{ $theme['text'] }};">¡Hola!</p>
                        <p style="margin:0 0 12px;font-size:16px;line-height:1.55;color:{{ $theme['text'] }};">Recibimos una solicitud para restablecer la contraseña de tu acceso al panel de administración de {{ $store }}.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:12px 32px 20px;">
                        <a href="{{ $url }}" style="display:inline-block;background:{{ $theme['accent'] }};color:{{ $theme['on_accent'] }};font-family:{!! $theme['body_font'] !!};font-size:16px;font-weight:700;text-decoration:none;padding:14px 28px;border-radius:{{ $theme['radius'] }};text-transform:{{ $theme['heading_transform'] }};">
                            Restablecer contraseña
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 8px;">
                        <p style="margin:0 0 12px;font-size:14px;line-height:1.55;color:{{ $theme['muted'] }};">Este enlace vence en {{ $minutes }} minutos y solo se puede usar una vez.</p>
                        <p style="margin:0 0 12px;font-size:14px;line-height:1.55;color:{{ $theme['muted'] }};">Si no lo pediste, ignora este correo: tu contraseña no cambiará.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 28px;border-top:1px solid {{ $theme['border'] }};">
                        <p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:{{ $theme['muted'] }};">Si el botón no funciona, copia y pega esta dirección en tu navegador:</p>
                        <p style="margin:0;font-size:12px;line-height:1.5;word-break:break-all;"><a href="{{ $url }}" style="color:{{ $theme['accent'] }};">{{ $url }}</a></p>
                    </td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:{{ $theme['muted'] }};">Saludos, {{ $store }}</p>
        </td>
    </tr>
</table>
</body>
</html>
