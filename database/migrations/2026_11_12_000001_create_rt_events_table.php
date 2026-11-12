<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-time events for the browser's EventSource channels. The Node app kept these in memory; PHP serves each
 * request in its own process, so events are written here and read by GET /api/rt/workspace/{name}.
 * Rows older than a few minutes are pruned by the minute sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rt_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('tenant_id');
            $t->string('channel', 120);
            $t->jsonb('payload')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'channel', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rt_events');
    }
};
