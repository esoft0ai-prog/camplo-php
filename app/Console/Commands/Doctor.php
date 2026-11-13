<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks a local install (PHP extensions, database, migrations, URL, folders) and says how to fix each problem.
 * Written for Windows/WAMP set-ups but works anywhere: php artisan camplo:doctor
 */
class Doctor extends Command
{
    protected $signature = 'camplo:doctor';

    protected $description = 'Check this Camplo install (PHP, database, migrations, URL, folders) and explain how to fix problems';

    private int $problems = 0;

    private function ok(string $what): void
    {
        $this->line("  <fg=green>OK</>    {$what}");
    }

    private function bad(string $what, string $fix): void
    {
        $this->problems++;
        $this->line("  <fg=red>FIX</>   {$what}");
        foreach (explode("\n", $fix) as $l) {
            $this->line("        → {$l}");
        }
    }

    private function note(string $what, string $note): void
    {
        $this->line("  <fg=yellow>NOTE</>  {$what}");
        $this->line("        → {$note}");
    }

    public function handle(): int
    {
        $ini = php_ini_loaded_file() ?: '(no php.ini loaded)';
        $driver = (string) config('database.default');
        $this->info('PHP '.PHP_VERSION." — command-line php.ini: {$ini}");

        // ---------------------------------------------------------------- PHP
        version_compare(PHP_VERSION, '8.3.0', '>=') ? $this->ok('PHP 8.3 or newer')
            : $this->bad('PHP '.PHP_VERSION.' is too old', 'Camplo needs PHP 8.3+. In WAMP: tray icon → PHP → Version → 8.3.x, and use that php.exe on the command line.');
        $pdo = in_array($driver, ['mysql', 'mariadb'], true) ? 'pdo_mysql' : 'pdo_pgsql';
        $need = [$pdo => 'database driver', 'mbstring' => 'text', 'openssl' => 'encryption', 'fileinfo' => 'uploads', 'zip' => 'page uploads (ZIP)',
            'curl' => 'outbound calls', 'ctype' => 'Laravel', 'tokenizer' => 'Laravel'];
        $missing = array_keys(array_filter($need, fn ($_, $ext) => ! extension_loaded($ext), ARRAY_FILTER_USE_BOTH));
        $missing ? $this->bad('Missing PHP extensions: '.implode(', ', $missing),
            "Open {$ini} and remove the ; in front of:\n".implode("\n", array_map(fn ($e) => "extension={$e}", $missing))."\nWAMP keeps a separate php.ini for Apache (phpForApache.ini) — this one is the command line's. Then open a new terminal.")
            : $this->ok('PHP extensions ('.implode(', ', array_keys($need)).')');

        // ---------------------------------------------------------------- .env and URL
        is_file(base_path('.env')) ? $this->ok('.env present') : $this->bad('No .env file', 'Copy .env.example to .env, then run: php artisan key:generate');
        config('app.key') ? $this->ok('APP_KEY set') : $this->bad('APP_KEY is empty', 'Run: php artisan key:generate');
        if (is_file(base_path('bootstrap/cache/config.php'))) {
            $this->bad('Configuration is cached, so .env changes are ignored', 'Run: php artisan config:clear');
        }
        $url = (string) config('app.url');
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $folder = basename(base_path());
        if ($path === '') {
            $this->ok("APP_URL {$url}");
        } elseif (strcasecmp(basename($path), $folder) === 0) {
            $this->ok("APP_URL {$url} (Apache serves this project folder, {$folder}, at /{$path})");
        } else {
            $this->bad("APP_URL ends in /{$path} but this project folder is called \"{$folder}\"", 'Apache serves a folder under its web root at /<folder name>. Either rename the folder to "'.basename($path)."\",\nor set APP_URL=".preg_replace('#/[^/]*$#', '', rtrim($url, '/'))."/{$folder}");
        }
        if (! config('camplo.encryption_key') && ! env('ENCRYPTION_KEY')) {
            $this->note('ENCRYPTION_KEY is empty', 'Fine for a quick local test (a development key is used). Set one before keeping real data; never change it afterwards.');
        }

        // ---------------------------------------------------------------- database
        $this->info("Database ({$driver})");
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->bad("The database connection is \"{$driver}\", which Camplo does not support",
                'Put DB_CONNECTION=mysql and your DB_* settings in '.base_path('.env')."\n(the file must be named exactly .env — not .env.txt), then run: php artisan config:clear\nIf migrate already ran on SQLite, run php artisan migrate:fresh afterwards.");
            $this->summary();

            return self::FAILURE;
        }
        try {
            DB::connection()->getPdo();
            $version = (string) DB::selectOne('select version() as v')->v;
            $this->ok('Connected to '.config("database.connections.{$driver}.host").':'.config("database.connections.{$driver}.port").'/'.config("database.connections.{$driver}.database")." — {$version}");
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $isMaria = stripos($version, 'mariadb') !== false;
                preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $v);
                $num = isset($v[3]) ? (int) $v[1] * 10000 + (int) $v[2] * 100 + (int) $v[3] : 0;
                if ($isMaria ? $num < 100600 : $num < 80013) {
                    $this->bad("Database version {$version} is too old", $isMaria ? 'Use MariaDB 10.6+ or MySQL 8.0.13+.' : 'Use MySQL 8.0.13 or newer (WAMP: tray icon → MySQL → Version).');
                }
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $fix = match (true) {
                str_contains($msg, 'could not find driver') => "PHP has no {$pdo} driver on the command line — see the extensions line above.",
                str_contains($msg, '1049') || str_contains($msg, 'Unknown database') || str_contains($msg, 'does not exist') => 'Create the database first, e.g. in phpMyAdmin: CREATE DATABASE '.config("database.connections.{$driver}.database").' CHARACTER SET utf8mb4;',
                str_contains($msg, '1045') || str_contains($msg, 'Access denied') || str_contains($msg, 'password authentication') => 'Wrong DB_USERNAME / DB_PASSWORD in .env.',
                str_contains($msg, '2002') || str_contains($msg, 'refused') || str_contains($msg, 'No connection') => 'The database server is not running on DB_HOST/DB_PORT. Start WAMP (icon green); MySQL normally uses port 3306, MariaDB 3307.',
                default => 'Check the DB_* settings in .env.',
            };
            $this->bad('Cannot connect: '.mb_substr($msg, 0, 200), $fix);
            $this->summary();

