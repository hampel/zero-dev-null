<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
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
function signatureVerifies(string $dir, string $publicKeyPath, string $payload, string $signature, string $namespace = 'devnull-commit'): bool
{
    $key = implode(' ', array_slice(explode(' ', trim((string) file_get_contents($publicKeyPath))), 0, 2));
    file_put_contents("{$dir}/allowed_signers", "signer@test namespaces=\"{$namespace}\" {$key}\n");
    file_put_contents("{$dir}/payload.sig", "-----BEGIN SSH SIGNATURE-----\n".chunk_split($signature, 70, "\n")."-----END SSH SIGNATURE-----\n");

    return Process::input($payload)->run([
        'ssh-keygen', '-Y', 'verify', '-f', "{$dir}/allowed_signers", '-I', 'signer@test',
        '-n', $namespace, '-s', "{$dir}/payload.sig",
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

/**
 * Holds a tool's structured answer to the outputSchema it declares, as an MCP client does.
 *
 * laravel/mcp's own test helpers never compare the two, and Claude Code rejects an answer that
 * does not match - which is how `next_cursor: null` against a schema of `string` reached a real
 * session on 2026-10-18 with every test green. Checks required keys and each key's JSON type;
 * an empty PHP array passes as either array or object, since JSON cannot tell them apart.
 *
 * @param  class-string<Tool>  $tool
 */
function assertMatchesOutputSchema(TestResponse $response, string $tool): void
{
    $content = null;
    $response->assertStructuredContent(function (AssertableJson $json) use (&$content) {
        $content = $json->toArray();
        $json->etc();
    });

    $schema = app($tool)->toArray()['outputSchema'] ?? [];

    foreach ($schema['required'] ?? [] as $key) {
        expect($content)->toHaveKey($key);
    }

    foreach ($schema['properties'] ?? [] as $key => $property) {
        if (! array_key_exists($key, $content)) {
            continue;
        }

        $value = $content[$key];
        $actual = match (true) {
            $value === null => ['null'],
            is_bool($value) => ['boolean'],
            is_int($value) => ['integer', 'number'],
            is_float($value) => ['number'],
            is_string($value) => ['string'],
            $value === [] => ['array', 'object'],
            is_array($value) && array_is_list($value) => ['array'],
            default => ['object'],
        };

        expect(array_intersect($actual, (array) ($property['type'] ?? [])))
            ->not->toBeEmpty("`{$key}` is ".implode('/', $actual).", but {$tool} declares ".json_encode($property['type'] ?? null));
    }
}
