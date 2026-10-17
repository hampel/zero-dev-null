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
    A /dev/null user's commits, newest first, 20 at a time: the text, when it was posted, its star and reply
    counts, its link preview and images, and its link. Approved commits only.

    - By default these are the user's own top-level commits. With `replies: true` they are the user's replies
      to other commits instead, and each `parent_id` is the commit replied to - fetch it with the commit tool.
    - `next_cursor` is null on the last page. Otherwise pass it back as `cursor` for the next 20.
    - Each commit's `id` is what the commit tool takes. The `short_hash` is what the site displays, and cannot
      be looked up.
    TEXT)]
#[IsReadOnly]
class UserCommits extends Tool
{
    public function handle(Request $request, PublicApi $api): Response|ResponseFactory
    {
        $username = ltrim(trim((string) $request->get('username', '')), '@');
        $cursor = $request->get('cursor');

        try {
            $page = $api->userCommits(
                $username,
                (bool) $request->get('replies', false),
                is_string($cursor) && $cursor !== '' ? $cursor : null,
            );
        } catch (DevNullException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'author' => $username,
            'count' => count($page['data']),
            'next_cursor' => $page['next_cursor'],
            'commits' => array_map(Present::listed(...), $page['data']),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'username' => $schema->string()->description('The username, with or without a leading @.')->required(),
            'replies' => $schema->boolean()->description('True for the user\'s replies instead of their own commits.'),
            'cursor' => $schema->string()->description('The `next_cursor` from the previous page, to read the next one.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'author' => $schema->string()->description('The user every commit in this page is by.')->required(),
            'count' => $schema->integer()->description('Commits in this page; at most 20.')->required(),
            // nullable: the last page has no cursor, and a client rejects an answer that
            // breaks the declared type
            'next_cursor' => $schema->string()->nullable()->description('Pass as `cursor` for the next page; null on the last.'),
            'commits' => $schema->array()->description('The commits, newest first.')->required(),
        ];
    }
}
