<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Housekeeping only — the import worker runs from its own dedicated cron above.
Schedule::command('queue:prune-batches --hours=48 --unfinished=72')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();