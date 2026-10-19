<?php

namespace App\Mcp\Tools;

use App\DevNull\Account;
use App\DevNull\DevNullException;
use App\Mcp\Present;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    The replies to a /dev/null commit, as the web shows them to the signed-in user, up to 50 a page: each
    with its author, text, counts and whether the user starred it. The public API has no way to list them.

    - `commit` is the commit's full `id` or its link, as from any other tool.
    - A deleted commit still lists its replies, as a thread keeps it as a tombstone.
    - `next_cursor` is null on the last page. Otherwise pass it back as `cursor`, with the same `commit`.

    Needs this machine signed in with `devnull login`, which the user runs in a terminal.
    TEXT)]
#[IsReadOnly]
class Replies extends Tool
{
    public function handle(Request $request, Account $account): Response|ResponseFactory
    {
        $cursor = $request->get('cursor');

        try {
            $page = $account->replies(
                (string) $request->get('commit', ''),
                is_string($cursor) && $cursor !== '' ? $cursor : null,
            );
        } catch (DevNullException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'commit' => $page['commit'],
            'count' => count($page['data']),
            'next_cursor' => $page['next_cursor'],
            'replies' => array_map(Present::signed(...), $page['data']),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'commit' => $schema->string()->description('The commit\'s full `id` (a UUID) or its link on dev-null.sh.')->required(),
            'cursor' => $schema->string()->description('The `next_cursor` from the previous page of the same replies.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'commit' => $schema->string()->description('The id of the commit these reply to.')->required(),
            'count' => $schema->integer()->description('Replies in this page; at most 50.')->required(),
            'next_cursor' => $schema->string()->nullable()->description('Pass as `cursor` for the next page; null on the last.'),
            'replies' => $schema->array()->description('The replies, in the order the web shows them.')->required(),
        ];
    }
}
