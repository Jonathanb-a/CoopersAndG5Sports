<?php

use App\Http\Middleware\AuthenticateCustomerSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\StoreErrorPage;
use App\Support\TrustedProxies;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Detrás de un túnel o balanceador (ngrok, Cloudflare, Nginx): solo se confía en los proxies de TRUSTED_PROXIES.
        // Sin la variable no se confía en ninguno (ver .env.example).
        TrustedProxies::configure($middleware, env('TRUSTED_PROXIES'));

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Debe correr antes de auth:customer: si la contraseña cambió, esa sesión ya no cuenta como iniciada.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: AuthenticateCustomerSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 404, 419, 500 y 503 con el diseño de la tienda del dominio (StoreErrorPage).
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request) => StoreErrorPage::render($response, $request));
    })->create();
