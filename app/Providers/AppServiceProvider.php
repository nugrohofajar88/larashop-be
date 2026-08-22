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

        // WORKAROUND SEMENTARA (lihat catatan di bawah): daftarkan PSR-4 manual utk
        // minishlink/web-push & dependensinya. `composer dump-autoload` di lingkungan
        // dev ini hang terus (belum diketahui sebabnya) sesudah `composer require`,
        // jadi vendor/composer/autoload_static.php belum ke-update walau paket-nya
        // sudah kesimpan di vendor/. Aman dihapus begitu composer normal lagi/deploy
        // production jalanin composer install (autoloader Composer asli akan lebih
        // dulu resolve class-nya, shim ini otomatis gak kepakai lagi).
        spl_autoload_register(function (string $class): void {
            static $map = [
                'Minishlink\\WebPush\\' => 'minishlink/web-push/src/',
                'Http\\Discovery\\' => 'php-http/discovery/src/',
                'Http\\Client\\' => 'php-http/httplug/src/',
                'Http\\Promise\\' => 'php-http/promise/src/',
                'SpomkyLabs\\Pki\\' => 'spomky-labs/pki-framework/src/',
                'Jose\\Component\\' => 'web-token/jwt-library/',
                'Base64Url\\' => 'spomky-labs/base64url/src/',
            ];

            foreach ($map as $prefix => $relativeDir) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $path = base_path('vendor/'.$relativeDir)
                    .str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                if (is_file($path)) {
                    require $path;
                }

                return;
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->bearerToken() ?: $request->ip());
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
