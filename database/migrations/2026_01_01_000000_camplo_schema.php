<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Camplo schema, exactly as the Node version shipped it (database/sql/*.sql for PostgreSQL), so an existing
 * Camplo database keeps working. On MySQL the generated equivalents in database/sql/mysql/ run instead
 * (database/tools/pg2mysql.php). Each file runs once; on PostgreSQL, files the Node app already applied are skipped
 * (see the `drizzle.__drizzle_migrations` check below). New schema changes go in new Laravel migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        foreach (glob(database_path($mysql ? 'sql/mysql/*.sql' : 'sql/*.sql')) as $file) {
            if (! $mysql && $this->appliedByNode(basename($file, '.sql'))) {
                continue;
            }
            foreach (preg_split('/-->\s*statement-breakpoint/', file_get_contents($file)) as $stmt) {
                $stmt = trim(preg_replace('/^--[^\n]*\n/m', '', $stmt));
                if ($stmt !== '') {
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
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::dropAllTables();

            return;
        }
        DB::unprepared('drop schema public cascade; create schema public;');
    }
};
