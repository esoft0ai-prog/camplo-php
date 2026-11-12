<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Camplo schema, exactly as the Node version shipped it (database/sql/*.sql, Postgres only), so an existing
 * Camplo database keeps working. Each file runs once; files already applied by the Node app are skipped
 * (see the `drizzle.__drizzle_migrations` check below). New schema changes go in new Laravel migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (glob(database_path('sql/*.sql')) as $file) {
            if ($this->appliedByNode(basename($file, '.sql'))) {
                continue;
            }
            foreach (preg_split('/-->\s*statement-breakpoint/', file_get_contents($file)) as $stmt) {
                if (trim($stmt) !== '') {
                    DB::unprepared($stmt);
                }
            }
        }
    }

    /** A database the Node app already migrated has the tables; only run files it has not applied. */
    private function appliedByNode(string $name): bool
    {
        $exists = DB::selectOne("select to_regclass('drizzle.__drizzle_migrations') as t")->t ?? null;
        if (! $exists) {
            return false;
        }
        $journal = json_decode((string) @file_get_contents(database_path('sql/meta/_journal.json')), true);
        $entry = collect($journal['entries'] ?? [])->firstWhere('tag', $name);
        if (! $entry) {
            return false;
        }

        return (bool) DB::selectOne('select 1 as x from drizzle.__drizzle_migrations where created_at = ?', [$entry['when']]);
    }

    public function down(): void
    {
        DB::unprepared('drop schema public cascade; create schema public;');
    }
};
