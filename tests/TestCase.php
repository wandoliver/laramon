<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Views reference the Vite manifest; tests must not depend on built
        // assets (CI runs without Node entirely).
        $this->withoutVite();
    }
}
