<?php

namespace App\Mcp\Tools;

use App\DevNull\DevNullException;
use App\DevNull\PublicApi;
use App\Mcp\Present;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    One /dev/null commit: its text, author, when it was posted, star and reply counts, link preview (with its
    embedded player, if any) and images (`url`, `width`, `height`), and `parent_id` when it is a reply.

    Takes the commit's full `id` from user-commits, or its link (https://dev-null.sh/@user/commits/<id>). The
    seven-character short hash the site displays cannot be looked up. Only approved commits are public, so a
    commit pending review, rejected or deleted is reported as not found.

    The replies to a commit are not in the public API: `replies_count` says how many there are, and only a
    reply's `parent_id` leads back to the commit it answers.
    TEXT)]
#[IsReadOnly]
class Commit extends Tool
{
    public function handle(Request $request, PublicApi $api): Response|ResponseFactory
    {
        try {
            return Response::structured(['commit' => Present::commit($api->commit((string) $request->get('commit', '')))]);
        } catch (DevNullException $e) {
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'commit' => $schema->string()->description('The commit\'s full `id`, or its link on dev-null.sh.')->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'commit' => $schema->object()->description('The commit, as the public API returns it.')->required(),
        ];
    }
}
