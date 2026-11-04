<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Run a raw statement, used by the container entrypoint for idempotent
 * prerequisites such as `CREATE EXTENSION IF NOT EXISTS pg_trgm`.
 */
class DbStatement extends Command
{
    protected $signature = 'db:statement {sql}';

    protected $description = 'Execute a single raw SQL statement';

    public function handle(): int
    {
        DB::statement($this->argument('sql'));

        return self::SUCCESS;
    }
}
