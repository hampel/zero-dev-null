<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

/**
 * Keep unhandled exceptions off stdout, because under `mcp:start` stdout is JSON-RPC.
 *
 * Laravel's console handler renders a throwable to the output the command writes to, so the
 * framed error box would land where an MCP client expects one JSON message per line. A failure
 * inside a tool never gets here - the server answers it with a JSON-RPC error - so this is for
 * everything outside that, such as a server that fails to start.
 *
 * A BufferedOutput (what the tests use) has no error stream and is left alone.
 */
class Handler extends BaseHandler
{
    public function renderForConsole($output, Throwable $e)
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        parent::renderForConsole($output, $e);
    }
}
