<?php

use App\DevNull\PublicKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->dir = scratchDir();
    $this->one = throwawayKey($this->dir, 'one');
    $this->two = throwawayKey($this->dir, 'two');
});

afterEach(fn () => cleanupDir($this->dir));

it('names the key on the GitHub account and how to pin it', function () {
    $two = PublicKey::fromFile($this->two);
    Http::fake(['dev-null.sh/api/cli/key-lookups' => Http::response($two->id()."\n")]);

    $this->artisan('keys', ['--key' => [$this->one, $this->two]])
        ->expectsOutputToContain("export DEVNULL_KEY={$this->two}")
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->body()
        === PublicKey::fromFile($this->one)->id()."\n".$two->id()."\n");
});

it('rejects an answer naming a key it was not asked about', function () {
    $stranger = throwawayKey($this->dir, 'stranger');
    Http::fake(['dev-null.sh/*' => Http::response(PublicKey::fromFile($stranger)->id())]);

    $this->artisan('keys', ['--key' => [$this->one]])
        ->expectsOutput('devnull: Unexpected answer from https://dev-null.sh.')
        ->assertFailed();
});

it('reports when none of the keys is known', function () {
    Http::fake(['dev-null.sh/*' => Http::response('None of these keys is on the GitHub account of a /dev/null user.', 404)]);

    $this->artisan('keys', ['--key' => [$this->one]])
        ->expectsOutputToContain('None of these keys')
        ->assertFailed();
});
