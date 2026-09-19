<?php

use App\Http\Middleware\DenyShoppingToStaff;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'shopper' => DenyShoppingToStaff::class,
        ]);

        // CutLuy signs its deliveries with an HMAC instead of a CSRF token.
        $middleware->validateCsrfTokens(except: [
            'webhooks/cutluy',
        ]);

        // Behind a tunnel or any reverse proxy, the real scheme and host
        // arrive in X-Forwarded-* headers. Without trusting them Laravel
        // builds every link from the local address, so a shared URL serves
        // pages full of 127.0.0.1 links and mixed-content warnings.
        //
        // Off unless TRUSTED_PROXIES says otherwise: trusting a proxy you do
        // not control lets a caller forge the host Laravel thinks it is on.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)),
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
