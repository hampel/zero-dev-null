<?php

namespace App\Commands;

use App\DevNull\Client;
use App\DevNull\DeviceAuthorization;
use App\DevNull\DevNullException;
use App\DevNull\PublicKey;
use App\DevNull\SignedApi;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class Login extends Command
{
    protected $signature = 'login
        {--name=devnull CLI : What Settings → Devices on /dev/null calls this machine}';

    protected $description = 'Approve this machine as a device on /dev/null, for the signed API';

    public function handle(SignedApi $api): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('devnull: login needs a terminal: it asks you to paste a link from your browser.');

            return self::FAILURE;
        }

        try {
            $name = trim((string) $this->option('name'));

            if ($name === '' || mb_strlen($name) > 100) {
                throw new DevNullException('--name is 1 to 100 characters.');
            }

            $url = (string) config('devnull.url');
            $page = Client::secureEndpoint($url, '/mobile/authorize', 'a sign-in');
            $key = $this->deviceKey();
            $authorization = DeviceAuthorization::start();

            $this->line("Device key: {$key->path} ({$key->fingerprint()})");
            $this->newLine();
            $this->line('1. Open this link. It signs in as whoever is signed in on /dev/null in that browser.');
            $this->newLine();
            $this->line('   '.$authorization->url($page, $key, $name));
            $this->newLine();
            $this->line('2. Before you approve, open the developer tools (F12) on the Network tab. Approving sends');
            $this->line('   the browser to a devnull:// link meant for the /dev/null phone app, which nothing here');
            $this->line('   opens, so the browser drops it and the page seems to do nothing.');
            $this->newLine();
            $this->line('3. Approve, select the "authorize" request (a 303), and copy its Location response');
            $this->line('   header: devnull://auth/callback?code=…. It works once, for 5 minutes.');
            $this->newLine();

            $code = $authorization->code((string) $this->ask('Paste the devnull:// link, or the code from it'));
            $user = $api->redeem($key, $code, $authorization->verifier);
        } catch (DevNullException $e) {
            $this->error('devnull: '.$e->getMessage());

            return self::FAILURE;
        }

        $who = is_string($user['name'] ?? null) && $user['name'] !== ''
            ? Client::sanitize($user['name']).' (@'.Client::sanitize($user['username']).')'
            : '@'.Client::sanitize($user['username']);

        $this->info("Signed in as {$who}.");
        $this->line('This key can now read and post on /dev/null as you. Revoke it under Settings → Devices:');
        $this->line(rtrim($url, '/').'/settings/devices');

        return self::SUCCESS;
    }

    /**
     * The device key, made on first use. It has no passphrase, because a signed request is made
     * without anyone at the keyboard; what protects it is the file's mode, and revoking it.
     */
    private function deviceKey(): PublicKey
    {
        $pub = AppServiceProvider::deviceKeyPath();

        if (! str_ends_with($pub, '.pub')) {
            throw new DevNullException("The device key must be a .pub file; {$pub} is not.");
        }

        $private = substr($pub, 0, -4);

        if (! file_exists($pub)) {
            if (file_exists($private)) {
                throw new DevNullException("{$private} exists without its .pub; not overwriting it.");
            }

            $dir = dirname($private);

            if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
                throw new DevNullException("Could not create {$dir}.");
            }

            $result = Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'devnull device', '-f', $private]);

            if (! $result->successful()) {
                throw new DevNullException(trim($result->errorOutput() ?: 'ssh-keygen failed.'));
            }

            $this->line("Made a device key at {$private}.");
        }

        return PublicKey::fromFile($pub);
    }
}
