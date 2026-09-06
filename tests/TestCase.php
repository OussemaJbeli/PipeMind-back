<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A request nobody faked must fail loudly.
        //
        // Without this, an unfaked call reaches the real network — and on a
        // developer machine running the AI service on :8001, or a live GitHub
        // token in the environment, the suite quietly passes or fails based on
        // what happens to be running. Tests that depend on the outside world are
        // not tests.
        Http::preventStrayRequests();
    }
}
