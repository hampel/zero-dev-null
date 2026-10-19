<?php

namespace App\Commands;

use App\Commands\Concerns\PrintsCommits;
use App\DevNull\Account;
use App\DevNull\DevNullException;
use App\Mcp\Present;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;

class Log extends Command
{
    use PrintsCommits;

    protected $signature = 'log
        {log=following : following, latest or trending}
        {--cursor= : The cursor printed under the previous page, for the next one}
        {--json : Print the page as JSON}';

    protected $description = 'Read a /dev/null log as the web shows it to you';

    public function handle(Account $account): int
    {
        try {
            $page = $account->log(strtolower((string) $this->argument('log')), $this->option('cursor') ?: null);
        } catch (DevNullException $e) {
            $this->error('devnull: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                ...$page,
                'data' => array_map(Present::signed(...), $page['data']),
                'suggestions' => array_map(Present::signed(...), $page['suggestions']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($page['data'] === [] && $page['suggestions'] !== []) {
            $this->line('Your following log is empty. Some commits from people you might follow:');
            $this->newLine();
            $this->printCommits($page['suggestions']);
        } elseif ($page['data'] === []) {
            $this->line("The {$page['log']} log is empty.");
        }

        $this->printCommits($page['data']);

        if ($page['next_cursor'] !== null) {
            $this->line("<fg=gray>More: devnull log {$page['log']} --cursor={$this->text($page['next_cursor'])}</>");
        }

        return self::SUCCESS;
    }
}
