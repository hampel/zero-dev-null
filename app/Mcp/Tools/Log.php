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
    One of /dev/null's three logs - its timelines - as the web shows them to the signed-in user, 20 commits a
    page: `following` (people they follow, newest first), `latest` (everyone, newest first) or `trending`
    (ranked, not by time).

    - Each commit has its author, text, counts, link preview, images, poll, quoted commit, whether the user
      starred or saved it, and `reply_preview`: the first few replies. The replies tool has all of them.
    - `suggestions` has a few commits to follow when the following log is empty, and is empty otherwise.
    - `next_cursor` is null on the last page. Otherwise pass it back as `cursor`, with the same `log`.

    Needs this machine signed in with `devnull login`, which the user runs in a terminal.
    TEXT)]
#[IsReadOnly]
class Log extends Tool
{
    public function handle(Request $request, Account $account): Response|ResponseFactory
    {
        $cursor = $request->get('cursor');

        try {
            $page = $account->log(
                strtolower(trim((string) $request->get('log', ''))),
                is_string($cursor) && $cursor !== '' ? $cursor : null,
            );
        } catch (DevNullException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'log' => $page['log'],
            'is_ranked' => $page['is_ranked'],
            'count' => count($page['data']),
            'next_cursor' => $page['next_cursor'],
            'commits' => array_map(Present::signed(...), $page['data']),
            'suggestions' => array_map(Present::signed(...), $page['suggestions']),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'log' => $schema->string()->enum(Account::LOGS)->description('Which log: following, latest or trending.')->required(),
            'cursor' => $schema->string()->description('The `next_cursor` from the previous page of the same log.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'log' => $schema->string()->description('The log this page is from.')->required(),
            'is_ranked' => $schema->boolean()->description('True for trending, which is ranked rather than newest first.')->required(),
            'count' => $schema->integer()->description('Commits in this page; at most 20.')->required(),
            'next_cursor' => $schema->string()->nullable()->description('Pass as `cursor` for the next page; null on the last.'),
            'commits' => $schema->array()->description('The commits, in the log\'s order.')->required(),
            'suggestions' => $schema->array()->description('Commits to follow, when the following log is empty.')->required(),
        ];
    }
}
