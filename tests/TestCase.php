<?php

namespace Tests;

use App\Support\ThemeTokens;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The palette is memoised per process; the database is not. A test
        // that seeded it must not hand its colours to the next test.
        ThemeTokens::flush();
    }
}
