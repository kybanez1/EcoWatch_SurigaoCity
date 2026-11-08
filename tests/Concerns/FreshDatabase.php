<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Gives every test an empty, freshly migrated database.
 *
 * - SQLite (default, in memory): same as RefreshDatabase.
 * - MySQL: runs migrate:fresh before every test, so row ids start at 1 again and the real MySQL
 *   rules (foreign keys, column sizes, strict mode) are tested.
 *
 * migrate:fresh DROPS ALL TABLES, so a MySQL test run is refused unless the database name contains "test".
 */
trait FreshDatabase
{
    protected function setUpFreshDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql' && ! str_contains(strtolower($connection->getDatabaseName()), 'test')) {
            $this->fail('Refusing to wipe the database "' . $connection->getDatabaseName() . '". Use a database whose name contains "test", for example ecowatch_test.');
        }
        $this->artisan('migrate:fresh');
    }
}
