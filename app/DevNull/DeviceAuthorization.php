<?php

namespace App\DevNull;

/**
 * One sign-in as a device: the OAuth authorization code flow with PKCE that /dev/null runs for
 * its mobile app. The user approves the key on /mobile/authorize, the browser is sent to
 * devnull://auth/callback with a code, and the key redeems the code with the verifier.
 *
 * Nothing on this machine answers devnull://, so the code comes back by hand: the user pastes
 * the link, or the code from it.
 */
final readonly class DeviceAuthorization
{
    public const string REDIRECT_URI = 'devnull://auth/callback';

    public function __construct(public string $verifier, public string $state) {}

    public static function start(): self
    {
        return new self(self::base64url(random_bytes(32)), bin2hex(random_bytes(16)));
    }

    /**
     * The S256 challenge for the verifier (RFC 7636).
     */
    public function challenge(): string
    {
        return self::base64url(hash('sha256', $this->verifier, true));
    }

    /**
     * The page on /dev/null that asks the user to approve this key.
     */
    public function url(string $endpoint, PublicKey $key, string $deviceName): string
    {
        return $endpoint.'?'.http_build_query([
            'fingerprint' => $key->fingerprint(),
            'device_name' => $deviceName,
            'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256',
            'state' => $this->state,
            'redirect_uri' => self::REDIRECT_URI,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The code from what the user pasted: the devnull:// link, which must carry this sign-in's
     * state, or the bare code from it.
     */
    public function code(string $pasted): string
    {
        $pasted = trim($pasted);

        if (! str_starts_with($pasted, 'devnull:')) {
            return self::validCode($pasted)
                ?? throw new DevNullException('That is neither the devnull:// link nor the code from it.');
        }

        parse_str((string) parse_url($pasted, PHP_URL_QUERY), $query);

        if (($query['state'] ?? null) !== $this->state) {
            throw new DevNullException('That link is from a different sign-in. Use the link from this one, or run `devnull login` again.');
        }

        if (isset($query['error'])) {
            throw new DevNullException('The sign-in was cancelled on /dev/null.');
        }

        return self::validCode(is_string($query['code'] ?? null) ? $query['code'] : '')
            ?? throw new DevNullException('That link carries no code.');
    }

    private static function validCode(string $code): ?string
    {
        return preg_match('/^[A-Za-z0-9._~-]{16,255}$/', $code) ? $code : null;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
