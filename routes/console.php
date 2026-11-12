<?php

use Illuminate\Support\Facades\Schedule;

// Every time-based rule (SLA alerts, digests, webhook health, early warning, cleanup) — idempotent.
Schedule::command('camplo:sweep')->everyMinute()->withoutOverlapping(10);
