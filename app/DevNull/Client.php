<?php

namespace App\DevNull;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The two endpoints behind https://dev-null.sh/commit. Both return plain text, and take it too,
 * except a commit with images, which is multipart.
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
     *
     * @param  list<Image>  $images
     */
    public function commit(PublicKey $key, string $message, ?string $replyTo = null, array $images = []): string
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

        if (count($images) > Image::MAX_IMAGES) {
            throw new DevNullException(sprintf('A commit can carry up to %d images.', Image::MAX_IMAGES));
        }

        $payload = $this->payload($message, $replyTo, $images);

        if ($images === []) {
            $signature = $this->signer->sign($key, $payload);

            return $this->text($this->post('/api/cli/commits', $payload, ['X-SSH-Signature' => $signature]));
        }

        $signature = $this->signer->sign($key, $payload, Signer::IMAGES_NAMESPACE);

        // As the official script sends it: the payload as a file part beside the images, in
        // the order their hashes were signed. Up to 30 MB, so the timeout is the script's too.
        $request = $this->request(600)->withHeaders(['X-SSH-Signature' => $signature])
            ->attach('payload', $payload, 'commit');

        foreach ($images as $position => $image) {
            $request->attach('images[]', $image->bytes, 'image-'.($position + 1), ['Content-Type' => $image->type]);
        }

        return $this->text($this->send(fn () => $request->post($this->endpoint('/api/cli/commits'))));
    }

    /**
     * Timestamp, nonce, reply-to and message, one per line, with no trailing newline. With
     * images, a line of their SHA-256 hashes, comma-separated, goes before the message.
     *
     * @param  list<Image>  $images
     */
    public function payload(string $message, ?string $replyTo = null, array $images = []): string
    {
        $hashes = $images === [] ? [] : [implode(',', array_map(fn (Image $image) => $image->sha256(), $images))];

        return implode("\n", [time(), bin2hex(random_bytes(16)), $replyTo ?? '', ...$hashes, $message]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function post(string $path, string $body, array $headers = []): Response
    {
        $endpoint = $this->endpoint($path);

        return $this->send(fn () => $this->request()
            ->withHeaders($headers)
            ->withBody($body, 'text/plain; charset=utf-8')
            ->post($endpoint));
    }

    /**
     * The URL to send to, refusing anything but https:// before a byte leaves the machine.
     */
    private function endpoint(string $path): string
    {
        return self::secureEndpoint($this->url, $path, 'your commit');
    }

    /**
     * The URL of a path on /dev/null, refusing anything but https:// apart from a local
     * development server.
     */
    public static function secureEndpoint(string $url, string $path, string $what = 'a signed request'): string
    {
        $local = preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/?$#', $url);

        if (! str_starts_with($url, 'https://') && ! $local) {
            throw new DevNullException("Refusing to send {$what} to {$url}: it is not an https:// URL.");
        }

        return rtrim($url, '/').$path;
    }

    /**
     * @param  callable(): Response  $send
     */
    private function send(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException) {
            throw new DevNullException("Could not reach {$this->url}.");
        }
    }

    private function request(int $timeout = 30): PendingRequest
    {
        return Http::timeout($timeout)->withoutRedirecting()->accept('text/plain');
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
