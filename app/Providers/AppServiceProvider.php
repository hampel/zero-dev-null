<?php

namespace App\Providers;

use App\DevNull\Client;
use App\DevNull\KeyRing;
use App\DevNull\Signer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(KeyRing::class, fn () => new KeyRing(self::home().'/.ssh'));

        $this->app->bind(Client::class, fn ($app) => new Client(
            (string) $app['config']->get('devnull.url'),
            $app->make(Signer::class),
        ));
    }

    public static function home(): string
    {
        return rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')), '/');
    }

    /**
     * Expands a leading ~/ the way the shell would, for paths that arrive through .env or an
     * option the shell did not expand.
     */
    public static function expandHome(string $path): string
    {
        return str_starts_with($path, '~/') ? self::home().substr($path, 1) : $path;
    }
}
