<?php

namespace App\Commands;

use App\DevNull\Client;
use App\DevNull\DevNullException;
use App\DevNull\KeyRing;
use App\DevNull\PublicKey;
use App\Providers\AppServiceProvider;
use LaravelZero\Framework\Commands\Command;

class Keys extends Command
{
    protected $signature = 'keys
        {--key=* : Ask about only these public key files, instead of every key on this machine}';

    protected $description = 'Find which of your SSH keys /dev/null knows from your GitHub account';

    public function handle(Client $client, KeyRing $keyRing): int
    {
        try {
            $keys = $this->option('key')
                ? array_map(fn (string $path) => PublicKey::fromFile(AppServiceProvider::expandHome($path)), $this->option('key'))
                : array_values($keyRing->candidates());

            if ($keys === []) {
                throw new DevNullException('No Ed25519 or RSA SSH key found in your SSH agent or in ~/.ssh.');
            }

            $this->line('Asking '.config('devnull.url').' about these public keys:');

            foreach ($keys as $key) {
                $this->line("  {$key->fingerprint()}  ".($key->path ?? '(agent)'));
            }

            ['key' => $match, 'answer' => $answer] = $client->lookup($keys);
        } catch (DevNullException $e) {
            $this->error('devnull: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("On your GitHub account: {$match->fingerprint()}  ".($match->path ?? '(agent only)'));

        if ($answer !== $match->id()) {
            $this->line(Client::sanitize($answer));
        }

        if ($match->path !== null) {
            $this->newLine();
            $this->line('Pin it, so commits sign with this key and send no others:');
            $this->line("  export DEVNULL_KEY={$match->path}");
        } else {
            $this->newLine();
            $this->line('Only your SSH agent holds it. Save its public key to a file and point DEVNULL_KEY at that:');
            $this->line("  ssh-add -L | grep -F '{$match->blob}' > ~/.ssh/devnull.pub");
        }

        return self::SUCCESS;
    }
}
