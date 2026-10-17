<?php

namespace Tests;

use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A real request would publish a commit. Nothing in the suite may reach the network.
        Http::preventStrayRequests();
    }
}
