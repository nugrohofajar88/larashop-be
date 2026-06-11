<?php

namespace App\Providers;

use App\Support\Contracts\WhatsappGateway;
use App\Support\FonnteService;
use App\Support\WablasService;
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
        // Driver WhatsApp aktif (wablas/fonnte) via config WHATSAPP_DRIVER.
        $this->app->bind(WhatsappGateway::class, function ($app) {
            return $app->make(
                config('services.whatsapp.driver') === 'fonnte'
                    ? FonnteService::class
                    : WablasService::class
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
