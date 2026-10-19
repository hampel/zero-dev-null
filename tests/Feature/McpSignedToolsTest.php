<?php

use App\Mcp\Servers\DevNullServer;
use App\Mcp\Tools\Log;
use App\Mcp\Tools\Me;
use App\Mcp\Tools\Replies;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PARENT_ID = '01a15000-0000-7000-8000-000000000003';

beforeEach(function () {
    $this->dir = scratchDir();
    $this->key = throwawayKey($this->dir, 'device_ed25519');
    config(['devnull.device_key' => $this->key]);
});

afterEach(fn () => cleanupDir($this->dir));

/**
 * Checks a signed API request the way /dev/null does: the timestamp, nonce, method, path with
 * its query exactly as sent, and body, in the devnull-api namespace.
 */
function signedRequestVerifies(string $dir, string $key, Request $request, ?string $uri = null): bool
{
    $url = parse_url($request->url());
    $uri ??= $url['path'].(isset($url['query']) ? "?{$url['query']}" : '');

    return signatureVerifies($dir, $key, implode("\n", [
        $request->header('X-Devnull-Timestamp')[0],
        $request->header('X-Devnull-Nonce')[0],
        $request->method(),
        $uri,
        $request->body(),
    ]), $request->header('X-SSH-Signature')[0], 'devnull-api');
}

it('says who is signed in, with a request signed by the device key', function () {
    Http::fake(['dev-null.sh/api/v1/me' => captured('me')]);

    $response = DevNullServer::tool(Me::class)
        ->assertOk()
        ->assertSee(['"username":"acme-dev"', '"unread_notifications_count":2']);

    assertMatchesOutputSchema($response, Me::class);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && signedRequestVerifies($this->dir, $this->key, $request, '/api/v1/me')
        // ...and the check can fail: the same signature does not cover another path
        && ! signedRequestVerifies($this->dir, $this->key, $request, '/api/v1/notifications'));
});

it('reads a log without what only the web renders', function () {
    Http::fake(['dev-null.sh/api/v1/log/latest*' => captured('log-latest')]);

    $response = DevNullServer::tool(Log::class, ['log' => 'Latest'])
        ->assertOk()
        ->assertSee(['"log":"latest"', '"count":3', '"next_cursor":"eyJ', '"poll":{', '"quoted_commit":{', '"reply_preview":[{'])
        ->assertDontSee(['"body_segments"', '"preview_segments"', '"mentions"', '"avatar_url"']);

    assertMatchesOutputSchema($response, Log::class);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://dev-null.sh/api/v1/log/latest');
});

it('signs the cursor as part of the path', function () {
    Http::fake(['dev-null.sh/*' => captured('log-latest')]);

    DevNullServer::tool(Log::class, ['log' => 'following', 'cursor' => 'eyJabc-_'])->assertOk();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://dev-null.sh/api/v1/log/following?cursor=eyJabc-_'
        && signedRequestVerifies($this->dir, $this->key, $request, '/api/v1/log/following?cursor=eyJabc-_')
        && ! signedRequestVerifies($this->dir, $this->key, $request, '/api/v1/log/following'));
});

it('lists the replies to a commit given by its link', function () {
    Http::fake(['dev-null.sh/api/v1/commits/'.PARENT_ID.'/replies*' => captured('replies')]);

    $response = DevNullServer::tool(Replies::class, ['commit' => 'https://dev-null.sh/@acme-dev/commits/'.PARENT_ID])
        ->assertOk()
        ->assertSee(['"commit":"'.PARENT_ID.'"', '"count":1', '"next_cursor":null', '"parent_id":"'.PARENT_ID.'"'])
        ->assertDontSee('"body_segments"');

    assertMatchesOutputSchema($response, Replies::class);
});

it('says to run devnull login when this machine is not signed in', function () {
    config(['devnull.device_key' => "{$this->dir}/nowhere.pub"]);

    DevNullServer::tool(Me::class)->assertHasErrors(['Run `devnull login` in a terminal']);

    Http::assertNothingSent();
});

it('says to sign in again when the device was revoked, in its own words', function () {
    Http::fake(['dev-null.sh/*' => Http::response([
        'message' => 'This key isn\'t approved for the /dev/null app. Sign in again in the app.',
        'code' => 'device_not_approved',
    ], 401)]);

    DevNullServer::tool(Log::class, ['log' => 'trending'])
        ->assertHasErrors(['revoked under Settings → Devices. Run `devnull login`'])
        ->assertDontSee('in the app');
});

it('explains a failure as a tool error with a sentence in it', function (int $status, string $expected) {
    Http::fake(['dev-null.sh/*' => Http::response(['message' => 'whatever answered'], $status)]);

    DevNullServer::tool(Replies::class, ['commit' => PARENT_ID])
        ->assertHasErrors([$expected])
        ->assertDontSee('whatever answered');
})->with([
    'not found' => [404, 'that you can see'],
    'rate limited' => [429, '120 a minute'],
    'bad signature' => [401, 'system clock'],
    'server error' => [503, 'answered HTTP 503'],
]);

it('refuses what it cannot ask for, without asking', function (string $tool, array $arguments, string $expected) {
    DevNullServer::tool($tool, $arguments)->assertHasErrors([$expected]);

    Http::assertNothingSent();
})->with([
    'an unknown log' => [Log::class, ['log' => 'everything'], 'following, latest or trending'],
    'a changed cursor' => [Log::class, ['log' => 'latest', 'cursor' => 'abc&x=1'], 'unchanged'],
    'a short hash' => [Replies::class, ['commit' => 'cef7611'], 'short hash'],
]);
