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
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException("Camplo needs MySQL 8 / MariaDB 10.6+ or PostgreSQL, but this connection is {$driver}. "
                .'Set DB_CONNECTION=mysql (and DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD) in .env, then run: php artisan config:clear && php artisan migrate:fresh');
        }
        $mysql = in_array($driver, ['mysql', 'mariadb'], true);
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
