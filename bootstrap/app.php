<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => response()->json([
                    'error' => [
                        'message' => $e->getMessage(),
                        'code' => 'validation_error',
                        'fields' => $e->errors(),
                    ],
                ], 422),

                $e instanceof AuthenticationException => response()->json([
                    'error' => [
                        'message' => 'No autenticado.',
                        'code' => 'unauthenticated',
                    ],
                ], 401),

                // MissingAbilityException extends AuthorizationException, must be checked first.
                $e instanceof MissingAbilityException => response()->json([
                    'error' => [
                        'message' => 'El token no tiene permisos para realizar esta acción.',
                        'code' => 'forbidden',
                    ],
                ], 403),

                $e instanceof AuthorizationException => response()->json([
                    'error' => [
                        'message' => $e->getMessage() ?: 'No tenés permisos para realizar esta acción.',
                        'code' => 'forbidden',
                    ],
                ], 403),

                $e instanceof ThrottleRequestsException => response()->json([
                    'error' => [
                        'message' => 'Demasiadas solicitudes. Intentá nuevamente más tarde.',
                        'code' => 'too_many_requests',
                    ],
                ], 429),

                $e instanceof NotFoundHttpException => response()->json([
                    'error' => [
                        'message' => 'Recurso no encontrado.',
                        'code' => 'not_found',
                    ],
                ], 404),

                $e instanceof HttpExceptionInterface => response()->json([
                    'error' => [
                        'message' => $e->getMessage() ?: 'Error inesperado.',
                        'code' => 'http_error',
                    ],
                ], $e->getStatusCode()),

                default => response()->json([
                    'error' => [
                        'message' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor.',
                        'code' => 'server_error',
                    ],
                ], 500),
            };
        });
    })->create();
