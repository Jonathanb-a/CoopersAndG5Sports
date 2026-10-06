# Copper's + Sports G5

Plataforma multitienda (Laravel, Filament, Inertia + React, MySQL). Las reglas del proyecto están en `CLAUDE.md`.

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
