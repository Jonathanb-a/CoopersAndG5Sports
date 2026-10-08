{{ $creatingPassword ? 'Crea tu contraseña' : 'Restablece tu contraseña' }} · {{ $store }}

¡Hola!

@if ($creatingPassword)
Tu cuenta en {{ $store }} se creó con Google. Con este enlace puedes definir una contraseña para entrar también con tu correo:
@else
Recibimos una solicitud para restablecer la contraseña de tu cuenta en {{ $store }}:
@endif

{{ $url }}

Este enlace vence en {{ $minutes }} minutos y solo se puede usar una vez.
Si no lo pediste, ignora este correo: tu contraseña no cambiará.

Saludos, {{ $store }}
