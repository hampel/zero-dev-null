<?php

namespace App\Providers;

use App\DevNull\Account;
use App\DevNull\Client;
use App\DevNull\KeyRing;
use App\DevNull\PublicApi;
use App\DevNull\SignedApi;
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

        $this->app->bind(PublicApi::class, fn ($app) => new PublicApi((string) $app['config']->get('devnull.url')));

        $this->app->bind(SignedApi::class, fn ($app) => new SignedApi(
            (string) $app['config']->get('devnull.url'),
            $app->make(Signer::class),
        ));

        $this->app->bind(Account::class, fn ($app) => new Account($app->make(SignedApi::class), self::deviceKeyPath()));
    }

    /**
     * The device key's .pub: DEVNULL_DEVICE_KEY, or one in devnull's own config directory, apart
     * from the keys in ~/.ssh - it signs in as the user, not as a GitHub key.
     */
    public static function deviceKeyPath(): string
    {
        $configured = config('devnull.device_key');

        if (is_string($configured) && $configured !== '') {
            return self::expandHome($configured);
        }

        $config = rtrim((string) getenv('XDG_CONFIG_HOME'), '/') ?: self::home().'/.config';

        return "{$config}/devnull/device_ed25519.pub";
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
