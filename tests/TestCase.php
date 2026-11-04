<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The board search indexes need pg_trgm; CREATE EXTENSION is idempotent and
        // cheap, so ensure it here rather than documenting it as a manual step.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable) {
            // A role without the privilege still runs every test except the trigram
            // index creation, which the migration tolerates.
        }
    }
}
