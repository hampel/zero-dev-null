<?php

namespace App\DevNull;

/**
 * An SSH public key as /dev/null identifies it: "<type> <base64>", without the comment.
 */
final readonly class PublicKey
{
    public const array TYPES = ['ssh-ed25519', 'ssh-rsa'];

    private function __construct(
        public string $type,
        public string $blob,
        public ?string $path = null,
    ) {}

    /**
     * Parses one line of a .pub file or of `ssh-add -L`; null for anything that is not an
     * Ed25519 or RSA key, which are the only types /dev/null accepts.
     */
    public static function parse(string $line, ?string $path = null): ?self
    {
        $parts = preg_split('/\s+/', trim($line));

        if (count($parts) < 2 || ! in_array($parts[0], self::TYPES, true)
            || ! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $parts[1])) {
            return null;
        }

        return new self($parts[0], $parts[1], $path);
    }

    /**
     * The key in the first line of a .pub file; null when the file holds no usable key.
     */
    public static function read(string $path): ?self
    {
        $line = is_file($path) && is_readable($path) ? strtok((string) file_get_contents($path), "\n") : false;

        return $line === false ? null : self::parse($line, $path);
    }

    public static function fromFile(string $path): self
    {
        return self::read($path) ?? throw new DevNullException("{$path} is not an Ed25519 or RSA public key.");
    }

    public function id(): string
    {
        return "{$this->type} {$this->blob}";
    }

    /**
     * The private key file beside a .pub, when there is one.
     */
    public function privateKeyPath(): ?string
    {
        if ($this->path === null || ! str_ends_with($this->path, '.pub')) {
            return null;
        }

        $private = substr($this->path, 0, -4);

        return is_file($private) ? $private : null;
    }

    public function fingerprint(): string
    {
        $hash = base64_encode(hash('sha256', (string) base64_decode($this->blob, true), true));

        return 'SHA256:'.rtrim($hash, '=');
    }
}
