<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Carbon's language is process-wide: a test that ran a request in Spanish must not leave dates in Spanish for the next one.
        Carbon::setLocale((string) config('app.locale'));
    }
}
