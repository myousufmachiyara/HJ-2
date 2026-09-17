<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * NOT USED. Kept only so nothing that references the class name breaks.
 *
 * This app's bootstrap/app.php uses the Laravel 11/12 bootstrap-file
 * structure, which binds Illuminate\Contracts\Console\Kernel straight to
 * the framework's own Illuminate\Foundation\Console\Kernel — never to this
 * class — unless you explicitly wire it in (which bootstrap/app.php here
 * does not). `schedule()` below was therefore dead code: it never ran,
 * regardless of how any OS-level cron/Task Scheduler entry was configured.
 *
 * The real schedule now lives in routes/console.php (the framework-native
 * location for Laravel 11+), registered via the Schedule facade. Add any
 * new scheduled task there, not here.
 */
class Kernel extends ConsoleKernel
{
    //
}
