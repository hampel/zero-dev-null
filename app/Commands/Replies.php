<?php

namespace App\Commands;

use App\Commands\Concerns\PrintsCommits;
use App\DevNull\Account;
use App\DevNull\DevNullException;
use App\Mcp\Present;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;

class Replies extends Command
{
    use PrintsCommits;

    protected $signature = 'replies
        {commit : The commit\'s full id, or its link}
        {--cursor= : The cursor printed under the previous page, for the next one}
        {--json : Print the page as JSON}';

    protected $description = 'Read the replies to a /dev/null commit';

    public function handle(Account $account): int
    {
        try {
            $page = $account->replies((string) $this->argument('commit'), $this->option('cursor') ?: null);
        } catch (DevNullException $e) {
            $this->error('devnull: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                ...$page,
                'data' => array_map(Present::signed(...), $page['data']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($page['data'] === []) {
            $this->line('No replies yet.');
        }

        $this->printCommits($page['data']);

        if ($page['next_cursor'] !== null) {
            $this->line("<fg=gray>More: devnull replies {$page['commit']} --cursor={$this->text($page['next_cursor'])}</>");
        }

        return self::SUCCESS;
    }
}
