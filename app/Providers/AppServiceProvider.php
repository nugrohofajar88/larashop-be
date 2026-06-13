<?php

namespace App\Providers;

use App\Support\Contracts\WhatsappGateway;
use App\Support\FonnteService;
use App\Support\WablasService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
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

        // Driver penyimpanan Google Drive — dipakai disk 'gdrive' untuk backup off-site.
        // Lazy: hanya diinstansiasi saat disk 'gdrive' benar-benar dipakai.
        Storage::extend('google', function ($app, array $config) {
            $client = new \Google\Client();
            $client->setClientId($config['clientId'] ?? '');
            $client->setClientSecret($config['clientSecret'] ?? '');
            $client->refreshToken($config['refreshToken'] ?? '');

            $service = new \Google\Service\Drive($client);
            $adapter = new \Masbug\Flysystem\GoogleDriveAdapter($service, $config['folder'] ?? '/');
            $driver = new \League\Flysystem\Filesystem($adapter);

            return new \Illuminate\Filesystem\FilesystemAdapter($driver, $adapter, $config);
        });
    }
}
