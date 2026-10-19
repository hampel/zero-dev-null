<?php

namespace App\Commands\Concerns;

use App\DevNull\Client;
use App\Mcp\Present;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Prints commits from the signed API for a person at a terminal. Everything a user wrote goes
 * through text(), which strips control characters and escapes console tags, so a commit can
 * neither move the cursor nor colour the rest of the output.
 */
trait PrintsCommits
{
    /**
     * @param  array<string, mixed>  $commit  as Present::signed() shapes it
     */
    private function printCommit(array $commit, string $indent = ''): void
    {
        $stars = self::count((int) ($commit['stars_count'] ?? 0), 'star');

        $meta = array_filter([
            '<fg=cyan>@'.$this->text((string) ($commit['author']['username'] ?? '?')).'</>',
            is_string($commit['created_at'] ?? null) ? Carbon::parse($commit['created_at'])->diffForHumans() : null,
            ($commit['status'] ?? 'approved') !== 'approved' ? '<fg=yellow>'.$this->text((string) $commit['status']).'</>' : null,
            ($commit['deleted_at'] ?? null) !== null ? '<fg=red>deleted</>' : null,
            $stars !== null && ($commit['starred'] ?? false) ? "{$stars}, one yours" : $stars,
            self::count((int) ($commit['replies_count'] ?? 0), 'reply', 'replies'),
        ]);

        $this->line($indent.implode(' · ', $meta));

        foreach (explode("\n", (string) ($commit['body'] ?? '')) as $line) {
            $this->line($indent.'  '.$this->text($line));
        }

        $poll = $commit['poll'] ?? null;

        if (is_array($poll)) {
            $options = array_map(fn (array $option) => $this->text((string) ($option['label'] ?? ''))
                .(is_numeric($option['percentage'] ?? null) ? " {$option['percentage']}%" : ''), (array) ($poll['options'] ?? []));

            $this->line($indent.'  <comment>Poll'.(($poll['is_closed'] ?? false) ? ', closed' : '').':</> '.implode(' | ', $options));
        }

        if (($images = count((array) ($commit['images'] ?? []))) > 0) {
            $this->line($indent.'  <comment>['.self::count($images, 'image').']</>');
        }

        $this->line($indent.'  <fg=gray>'.$this->text((string) ($commit['html_url'] ?? '')).'</>');

        if (is_array($commit['quoted_commit'] ?? null)) {
            $this->line($indent.'  <comment>Quoting:</>');
            $this->printCommit($commit['quoted_commit'], $indent.'    ');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $commits  as the API answers them
     */
    private function printCommits(array $commits): void
    {
        foreach ($commits as $commit) {
            $this->printCommit(Present::signed($commit));
            $this->newLine();
        }
    }

    private function text(string $text): string
    {
        return OutputFormatter::escape(Client::sanitize($text));
    }

    private static function count(int $count, string $one, ?string $many = null): ?string
    {
        return $count === 0 ? null : $count.' '.($count === 1 ? $one : ($many ?? "{$one}s"));
    }
}
