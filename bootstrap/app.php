<?php

use App\Exceptions\Handler as ExceptionHandler;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

/*
 * Rebind the exception handler, because under `mcp:start` stdout is the JSON-RPC channel.
 * App\Exceptions\Handler says why.
 */
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, ExceptionHandler::class);

return $app;
