# Copper's + Sports G5

Plataforma multitienda (Laravel, Filament, Inertia + React, MySQL). Las reglas del proyecto están en `CLAUDE.md`.

## Puesta en producción

`php artisan app:setup-produccion` deja la base lista para el cliente: crea solo el owner y las dos tiendas, sin staff ni productos de ejemplo. Lee estas variables del `.env`:

```
ADMIN_EMAIL=dueno@ejemplo.com
ADMIN_PASSWORD=una-contraseña-de-12-o-más
COPPERS_DOMAIN=coppers.ejemplo.com
G5_DOMAIN=sportsg5.ejemplo.com
```

- Falla sin guardar nada si falta el correo o algún dominio, si los dos dominios son iguales o si la contraseña tiene menos de 12 caracteres.
- Se puede repetir sin duplicar: una tienda que ya existe solo actualiza su dominio (conserva nombre, estado y lo capturado en el panel) y un owner que ya existe conserva su contraseña.
- Si se corrió `php artisan config:cache`, vuelve a correrlo después de cambiar el `.env`.

El `DatabaseSeeder` (`php artisan db:seed`) con `APP_ENV=production` crea las tiendas, el owner y los borradores de las páginas, pero no el staff ni los productos de demostración. En local los sigue creando, con los dominios `coppers.localhost` y `g5.localhost` si `COPPERS_DOMAIN` y `G5_DOMAIN` están vacíos.

## Tareas programadas

El comando `orders:expire-unpaid` cancela los apartados que no se pagaron a tiempo y regresa su stock. Corre cada 10 minutos con el programador de Laravel (`routes/console.php`).

En producción se programa esta línea en el cron del servidor:

```
* * * * * php artisan schedule:run
```

En local se usa:

```
php artisan schedule:work
```

Para ver qué pedidos cancelaría sin tocar nada:

```
php artisan orders:expire-unpaid --dry-run
```
