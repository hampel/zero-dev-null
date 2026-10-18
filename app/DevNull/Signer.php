<?php

namespace App\DevNull;

use Illuminate\Support\Facades\Process;

/**
 * Signs a commit with `ssh-keygen -Y sign` in the "devnull-commit" namespace, so the signature
 * is good for /dev/null commits only: it cannot sign a git commit or log in anywhere. A commit
 * with images is signed in "devnull-commit-images" instead, and its payload has a line more.
 */
class Signer
{
    public const string NAMESPACE = 'devnull-commit';

    public const string IMAGES_NAMESPACE = 'devnull-commit-images';

    public function __construct(private readonly KeyRing $keyRing) {}

    /**
     * The signature as /dev/null wants it in X-SSH-Signature: the armoured body on one line.
     */
    public function sign(PublicKey $key, string $payload, string $namespace = self::NAMESPACE): string
    {
        // Through the agent if it holds the key - ssh-keygen does that when handed the public
        // half - otherwise with the private key file, which may prompt for its passphrase on
        // the terminal.
        $signingKey = $this->keyRing->agentHolds($key) ? $key->path : $key->privateKeyPath();

        if ($signingKey === null) {
            throw new DevNullException("Could not find the private key of {$key->fingerprint()}.");
        }

        // With no file argument, ssh-keygen signs stdin and writes the signature to stdout:
        // the payload signed is exactly the bytes sent, and nothing touches the disk.
        $result = Process::input($payload)
            ->run(['ssh-keygen', '-Y', 'sign', '-n', $namespace, '-f', $signingKey]);

        if (! $result->successful()) {
            throw new DevNullException(trim("Could not sign your commit with {$signingKey}.\n".$result->errorOutput()));
        }

        $signature = implode('', array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn (string $line) => $line !== '' && ! str_contains($line, '-----'),
        ));

        if (! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $signature)) {
            throw new DevNullException('ssh-keygen produced an unexpected signature.');
        }

        return $signature;
    }
}
