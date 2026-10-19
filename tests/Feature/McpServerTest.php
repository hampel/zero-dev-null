<?php

use App\Mcp\Servers\DevNullServer;
use App\Mcp\Tools\Commit;
use App\Mcp\Tools\Log;
use App\Mcp\Tools\Me;
use App\Mcp\Tools\Replies;
use App\Mcp\Tools\User;
use App\Mcp\Tools\UserCommits;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Tool;

const TOOLS = [User::class, UserCommits::class, Commit::class, Me::class, Log::class, Replies::class];

it('registers every tool', function () {
    DevNullServer::tools()->assertRegistered(TOOLS);
});

it('is registered as a local server and as nothing else', function () {
    expect(Mcp::getLocalServer('devnull'))->not->toBeNull()
        ->and(array_keys(Mcp::servers()))->toBe(['devnull']);
});

it('marks every tool read-only, and never destructive', function (string $class) {
    /** @var Tool $tool */
    $tool = app($class);

    $annotations = (array) $tool->toArray()['annotations'];

    expect($annotations)->toMatchArray(['readOnlyHint' => true])
        ->and(array_key_exists('destructiveHint', $annotations))->toBeFalse();
})->with(TOOLS);

it('marks only the profile idempotent, since commits arrive and counts change', function (string $class) {
    $annotations = (array) app($class)->toArray()['annotations'];

    $class === User::class
        ? expect($annotations)->toMatchArray(['idempotentHint' => true])
        : expect($annotations)->not->toHaveKey('idempotentHint');
})->with(TOOLS);

it('gives every tool a description that is more than its name', function (string $class) {
    expect(strlen(app($class)->description()))->toBeGreaterThan(150);
})->with(TOOLS);
