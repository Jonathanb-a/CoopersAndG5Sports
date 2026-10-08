<?php

use App\Http\Middleware\AuthenticateCustomerSession;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Debe correr antes de auth:customer: si la contraseña cambió, esa sesión ya no cuenta como iniciada.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: AuthenticateCustomerSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
