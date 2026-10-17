<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Commit;
use App\Mcp\Tools\User;
use App\Mcp\Tools\UserCommits;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Read-only access to /dev/null through its public API, over stdio.
 *
 * Every tool here reads, and the server deliberately has no way to post: a commit is public
 * and signed with the user's key, and an agent doing that is a separate decision with a tool
 * of its own, not a flag on one of these.
 */
#[Name('/dev/null')]
#[Instructions(<<<'TEXT'
    Read-only access to /dev/null (dev-null.sh), a social feed where developers post short plain-text "commits".
    Nothing here posts, stars or follows.

    There is no timeline, search or list of everyone: start from a username. user gives a profile;
    user-commits gives that user's commits or their replies, 20 at a time with a cursor; commit gives one commit by
    its full id or its link. A username is the user's GitHub username.

    Only approved commits are public, so "not found" can mean pending review, rejected or deleted, not only
    missing. The API allows 60 requests a minute from this machine; prefer one page and a summary to paging through
    a whole history. Times are ISO 8601 in UTC.
    TEXT)]
class DevNullServer extends Server
{
    protected array $tools = [
        User::class,
        UserCommits::class,
        Commit::class,
    ];

    protected function boot(): void
    {
        $this->version = (string) config('app.version');
    }
}
