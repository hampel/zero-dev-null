<?php

use App\Mcp\Servers\DevNullServer;
use Laravel\Mcp\Facades\Mcp;

// Local only: a stdio server started with `devnull mcp:start devnull`. There is no
// Mcp::web() here and there must never be one - nothing in this tool listens on a port.
Mcp::local('devnull', DevNullServer::class);
