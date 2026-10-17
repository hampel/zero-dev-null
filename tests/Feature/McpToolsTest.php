<?php

use App\Mcp\Servers\DevNullServer;
use App\Mcp\Tools\Commit;
use App\Mcp\Tools\User;
use App\Mcp\Tools\UserCommits;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const COMMIT_ID = '01a14614-0274-7293-a35d-6cb58c01d7a1';

it('returns a profile', function () {
    Http::fake(['dev-null.sh/api/v1/users/acme-dev' => captured('user')]);

    DevNullServer::tool(User::class, ['username' => '@acme-dev'])
        ->assertOk()
        ->assertSee(['"username":"acme-dev"', '"followers_count"']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://dev-null.sh/api/v1/users/acme-dev');
});

it('lists commits with the author once, not on every commit', function () {
    Http::fake(['dev-null.sh/api/v1/users/acme-dev/commits*' => captured('user-commits')]);

    DevNullServer::tool(UserCommits::class, ['username' => 'acme-dev'])
        ->assertOk()
        ->assertSee(['"author":"acme-dev"', '"count":3', '"next_cursor":"eyJ', '"short_hash"'])
        ->assertDontSee('"avatar_url"');
});

it('pages with the cursor it was given, and asks for replies when told to', function () {
    Http::fake(['dev-null.sh/api/v1/users/acme-dev/commits*' => captured('user-replies')]);

    DevNullServer::tool(UserCommits::class, ['username' => 'acme-dev', 'replies' => true, 'cursor' => 'eyJabc-_'])
        ->assertOk()
        ->assertSee(['"next_cursor":null', '"parent_id":"01a1']);

    Http::assertSent(fn (Request $request) => $request->url()
        === 'https://dev-null.sh/api/v1/users/acme-dev/commits?replies=1&cursor=eyJabc-_');
});

it('returns a commit by its id or by its link', function (string $given) {
    Http::fake(['dev-null.sh/api/v1/commits/'.COMMIT_ID => captured('commit-link-preview')]);

    DevNullServer::tool(Commit::class, ['commit' => $given])
        ->assertOk()
        ->assertSee(['"link_preview"', '"username":"acme-dev"'])
        ->assertDontSee('"avatar_url"');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://dev-null.sh/api/v1/commits/'.COMMIT_ID);
})->with([
    'id' => COMMIT_ID,
    'link' => 'https://dev-null.sh/@acme-dev/commits/'.COMMIT_ID,
    'link with a query' => 'https://dev-null.sh/@acme-dev/commits/'.COMMIT_ID.'?ref=x',
]);

it('passes images through', function () {
    Http::fake(['dev-null.sh/api/v1/commits/*' => captured('commit-images')]);

    DevNullServer::tool(Commit::class, ['commit' => '01a144e4-6bac-702c-988f-b24d83d079eb'])
        ->assertOk()
        ->assertSee(['"width":1280', 'https://dev-null.sh/commit-images/']);
});

it('explains a failure as a tool error with a sentence in it', function (mixed $answer, string $expected) {
    Http::fake(['dev-null.sh/*' => $answer]);

    DevNullServer::tool(Commit::class, ['commit' => COMMIT_ID])->assertHasErrors([$expected]);
})->with([
    'not public' => [fn () => captured('not-found-commit', 404), 'pending review, rejected or deleted'],
    'rate limited' => [fn () => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '37']), 'try again in 37 seconds'],
    'failing' => [fn () => Http::response('<html>Bad Gateway</html>', 502), 'answered HTTP 502'],
    'a page, not the API' => [fn () => Http::response('<html>Back soon</html>', 200), 'without the data the public API returns'],
    'unreachable' => [fn () => throw new ConnectException('Could not resolve host', new Psr7Request('GET', 'https://dev-null.sh')), 'could not be reached'],
]);

it('never passes on what the server said', function () {
    Http::fake(['dev-null.sh/*' => Http::response('<html>secret-looking WAF page</html>', 403)]);

    DevNullServer::tool(Commit::class, ['commit' => COMMIT_ID])
        ->assertHasErrors(['answered HTTP 403'])
        ->assertDontSee('WAF');
});

it('says an unknown user is unknown', function () {
    Http::fake(['dev-null.sh/*' => captured('not-found-user', 404)]);

    DevNullServer::tool(User::class, ['username' => 'nobody'])->assertHasErrors(['no /dev/null user named nobody']);
});

it('refuses what it cannot look up without asking the site', function (string $tool, array $arguments, string $expected) {
    DevNullServer::tool($tool, $arguments)->assertHasErrors([$expected]);

    Http::assertNothingSent();
})->with([
    'a short hash' => [Commit::class, ['commit' => '960889a'], 'is a short hash'],
    'not a commit' => [Commit::class, ['commit' => '../users/x'], 'must be a commit\'s full `id`'],
    'not a username' => [User::class, ['username' => 'a/b'], 'must be a GitHub username'],
    'a forged cursor' => [UserCommits::class, ['username' => 'acme-dev', 'cursor' => 'x&replies=0'], 'must be the `next_cursor`'],
]);
