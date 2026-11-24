<?php

namespace App\DevNull;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The read-only public API: three GET endpoints under /api/v1, no authentication, approved
 * commits only, 60 requests a minute per IP. The site's README documents it.
 *
 * Every failure is a DevNullException carrying a sentence an agent can act on. A response
 * body is never passed on: it can be anything that answered in front of the site.
 */
class PublicApi
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly string $url) {}

    /**
     * @return array<string, mixed>
     */
    public function user(string $handle): array
    {
        $handle = self::handle($handle);

        return $this->get("users/{$handle}", [], "There is no /dev/null user named {$handle}.")['data'];
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function userCommits(string $handle, bool $replies = false, ?string $cursor = null): array
    {
        $handle = self::handle($handle);

        if ($cursor !== null && ! preg_match('/^[A-Za-z0-9_=-]+$/', $cursor)) {
            throw new DevNullException('`cursor` must be the `next_cursor` from a previous page, unchanged.');
        }

        $query = array_filter(['replies' => $replies ? 1 : null, 'cursor' => $cursor]);
        $answer = $this->get("users/{$handle}/commits", $query, "There is no /dev/null user named {$handle}.");

        return ['data' => $answer['data'], 'next_cursor' => $answer['meta']['next_cursor'] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    public function commit(string $commit): array
    {
        $id = self::commitId($commit);

        return $this->get("commits/{$id}", [], "There is no public commit {$id}: it may not exist, or it may be "
            .'pending review, rejected or deleted, none of which the public API shows.')['data'];
    }

    /**
     * A commit's id from the id itself or from its link. The seven-character short hash the
     * site displays cannot be looked up: the API has no route for it.
     */
    public static function commitId(string $commit): string
    {
        $commit = strtolower(trim($commit));

        if (preg_match('#^'.self::UUID.'$#', $commit) || preg_match('#/commits/('.self::UUID.')(?:[/?\#]|$)#', $commit, $m)) {
            return $m[1] ?? $commit;
        }

        if (preg_match('/^[0-9a-f]{7}$/', $commit)) {
            throw new DevNullException("{$commit} is a short hash, which the public API cannot look up. Use the "
                .'commit\'s full `id` from user-commits, or its link.');
        }

        throw new DevNullException('`commit` must be a commit\'s full `id` (a UUID) or its link on dev-null.sh.');
    }

    private static function handle(string $handle): string
    {
        $handle = ltrim(trim($handle), '@');

        // GitHub's own rule, since every /dev/null handle is a GitHub username.
        if (! preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $handle)) {
            throw new DevNullException('`handle` must be a GitHub username, such as the one in a profile link '
                .'https://dev-null.sh/@handle.');
        }

        return $handle;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $notFound): array
    {
        try {
            $response = Http::timeout(15)->withoutRedirecting()->acceptJson()
                ->get(rtrim($this->url, '/')."/api/v1/{$path}", $query);
        } catch (ConnectionException) {
            throw new DevNullException("{$this->url} could not be reached.");
        }

        if ($response->successful() && is_array($response->json('data'))) {
            return (array) $response->json();
        }

        throw new DevNullException(match (true) {
            $response->notFound() => $notFound,
            $response->tooManyRequests() => $this->rateLimited($response),
            $response->successful() => "{$this->url} answered without the data the public API returns.",
            default => "{$this->url} answered HTTP {$response->status()}.",
        });
    }

    private function rateLimited(Response $response): string
    {
        $retry = (int) $response->header('Retry-After');

        return 'The public API allows 60 requests a minute and this machine has used them; '
            .($retry > 0 ? "try again in {$retry} seconds." : 'try again in a minute.');
    }
}
