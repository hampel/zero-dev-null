<?php

namespace App\Mcp;

/**
 * Shapes the API's answers for an agent. Facts are passed through unchanged; what changes is
 * what is repeated, since every token of a list is one an agent reads.
 */
class Present
{
    /**
     * A commit in a list of one user's commits: the author is the user asked about, so it is
     * stated once beside the list rather than twenty times inside it.
     *
     * @param  array<string, mixed>  $commit
     * @return array<string, mixed>
     */
    public static function listed(array $commit): array
    {
        unset($commit['author']);

        return $commit;
    }

    /**
     * A commit on its own, with only enough of its author to say who wrote it and where to
     * look them up - the user tool has the rest.
     *
     * @param  array<string, mixed>  $commit
     * @return array<string, mixed>
     */
    public static function commit(array $commit): array
    {
        $author = (array) ($commit['author'] ?? []);

        $commit['author'] = array_intersect_key($author, array_flip(['username', 'name', 'html_url']));

        return $commit;
    }

    /**
     * A commit from the signed API. It carries what the web renders a card from: the body cut
     * into segments, a list excerpt and mention offsets, all of which `body` already holds, so
     * they go. The quoted commit and the replies previewed under it are shaped the same way, and
     * the preview becomes a plain list - the replies tool pages through the rest.
     *
     * @param  array<string, mixed>  $commit
     * @return array<string, mixed>
     */
    public static function signed(array $commit): array
    {
        unset($commit['body_segments'], $commit['preview_segments'], $commit['mentions']);

        if (is_array($commit['author'] ?? null)) {
            $commit = self::commit($commit);
        }

        if (is_array($commit['quoted_commit'] ?? null)) {
            $commit['quoted_commit'] = self::signed($commit['quoted_commit']);
        }

        if (array_key_exists('reply_preview', $commit)) {
            $replies = is_array($commit['reply_preview']) ? (array) ($commit['reply_preview']['data'] ?? []) : [];
            $commit['reply_preview'] = array_values(array_map(self::signed(...), array_filter($replies, 'is_array')));
        }

        return $commit;
    }
}
