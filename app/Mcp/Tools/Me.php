<?php

namespace App\Mcp\Tools;

use App\DevNull\Account;
use App\DevNull\DevNullException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Who this machine is signed in to /dev/null as: their profile, as the user tool gives it, and how many of
    their notifications are unread.

    Needs this machine signed in with `devnull login`, which the user runs in a terminal; if it is not, this
    says so. The notifications themselves are not available here: listing them marks every one as read on
    /dev/null, which a read should not do.
    TEXT)]
#[IsReadOnly]
class Me extends Tool
{
    public function handle(Request $request, Account $account): Response|ResponseFactory
    {
        try {
            $me = $account->me();
        } catch (DevNullException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured($me);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->object()->description('The signed-in user\'s profile.')->required(),
            'unread_notifications_count' => $schema->integer()->description('Notifications not yet read on /dev/null.')->required(),
        ];
    }
}
