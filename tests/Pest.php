<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * A fresh private directory for one test, removed by cleanupDir().
 */
function scratchDir(): string
{
    $dir = sys_get_temp_dir().'/devnull-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);

    return $dir;
}

function cleanupDir(string $dir): void
{
    foreach (glob($dir.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($dir);
}

/**
 * A throwaway Ed25519 key with no passphrase; returns the path of its .pub.
 */
function throwawayKey(string $dir, string $name = 'id_ed25519'): string
{
    Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'test', '-f', "{$dir}/{$name}"])->throw();

    return "{$dir}/{$name}.pub";
}

/**
 * Checks a signature the way /dev/null does: against the public key, in the devnull-commit
 * namespace, over exactly these bytes.
 */
function signatureVerifies(string $dir, string $publicKeyPath, string $payload, string $signature): bool
{
    $key = implode(' ', array_slice(explode(' ', trim((string) file_get_contents($publicKeyPath))), 0, 2));
    file_put_contents("{$dir}/allowed_signers", "signer@test namespaces=\"devnull-commit\" {$key}\n");
    file_put_contents("{$dir}/payload.sig", "-----BEGIN SSH SIGNATURE-----\n".chunk_split($signature, 70, "\n")."-----END SSH SIGNATURE-----\n");

    return Process::input($payload)->run([
        'ssh-keygen', '-Y', 'verify', '-f', "{$dir}/allowed_signers", '-I', 'signer@test',
        '-n', 'devnull-commit', '-s', "{$dir}/payload.sig",
    ])->successful();
}

/**
 * A response captured from the public API, as tests/Fixtures holds it.
 */
function captured(string $name, int $status = 200): PromiseInterface
{
    return Http::response(
        json_decode((string) file_get_contents(__DIR__."/Fixtures/{$name}.json"), true),
        $status,
    );
}
