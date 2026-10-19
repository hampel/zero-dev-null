<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Commit;
use App\Mcp\Tools\Log;
use App\Mcp\Tools\Me;
use App\Mcp\Tools\Replies;
use App\Mcp\Tools\User;
use App\Mcp\Tools\UserCommits;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Read-only access to /dev/null over stdio: through its public API, and as the signed-in user
 * once `devnull login` has approved this machine.
 *
 * Every tool here reads, and the server deliberately has no way to post: a commit is public
 * and signed with the user's key, and an agent doing that is a separate decision with a tool
 * of its own, not a flag on one of these. The device key could post; no tool here asks it to.
 */
#[Name('/dev/null')]
#[Instructions(<<<'TEXT'
    Read-only access to /dev/null (dev-null.sh), a social feed where developers post short plain-text "commits".
    Nothing here posts, stars, follows or marks notifications read.

    Public, needing nothing: user gives a profile; user-commits gives a user's commits or their replies, 20 at a
    time with a cursor; commit gives one commit by its full id or its link. A username is the user's GitHub
    username. Only approved commits are public, so "not found" can mean pending review, rejected or deleted.

    As the signed-in user, once they have run `devnull login` in a terminal: me says who that is and how many
    notifications are unread; log gives the following, latest or trending timeline; replies lists the replies to a
    commit. If this machine is not signed in, these say so - an agent cannot sign in for the user.

    The public API allows 60 requests a minute from this machine and the signed one 120 a minute for the user;
    prefer one page and a summary to paging through a whole history. Times are ISO 8601 in UTC.
    TEXT)]
class DevNullServer extends Server
{
    protected array $tools = [
        User::class,
        UserCommits::class,
        Commit::class,
        Me::class,
        Log::class,
        Replies::class,
    ];

    protected function boot(): void
    {
        $this->version = (string) config('app.version');
    }
}
