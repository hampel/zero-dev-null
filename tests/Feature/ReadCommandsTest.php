<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->dir = scratchDir();
    config(['devnull.device_key' => throwawayKey($this->dir, 'device_ed25519')]);
});

afterEach(fn () => cleanupDir($this->dir));

/**
 * The captured latest log, with its first commit changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function latestLogWith(array $changes): array
{
    $log = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/log-latest.json'), true);
    $log['data'][0] = [...$log['data'][0], ...$changes];

    return $log;
}

it('says who is signed in', function () {
    Http::fake(['dev-null.sh/api/v1/me' => captured('me')]);

    $this->artisan('me')
        ->expectsOutputToContain('Acme Developer @acme-dev')
        ->expectsOutputToContain('25 commits and replies · 16 followers · 13 following · 2 unread notifications')
        ->assertSuccessful();
});

it('prints a log, with its polls, quotes and the way to the next page', function () {
    Http::fake(['dev-null.sh/api/v1/log/latest*' => captured('log-latest')]);

    $this->artisan('log', ['log' => 'latest'])
        ->expectsOutputToContain('3 stars · 16 replies')
        ->expectsOutputToContain('  Example commit 1, as captured but with its text replaced.')
        ->expectsOutputToContain('Poll: Option 1 | Option 2 | Option 3 | Option 4')
        ->expectsOutputToContain('Quoting:')
        ->expectsOutputToContain('https://dev-null.sh/@acme-dev/commits/01a15000-0000-7000-8000-000000000003')
        ->expectsOutputToContain('More: devnull log latest --cursor=eyJ')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://dev-null.sh/api/v1/log/latest');
});

it('reads the following log by default, from the cursor it was given', function () {
    Http::fake(['dev-null.sh/*' => captured('log-latest')]);

    $this->artisan('log', ['--cursor' => 'eyJabc-_'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://dev-null.sh/api/v1/log/following?cursor=eyJabc-_');
});

it('prints what a user wrote as text, never as console markup or escape codes', function () {
    Http::fake(['dev-null.sh/*' => Http::response(latestLogWith([
        'body' => "<error>not red</error>\e[31m and </> done",
    ]))]);

    $this->artisan('log', ['log' => 'latest'])
        ->expectsOutputToContain('<error>not red</error>[31m and </> done')
        ->assertSuccessful();
});

it('offers suggestions when the following log is empty', function () {
    $log = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/log-latest.json'), true);
    Http::fake(['dev-null.sh/*' => Http::response([...$log, 'log' => 'following', 'data' => [], 'suggestions' => $log['data']])]);

    $this->artisan('log')
        ->expectsOutputToContain('Your following log is empty.')
        ->expectsOutputToContain('Example commit 2')
        ->assertSuccessful();
});

it('prints replies, and says when there are none', function () {
    Http::fake(['dev-null.sh/api/v1/commits/*/replies*' => Http::sequence()
        ->push(json_decode((string) file_get_contents(__DIR__.'/../Fixtures/replies.json'), true))
        ->push(['data' => [], 'meta' => ['next_cursor' => null]])]);

    $this->artisan('replies', ['commit' => 'https://dev-null.sh/@acme-dev/commits/01a15000-0000-7000-8000-000000000003'])
        ->expectsOutputToContain('Example commit 500')
        ->doesntExpectOutputToContain('More:')
        ->assertSuccessful();

    $this->artisan('replies', ['commit' => '01a15000-0000-7000-8000-000000000003'])
        ->expectsOutputToContain('No replies yet.')
        ->assertSuccessful();
});

it('prints JSON for a script', function () {
    Http::fake(['dev-null.sh/*' => captured('log-latest')]);

    $this->artisan('log', ['log' => 'latest', '--json' => true])
        ->expectsOutputToContain('"next_cursor": "eyJ')
        ->doesntExpectOutputToContain('body_segments')
        ->assertSuccessful();
});

it('says to run devnull login when this machine is not signed in', function (string $command, array $arguments) {
    config(['devnull.device_key' => "{$this->dir}/nowhere.pub"]);

    $this->artisan($command, $arguments)
        ->expectsOutputToContain('Run `devnull login` in a terminal')
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    'me' => ['me', []],
    'log' => ['log', []],
    'replies' => ['replies', ['commit' => '01a15000-0000-7000-8000-000000000003']],
]);
