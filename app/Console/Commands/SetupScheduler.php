<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The Shopify sync job (and the app's other queued jobs on the "default"
 * queue) only run because routes/console.php schedules
 * `queue:work --stop-when-empty` every minute. That scheduler itself does
 * nothing unless something calls `php artisan schedule:run` every minute —
 * on Windows/XAMPP that "something" is a Task Scheduler entry, which
 * doesn't exist until you create it. This command prints the exact command
 * to run (or checks whether it's already ticking).
 */
class SetupScheduler extends Command
{
    protected $signature = 'app:setup-scheduler {--check : Only report whether the scheduler appears to be running}';

    protected $description = 'Print (or verify) the OS-level task that must run "php artisan schedule:run" every minute';

    public function handle(): int
    {
        if ($this->option('check')) {
            return $this->checkStatus();
        }

        $phpBinary = PHP_BINARY;
        $artisan   = base_path('artisan');

        $this->info('This Laravel scheduler (app/Console/kernel.php) drives the Shopify sync queue.');
        $this->line('It only runs if something calls "schedule:run" every minute. Set that up once:');
        $this->newLine();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->line('<comment>Windows (matches your start-worker.bat / C:\xampp setup) — run in an elevated Command Prompt:</comment>');
            $this->newLine();
            $this->line("  schtasks /create /tn \"Laravel Scheduler - HJ-2\" /tr \"\\\"{$phpBinary}\\\" \\\"{$artisan}\\\" schedule:run\" /sc minute /mo 1 /ru SYSTEM /f");
            $this->newLine();
            $this->line('This registers a task that fires every minute, whether or not anyone is logged in.');
            $this->line('Confirm it in: Task Scheduler → Task Scheduler Library → "Laravel Scheduler - HJ-2".');
        } else {
            $this->line('<comment>Linux/macOS — add this line via `crontab -e`:</comment>');
            $this->newLine();
            $this->line("  * * * * * cd " . base_path() . " && {$phpBinary} artisan schedule:run >> /dev/null 2>&1");
        }

        $this->newLine();
        $this->line('After it has run for a couple of minutes, verify with:');
        $this->line('  php artisan app:setup-scheduler --check');
        $this->newLine();
        $this->line('<comment>Note:</comment> this is separate from start-worker.bat, which you still need to run');
        $this->line('manually whenever you do a bulk CSV product import (that job uses the dedicated');
        $this->line('"imports" queue, not the scheduler-driven default queue that Shopify sync uses).');

        return self::SUCCESS;
    }

    private function checkStatus(): int
    {
        $marker = storage_path('logs/cron-marker.txt');

        if (!file_exists($marker)) {
            $this->error('No cron-marker.txt yet — the scheduler has never run. Set up the OS task first (run this command without --check).');
            return self::FAILURE;
        }

        $lastRun = trim(file_get_contents($marker));

        try {
            $age = now()->diffInSeconds(\Illuminate\Support\Carbon::parse($lastRun));
        } catch (\Throwable) {
            $this->error("Couldn't parse the marker's timestamp: {$lastRun}");
            return self::FAILURE;
        }

        if ($age > 180) {
            $this->error("Scheduler last ran {$age}s ago (marker: {$lastRun}) — that's stale. Check the OS task is still enabled.");
            return self::FAILURE;
        }

        $this->info("Scheduler is alive — last tick {$age}s ago ({$lastRun}).");
        return self::SUCCESS;
    }
}
