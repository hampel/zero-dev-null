<?php

namespace App\DevNull;

/**
 * Reads /dev/null as the signed-in user, with the device key `devnull login` approved: their
 * profile, the three logs and a commit's replies, as the web shows them to that user.
 *
 * Only what reading leaves unchanged is here. Listing notifications marks every one of them
 * read, as the web does, so it is not a read in that sense and is left out on purpose.
 *
 * Like PublicApi, every failure is a DevNullException carrying a sentence of our own; a
 * response body is never passed on.
 */
class Account
{
    public const array LOGS = ['following', 'latest', 'trending'];

    public function __construct(private readonly SignedApi $api, private readonly string $keyPath) {}

    /**
     * @return array{user: array<string, mixed>, unread_notifications_count: int}
     */
    public function me(): array
    {
        $answer = $this->get('me', [], 'The signed-in user could not be found.');

        return [
            'user' => $answer['data'],
            'unread_notifications_count' => (int) ($answer['unread_notifications_count'] ?? 0),
        ];
    }

    /**
     * @return array{log: string, is_ranked: bool, data: list<array<string, mixed>>, next_cursor: ?string, suggestions: list<array<string, mixed>>}
     */
    public function log(string $log, ?string $cursor = null): array
    {
        if (! in_array($log, self::LOGS, true)) {
            throw new DevNullException('`log` is one of following, latest or trending.');
        }

        $answer = $this->get("log/{$log}", ['cursor' => self::cursor($cursor)], "There is no {$log} log.");

        return [
            'log' => $log,
            'is_ranked' => (bool) ($answer['is_ranked'] ?? false),
            'data' => $answer['data'],
            'next_cursor' => self::nextCursor($answer),
            'suggestions' => array_values(array_filter((array) ($answer['suggestions'] ?? []), 'is_array')),
        ];
    }

    /**
     * @return array{commit: string, data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function replies(string $commit, ?string $cursor = null): array
    {
        $id = PublicApi::commitId($commit);

        $answer = $this->get("commits/{$id}/replies", ['cursor' => self::cursor($cursor)], "There is no commit {$id} "
            .'that you can see: it may not exist, or it may be by someone who blocked you.');

        return ['commit' => $id, 'data' => $answer['data'], 'next_cursor' => self::nextCursor($answer)];
    }

    /**
     * @param  array<string, ?string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $notFound): array
    {
        $key = PublicKey::read($this->keyPath)
            ?? throw new DevNullException('This machine is not signed in to /dev/null. Run `devnull login` in a terminal; '
                .'it signs in through a browser.');

        $query = http_build_query(array_filter($query, fn (?string $value) => $value !== null), '', '&', PHP_QUERY_RFC3986);

        try {
            $answer = $this->api->send($key, 'GET', $path.($query !== '' ? "?{$query}" : ''));
        } catch (SignedApiException $e) {
            throw new DevNullException(match (true) {
                $e->apiCode === 'device_not_approved' => 'This machine\'s device key is not approved on /dev/null, or it was '
                    .'revoked under Settings → Devices. Run `devnull login` in a terminal to sign in again.',
                $e->status === 401 => '/dev/null did not accept this machine\'s signature. Check that the system clock is '
                    .'right, then try again.',
                $e->status === 404 => $notFound,
                $e->status === 429 => 'Signed requests are limited to 120 a minute, and this account has used them; try '
                    .'again in a minute.',
                $e->status >= 200 && $e->status < 300 => '/dev/null answered without the data the API returns.',
                default => "/dev/null answered HTTP {$e->status}.",
            });
        }

        if (! is_array($answer['data'] ?? null)) {
            throw new DevNullException('/dev/null answered without the data the API returns.');
        }

        return $answer;
    }

    private static function cursor(?string $cursor): ?string
    {
        if ($cursor !== null && ! preg_match('/^[A-Za-z0-9_=-]+$/', $cursor)) {
            throw new DevNullException('`cursor` must be the `next_cursor` from a previous page, unchanged.');
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private static function nextCursor(array $answer): ?string
    {
        $cursor = $answer['meta']['next_cursor'] ?? null;

        return is_string($cursor) ? $cursor : null;
    }
}
