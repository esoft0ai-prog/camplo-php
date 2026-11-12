<?php

namespace App\Console\Commands;

use App\Services\Sweeps;
use Illuminate\Console\Command;

class Sweep extends Command
{
    protected $signature = 'camplo:sweep';

    protected $description = 'Run every time-based rule once (SLA alerts, digests, webhook health, early warning, cleanup)';

    public function handle(): int
    {
        $this->line(json_encode(Sweeps::run()));

        return self::SUCCESS;
    }
}
