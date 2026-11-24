<?php

namespace App\Mcp\Tools;

use App\DevNull\DevNullException;
use App\DevNull\PublicApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    A /dev/null user's public profile: name, bio, company, location, website, links to their profile, GitHub
    account and Atom feed, when they joined, and how many commits, followers and accounts followed they have.

    A user's handle is their GitHub username. An author whose `type` is `organization` is not a user, and this
    tool cannot read one. `commits_count` counts approved commits AND replies together, so it is more than
    user-commits lists by default: that lists top-level commits, and its `replies: true` lists the rest.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class User extends Tool
{
    public function handle(Request $request, PublicApi $api): Response|ResponseFactory
    {
        try {
            return Response::structured(['user' => $api->user((string) $request->get('handle', ''))]);
        } catch (DevNullException $e) {
            // an error RESULT, not a protocol error: the agent should read why and carry on
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('The user\'s handle, with or without a leading @.')->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->object()->description('The profile, as the public API returns it.')->required(),
        ];
    }
}