            return self::FAILURE;
        }
        if (! Schema::hasTable('migrations') || ! Schema::hasTable('tenants')) {
            $this->bad('Tables are missing', 'Run: php artisan migrate');
        } else {
            $pending = array_diff(array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php'))), DB::table('migrations')->pluck('migration')->all());
            $pending ? $this->bad('Migrations not run: '.implode(', ', $pending), 'Run: php artisan migrate') : $this->ok('All migrations have run');
            $n = DB::table('tenants')->count();
            $n ? $this->ok("{$n} workspace(s) in the database") : $this->note('No workspaces yet', 'Sign up in the app, or load the demo: php artisan camplo:seed-demo');
            if (Schema::hasTable('error_events') && ($last = DB::table('error_events')->whereNull('resolved_at')->orderByDesc('last_seen_at')->first())) {
                $this->note('Most recent server error ('.$last->last_seen_at.')', mb_substr($last->message, 0, 300).($last->route ? " [{$last->route}]" : ''));
            }
        }

        // ---------------------------------------------------------------- folders
        foreach (['storage/framework/cache', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $p = base_path($dir);
            if (! is_dir($p)) {
                @mkdir($p, 0775, true);
            }
            if (! is_dir($p) || ! is_writable($p)) {
                $this->bad("{$dir} is not writable", 'Give your user write access to the camplo-php folder (or move it out of a protected location such as Program Files).');
            }
        }
        $this->ok('storage/ and bootstrap/cache/ are writable');

        $this->summary();

        return $this->problems ? self::FAILURE : self::SUCCESS;
    }

    private function summary(): void
    {
        $this->newLine();
        $this->problems
            ? $this->error(" {$this->problems} problem(s) found — fix them in order and run php artisan camplo:doctor again. ")
            : $this->info('Everything looks good. Start the app with: php artisan serve  →  http://localhost:8000');
    }
}
