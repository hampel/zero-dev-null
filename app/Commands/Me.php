<?php

namespace App\Commands;

use App\DevNull\Account;
use App\DevNull\Client;
use App\DevNull\DevNullException;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

class Me extends Command
{
    protected $signature = 'me
        {--json : Print the answer as JSON}';

    protected $description = 'Show who this machine is signed in to /dev/null as';

    public function handle(Account $account): int
    {
        try {
            $me = $account->me();
        } catch (DevNullException $e) {
            $this->error('devnull: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode($me, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $user = $me['user'];
        $text = fn (mixed $value) => OutputFormatter::escape(Client::sanitize((string) $value));
        $name = is_string($user['name'] ?? null) && $user['name'] !== '' ? $text($user['name']).' ' : '';

        $this->line("{$name}<fg=cyan>@{$text($user['username'] ?? '?')}</>");
        $this->line('<fg=gray>'.$text($user['html_url'] ?? '').'</>');
        $this->line(implode(' · ', [
            (int) ($user['commits_count'] ?? 0).' commits and replies',
            (int) ($user['followers_count'] ?? 0).' followers',
            (int) ($user['following_count'] ?? 0).' following',
            $me['unread_notifications_count'].' unread notifications',
        ]));

        return self::SUCCESS;
    }
}
