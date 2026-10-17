<?php

namespace App\DevNull;

use Illuminate\Support\Facades\Process;

/**
 * The public keys this machine can sign with: those the SSH agent holds, and each ~/.ssh/*.pub
 * whose private key is beside it. Never reads a private key - ssh-keygen and the agent do that.
 */
class KeyRing
{
    public function __construct(private readonly string $sshDir) {}

    /**
     * @return array<string, PublicKey> keyed by id, agent keys first
     */
    public function candidates(): array
    {
        $keys = [];

        foreach ([...$this->agentKeys(), ...$this->fileKeys()] as $key) {
            $keys[$key->id()] ??= $key;
        }

        return $keys;
    }

    /**
     * @return list<PublicKey>
     */
    public function agentKeys(): array
    {
        $result = Process::run(['ssh-add', '-L']);

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line) => PublicKey::parse($line),
            explode("\n", $result->output()),
        )));
    }

    public function agentHolds(PublicKey $key): bool
    {
        foreach ($this->agentKeys() as $held) {
            if ($held->id() === $key->id()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PublicKey>
     */
    public function fileKeys(): array
    {
        $keys = [];

        foreach (glob($this->sshDir.'/*.pub') ?: [] as $path) {
            $key = PublicKey::read($path);

            if ($key?->privateKeyPath() !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
