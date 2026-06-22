<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Log when scheduler runs (for debugging)
        $schedule->call(function () {
            Log::info('Scheduler is running at: ' . now());
            file_put_contents(storage_path('logs/cron-marker.txt'), now());
        })->everyMinute();

        // Process queue jobs every minute
        $schedule->command('queue:work --stop-when-empty --max-time=3600 --tries=3')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->before(function () {
                     Log::info('Starting queue worker...');
                 })
                 ->after(function () {
                     Log::info('Queue worker finished.');
                 });
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}