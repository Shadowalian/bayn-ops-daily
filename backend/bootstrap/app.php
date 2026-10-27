<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Token-based Sanctum API (Bearer) — React SPA on a separate origin
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                $errors = $e->errors();
                $first = collect($errors)->flatten()->first() ?? $e->getMessage();
                $code = array_key_first($errors) ?? 'validation_error';

                return response()->json([
                    'code' => $code,
                    'message' => $first,
                    'errors' => $errors,
                ], 422);
            }
        });
    })->create();
