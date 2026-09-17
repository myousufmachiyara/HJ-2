<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FIX (critical): this used to live in app/Console/kernel.php's schedule()
// method. That class is never bootstrapped on Laravel 11/12's bootstrap/app.php
// structure — Illuminate\Contracts\Console\Kernel is bound to the framework's
// own Illuminate\Foundation\Console\Kernel, not App\Console\Kernel, unless you
// explicitly wire it in. `php artisan schedule:list` proved it: only the two
// prune commands below ever showed up, never this one. That silently meant no
// queued job (Shopify sync included, since it dispatches to the default queue)
// was ever processed automatically, no matter how the OS-level cron/Task
// Scheduler entry calling `schedule:run` was configured — see
// App\Console\Commands\SetupScheduler for that OS-level half of the setup.
Schedule::call(function () {
    Log::info('Scheduler is running at: ' . now());
    file_put_contents(storage_path('logs/cron-marker.txt'), now());
})->everyMinute();

Schedule::command('queue:work --stop-when-empty --max-time=3600 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->before(fn () => Log::info('Starting queue worker...'))
    ->after(fn () => Log::info('Queue worker finished.'));

// Housekeeping.
Schedule::command('queue:prune-batches --hours=48 --unfinished=72')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();