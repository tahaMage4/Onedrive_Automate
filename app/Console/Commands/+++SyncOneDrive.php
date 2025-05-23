<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\Cache;

class SyncOneDrive extends Command
{
    protected $signature = 'onedrive:sync
                           {--folder=/ : Folder path inside OneDrive to sync}
                           {--local=onedrive : Local directory path relative to storage/app}
                           {--flashfiles : Only download .fls files from MOD Flash and ORI Flash folders}';

    protected $description = 'Sync files from OneDrive to local storage';

    public function __construct(protected OneDriveService $drive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // $taha = $this->drive->getModFlashContents();
        // dd($taha);

        $this->drive->downloadSpecificFolders(['MOD Flash', 'ORI Flash'], 'storage/onedrive');

        $folder = $this->option('folder');
        $localPath = $this->option('local');
        $flashFilesOnly = $this->option('flashfiles');

        $token = Cache::get('onedrive_access_token');
        if (!$token) {
            $this->info('No access token cached. Attempting to refresh token...');

            if (!$this->drive->refreshAccessToken()) {
                $this->error('No valid access token available. Visit /onedrive/login to authenticate first.');
                $this->info('You can access the login page by running: php artisan serve');
                return Command::FAILURE;
            }

            $this->info('Token refreshed successfully.');
        }


        if ($flashFilesOnly) {
            $this->info("Downloading flash files from MOD Flash and ORI Flash folders to 'storage/app/flashfiles'...");
            $result = $this->drive->fetchFlashFiles('flashfiles');

            if ($result['success']) {
                $this->info($result['message']);
                $this->info('Downloaded files:');
                foreach ($result['downloaded'] as $file) {
                    $this->line(' - ' . $file['folder'] . '/' . $file['file'] . ' (' . number_format($file['size'] / 1024, 2) . ' KB)');
                }

                if (!empty($result['errors'])) {
                    $this->warn('Some files had errors:');
                    foreach ($result['errors'] as $error) {
                        $this->warn(' - ' . $error);
                    }
                }

                return Command::SUCCESS;
            } else {
                $this->error($result['message']);
                return Command::FAILURE;
            }
        } else {
            $this->info("Syncing OneDrive folder '{$folder}' to local path 'storage/app/{$localPath}'...");

            if ($this->drive->syncFiles($folder, $localPath)) {
                $this->info('OneDrive files synced successfully.');
                return Command::SUCCESS;
            } else {
                $this->error('Failed to sync OneDrive files. Check the logs for details.');
                return Command::FAILURE;
            }
        }
    }
}
