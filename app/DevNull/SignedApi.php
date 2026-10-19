<?php

namespace App\DevNull;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * /api/v1 as the mobile app uses it: every request signed with a key the user approved as a
 * device. The signed message is the timestamp, the nonce, the method, the path with its query
 * exactly as sent, and the raw body, joined by "\n", in the "devnull-api" namespace.
 *
 * A key on GitHub is not enough here and is not needed: the approval is the proof.
 */
class SignedApi
{
    public function __construct(private readonly string $url, private readonly Signer $signer) {}

    /**
     * Redeems a sign-in code, which approves the key as a device of the user who approved it;
     * returns that user.
     *
     * @return array<string, mixed>
     */
    public function redeem(PublicKey $key, string $code, string $verifier): array
    {
        $user = $this->send($key, 'POST', 'devices', ['code' => $code, 'code_verifier' => $verifier])['data'] ?? null;

        if (! is_array($user) || ! is_string($user['username'] ?? null)) {
            throw new DevNullException("Unexpected answer from {$this->url}.");
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    public function send(PublicKey $key, string $method, string $path, ?array $json = null): array
    {
        $endpoint = Client::secureEndpoint($this->url, '/api/v1/'.ltrim($path, '/'));
        $query = parse_url($endpoint, PHP_URL_QUERY);
        $uri = parse_url($endpoint, PHP_URL_PATH).(is_string($query) ? "?{$query}" : '');

        // The body is signed as bytes, so it is encoded once and sent exactly as signed.
        $body = $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $signature = $this->signer->sign($key, implode("\n", [$timestamp, $nonce, $method, $uri, $body]), Signer::API_NAMESPACE);

        $request = Http::timeout(30)->withoutRedirecting()->acceptJson()->withHeaders([
            'X-Devnull-Timestamp' => $timestamp,
            'X-Devnull-Nonce' => $nonce,
            'X-SSH-Signature' => $signature,
        ]);

        if ($json !== null) {
            $request = $request->withBody($body, 'application/json');
        }

        try {
            return $this->json($request->send($method, $endpoint));
        } catch (ConnectionException) {
            throw new DevNullException("Could not reach {$this->url}.");
        }
    }

    /**
     * The answer; a refusal becomes an exception carrying /dev/null's message. Errors from the
     * signed API are JSON with a `message` meant for the user, so that is all that is passed on.
     *
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $answer = $response->json();

        if ($response->successful() && is_array($answer)) {
            return $answer;
        }

        $message = is_array($answer) && is_string($answer['message'] ?? null)
            ? mb_substr(Client::sanitize($answer['message']), 0, 300)
            : "Unexpected answer from {$this->url} (HTTP {$response->status()}).";

        if ($response->status() === 429 && is_numeric($retry = $response->header('Retry-After'))) {
            $message .= " Try again in {$retry} seconds.";
        }

        throw new DevNullException($message);
    }
}
