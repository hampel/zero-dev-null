<?php

namespace App\Commands;

use App\DevNull\Client;
use App\DevNull\DevNullException;
use App\DevNull\Image;
use App\DevNull\PublicKey;
use App\Providers\AppServiceProvider;
use LaravelZero\Framework\Commands\Command;

class Commit extends Command
{
    protected $signature = 'commit
        {message?* : The commit, 3 to 4,000 characters. Read from stdin when it is piped and none is given}
        {--reply= : The hash of a commit, or its link, to reply to}
        {--image=* : A JPEG, PNG or WebP of up to 10 MB to attach; up to 3 times}
        {--key= : The public key to sign with, overriding DEVNULL_KEY}';

    protected $description = 'Commit to /dev/null, signed with your SSH key';

    public function handle(Client $client): int
    {
        try {
            $key = self::pinnedKey($this->option('key'));

            // Read before the message, so a bad image fails before a prompt asks for text.
            $images = array_map(
                fn (string $path) => Image::fromFile(AppServiceProvider::expandHome($path)),
                array_values((array) $this->option('image')),
            );

            $message = $this->message();

            $answer = $client->commit($key, $message, $this->option('reply') ?: null, $images);
        } catch (DevNullException $e) {
            $this->error('devnull: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line($answer);

        return self::SUCCESS;
    }

    /**
     * The key from --key or DEVNULL_KEY. There is deliberately no fallback to trying every key
     * on the machine: that is what `devnull keys` is for, run once.
     */
    public static function pinnedKey(?string $option): PublicKey
    {
        $path = $option ?: config('devnull.key');

        if (! $path) {
            throw new DevNullException('No key to sign with. Run `devnull keys` to find the one on your GitHub account, then set DEVNULL_KEY to its .pub file.');
        }

        return PublicKey::fromFile(AppServiceProvider::expandHome($path));
    }

    private function message(): string
    {
        $message = implode(' ', (array) $this->argument('message'));

        if ($message === '' && ! stream_isatty(STDIN)) {
            $message = rtrim((string) stream_get_contents(STDIN), "\n");
        }

        if ($message === '' && $this->input->isInteractive()) {
            $message = (string) $this->ask('Commit message');
        }

        if (trim($message) === '') {
            throw new DevNullException('Aborting commit due to empty commit message.');
        }

        return $message;
    }
}
