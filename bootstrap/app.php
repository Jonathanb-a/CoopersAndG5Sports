<?php

use App\Http\Middleware\AuthenticateCustomerSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\StoreErrorPage;
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
        // Detrás del proxy de Railway: esquema (https), host y puerto reales salen de X-Forwarded-*.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

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
