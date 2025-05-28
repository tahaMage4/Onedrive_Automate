<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
        \App\Console\Commands\SyncOneDrive::class,
    ];
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Full sync process - runs daily at 3am
        $schedule->command('onedrive:sync --flashfiles')
            ->dailyAt('03:20')
            ->name('OneDrive File Download')
            ->withoutOverlapping()
            ->then(function () use ($schedule) {
                // After files are downloaded, process them in batches
                $this->scheduleProductProcessing($schedule);
            });

        // Incremental sync - runs every hour
        $schedule->command('onedrive:sync --flashfiles')
            ->hourly()
            ->name('OneDrive Incremental Sync')
            ->withoutOverlapping();
    }

    /**
     * Schedule product processing in batches
     */
    protected function scheduleProductProcessing(Schedule $schedule): void
    {
        $schedule->command('onedrive:sync --process --batch-size=50 --delay=10')
            ->everyMinute()
            ->name('OneDrive Product Processing')
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
