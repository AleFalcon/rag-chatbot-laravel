<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware(['auth:sanctum', 'abilities:refresh']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Todas las rutas de negocio (documentos, chat, etc.) requieren un access token
// vigente con ability "access". Un refresh token robado nunca puede pasar por acá.
Route::middleware(['auth:sanctum', 'abilities:access'])->group(function (): void {
    // Endpoint dummy: existe solo para poder testear el middleware de auth/abilities
    // en este ticket. La implementación real llega en el ticket de ingesta de documentos.
    // Cuando ese endpoint exista, aplicarle también el limiter 'upload' (5 req/hora).
    Route::get('/documents', function (Request $request) {
        return response()->json(['data' => []]);
    });

    // Cuando exista el endpoint real, aplicarle el limiter 'chat' (10 req/min).
    // Route::post('/conversations/{conversation}/messages', ...)->middleware('throttle:chat');
});
