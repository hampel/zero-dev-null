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
}
