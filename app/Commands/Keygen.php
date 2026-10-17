<?php

namespace App\Commands;

use App\DevNull\PublicKey;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class Keygen extends Command
{
    protected $signature = 'keygen
        {path=~/.ssh/devnull_ed25519 : Where to write the private key; the public key goes beside it as .pub}';

    protected $description = 'Generate an SSH key for signing /dev/null commits only';

    public function handle(): int
    {
        $path = AppServiceProvider::expandHome((string) $this->argument('path'));

        if (file_exists($path) || file_exists("{$path}.pub")) {
            $this->error("devnull: {$path} already exists; not overwriting it.");

            return self::FAILURE;
        }

        // No passphrase, like /dev/null's own CI recipe: the key can sign /dev/null commits
        // and nothing else, because it goes on GitHub as a signing key, not an authentication
        // key.
        $result = Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'devnull', '-f', $path]);

        if (! $result->successful()) {
            $this->error('devnull: '.trim($result->errorOutput() ?: 'ssh-keygen failed.'));

            return self::FAILURE;
        }

        $key = PublicKey::fromFile("{$path}.pub");

        $this->info("Wrote {$path} and {$path}.pub ({$key->fingerprint()}).");
        $this->newLine();
        $this->line('1. Add it to GitHub as a <options=bold>signing key</> - not an authentication key - at');
        $this->line('   https://github.com/settings/ssh/new');
        $this->newLine();
        $this->line((string) file_get_contents("{$path}.pub"));
        $this->line('2. Pin it:');
        $this->line("   export DEVNULL_KEY={$path}.pub");

        return self::SUCCESS;
    }
}
