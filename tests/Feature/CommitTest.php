<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->dir = scratchDir();
    $this->key = throwawayKey($this->dir);
});

afterEach(fn () => cleanupDir($this->dir));

it('sends a signed commit that verifies against the key', function () {
    Http::fake(['dev-null.sh/api/cli/commits' => Http::response("https://dev-null.sh/c/0be0130\n", 201)]);

    $this->artisan('commit', ['message' => ['It', 'was', 'DNS.'], '--key' => $this->key])
        ->expectsOutput('https://dev-null.sh/c/0be0130')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        [$timestamp, $nonce, $replyTo, $message] = explode("\n", $request->body(), 4);

        expect($request->url())->toBe('https://dev-null.sh/api/cli/commits')
            ->and($request->header('Content-Type')[0])->toBe('text/plain; charset=utf-8')
            ->and((int) $timestamp)->toBeGreaterThan(time() - 60)
            ->and($nonce)->toMatch('/^[0-9a-f]{32}$/')
            ->and($replyTo)->toBe('')
            ->and($message)->toBe('It was DNS.')
            ->and(signatureVerifies($this->dir, $this->key, $request->body(), $request->header('X-SSH-Signature')[0]))->toBeTrue()
            // ...and the check is capable of failing: the same signature over other bytes does not.
            ->and(signatureVerifies($this->dir, $this->key, $request->body().'!', $request->header('X-SSH-Signature')[0]))->toBeFalse();

        return true;
    });
});

it('puts the reply on the third line of the signed payload', function () {
    Http::fake(['dev-null.sh/*' => Http::response('ok', 201)]);

    $this->artisan('commit', ['message' => ['Agreed!'], '--reply' => '0be0130', '--key' => $this->key])->assertSuccessful();

    Http::assertSent(fn (Request $request) => explode("\n", $request->body())[2] === '0be0130');
});

it('keeps a multi-line message intact', function () {
    Http::fake(['dev-null.sh/*' => Http::response('ok', 201)]);

    $this->artisan('commit', ['message' => ["line one\nline two"], '--key' => $this->key])->assertSuccessful();

    Http::assertSent(fn (Request $request) => explode("\n", $request->body(), 4)[3] === "line one\nline two");
});

it('reports a refusal with the reason /dev/null gives', function () {
    Http::fake(['dev-null.sh/*' => Http::response("Slow down: 10 commits a minute.\n", 429)]);

    $this->artisan('commit', ['message' => ['Hello world!'], '--key' => $this->key])
        ->expectsOutput('devnull: Slow down: 10 commits a minute.')
        ->assertFailed();
});

it('strips terminal escape sequences from what the server says', function () {
    Http::fake(['dev-null.sh/*' => Http::response("\e[2Jhttps://dev-null.sh/c/1\n", 201)]);

    $this->artisan('commit', ['message' => ['Hello world!'], '--key' => $this->key])
        ->expectsOutput('[2Jhttps://dev-null.sh/c/1')
        ->assertSuccessful();
});

it('refuses a message outside 3 to 4,000 characters without sending it', function (string $message) {
    $this->artisan('commit', ['message' => [$message], '--key' => $this->key])->assertFailed();

    Http::assertNothingSent();
})->with(['too short' => 'hi', 'too long' => str_repeat('x', 4001)]);

it('refuses a reply that would break the payload', function () {
    $this->artisan('commit', ['message' => ['Agreed!'], '--reply' => "0be0130\nforged", '--key' => $this->key])
        ->expectsOutput('devnull: --reply takes the hash of a commit or its link.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('will not fall back to every key on the machine when none is pinned', function () {
    config(['devnull.key' => null]);

    $this->artisan('commit', ['message' => ['Hello world!']])
        ->expectsOutputToContain('No key to sign with')
        ->assertFailed();

    Http::assertNothingSent();
});

it('uses DEVNULL_KEY when no --key is given', function () {
    config(['devnull.key' => $this->key]);
    Http::fake(['dev-null.sh/*' => Http::response('ok', 201)]);

    $this->artisan('commit', ['message' => ['Hello world!']])->assertSuccessful();

    Http::assertSent(fn (Request $request) => signatureVerifies(
        $this->dir, $this->key, $request->body(), $request->header('X-SSH-Signature')[0],
    ));
});

it('refuses to send a commit anywhere but https', function () {
    config(['devnull.url' => 'http://dev-null.sh']);

    $this->artisan('commit', ['message' => ['Hello world!'], '--key' => $this->key])
        ->expectsOutput('devnull: Refusing to send your commit to http://dev-null.sh: it is not an https:// URL.')
        ->assertFailed();

    Http::assertNothingSent();
});
