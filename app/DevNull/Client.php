<?php

namespace App\DevNull;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The two endpoints behind https://dev-null.sh/commit. Both take and return plain text.
 */
class Client
{
    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 4000;

    public function __construct(private readonly string $url, private readonly Signer $signer) {}

    /**
     * Asks which of these keys is on the GitHub account of a /dev/null user. Every key passed
     * is sent - they are public, but they are also an inventory of what this machine holds.
     *
     * @param  list<PublicKey>  $keys
     * @return array{key: PublicKey, answer: string}
     */
    public function lookup(array $keys): array
    {
        $body = implode('', array_map(fn (PublicKey $key) => $key->id()."\n", $keys));
        $answer = $this->text($this->post('/api/cli/key-lookups', $body));

        // Only ever accept one of the keys we asked about, whatever /dev/null answers.
        $match = PublicKey::parse($answer);

        foreach ($keys as $key) {
            if ($match !== null && $key->id() === $match->id()) {
                return ['key' => $key, 'answer' => $answer];
            }
        }

        throw new DevNullException("Unexpected answer from {$this->url}.");
    }

    /**
     * Signs and sends a commit; returns /dev/null's answer - its link, and whether it is
     * pending review.
     */
    public function commit(PublicKey $key, string $message, ?string $replyTo = null): string
    {
        $length = mb_strlen($message);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new DevNullException(sprintf(
                'A commit is %d to %d characters; this one is %d.', self::MIN_LENGTH, self::MAX_LENGTH, $length,
            ));
        }

        // It goes on its own line of the signed payload.
        if ($replyTo !== null && ! preg_match('#^[A-Za-z0-9:/._@-]+$#', $replyTo)) {
            throw new DevNullException('--reply takes the hash of a commit or its link.');
        }

        $payload = $this->payload($message, $replyTo);
        $signature = $this->signer->sign($key, $payload);

        return $this->text($this->post('/api/cli/commits', $payload, ['X-SSH-Signature' => $signature]));
    }

    /**
     * Timestamp, nonce, reply-to and message, one per line, with no trailing newline.
     */
    public function payload(string $message, ?string $replyTo = null): string
    {
        return implode("\n", [time(), bin2hex(random_bytes(16)), $replyTo ?? '', $message]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function post(string $path, string $body, array $headers = []): Response
    {
        $local = preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/?$#', $this->url);

        if (! str_starts_with($this->url, 'https://') && ! $local) {
            throw new DevNullException("Refusing to send your commit to {$this->url}: it is not an https:// URL.");
        }

        try {
            return $this->request()
                ->withHeaders($headers)
                ->withBody($body, 'text/plain; charset=utf-8')
                ->post(rtrim($this->url, '/').$path);
        } catch (ConnectionException) {
            throw new DevNullException("Could not reach {$this->url}.");
        }
    }

    private function request(): PendingRequest
    {
        return Http::timeout(30)->withoutRedirecting()->accept('text/plain');
    }

    /**
     * The answer, always plain text; a refusal becomes an exception carrying the reason.
     */
    private function text(Response $response): string
    {
        $body = self::sanitize(trim($response->body()));

        if (! $response->successful()) {
            throw new DevNullException($body !== ''
                ? $body
                : "Unexpected answer from {$this->url} (HTTP {$response->status()}).");
        }

        return $body;
    }

    /**
     * Removes control characters, such as terminal escape sequences, from text we print.
     */
    public static function sanitize(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
    }
}
