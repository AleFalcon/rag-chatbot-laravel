<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters used by the API. Applied via the 'throttle:<name>' middleware.
     */
    private function configureRateLimiting(): void
    {
        // Protege /api/auth/login contra fuerza bruta. Sin usuario autenticado
        // todavía, así que se limita por IP.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Reservado para /api/conversations/{conversation}/messages (ticket de chat).
        // Limita por usuario autenticado para evitar consumo excesivo de la API de Anthropic.
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?? $request->ip()));

        // Reservado para /api/documents (ticket de ingesta). El procesamiento de un PDF
        // (parsing + embeddings) es costoso, por eso el límite es más estricto.
        RateLimiter::for('upload', fn (Request $request) => Limit::perHour(5)->by($request->user()?->id ?? $request->ip()));
    }
}
