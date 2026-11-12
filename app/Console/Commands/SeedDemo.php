<?php

namespace App\Console\Commands;

use App\Services\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedDemo extends Command
{
    protected $signature = 'camplo:seed-demo {--force : Seed even if SEED_DEMO is false}';

    protected $description = 'Create the demo workspace (Northbeam Growth) when the database has no workspaces';

    public function handle(): int
    {
        if (! config('camplo.seed_demo') && ! $this->option('force')) {
            $this->line('SEED_DEMO is false — skipped.');

            return self::SUCCESS;
        }
        if (DB::table('tenants')->exists()) {
            $this->line('Database already has workspaces — skipped.');

            return self::SUCCESS;
        }
        DemoSeeder::run();
        $this->info('Demo workspace created: '.DemoSeeder::EMAIL.' / '.DemoSeeder::password());

        return self::SUCCESS;
    }
}
