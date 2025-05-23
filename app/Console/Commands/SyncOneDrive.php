<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncOneDrive extends Command
{
   protected $signature = 'onedrive:sync
                            {--flashfiles : Only download .fls files from SharePoint folder}
                            {--list : List files in SharePoint folder without downloading}
                            {--force-refresh : Force refresh the access token before sync}';

   protected $description = 'Sync files from SharePoint OneDrive to local storage';

   public function __construct(protected OneDriveService $drive)
   {
      parent::__construct();
   }

   public function handle(): int
   {
      // Check if user wants to force refresh token
      // if ($this->option('force-refresh')) {
      //    $this->info('Force refreshing access token...');
      //    if (!$this->drive->refreshAccessToken()) {
      //       $this->error('Failed to refresh access token.');
      //       return Command::FAILURE;
      //    }
      //    $this->info('Access token refreshed successfully.');
      // }

      // // Check for valid access token
      // $token = $this->drive->getValidAccessToken();

      // if (!$token) {
      //    $this->error('No valid access token available.');
      //    $this->info('Please visit /onedrive/login to authenticate first, or try running with --force-refresh');
      //    $this->info('You can access the login page by running: php artisan serve');
      //    return Command::FAILURE;
      // }

      if ($this->option('list')) {
         return $this->listSharePointFiles();
      } elseif ($this->option('flashfiles')) {
         return $this->downloadFlashFiles();
      } else {
         $this->error('Please specify an option:');
         $this->line('  --flashfiles  Download .fls files from SharePoint folder');
         $this->line('  --list        List files in SharePoint folder');
         $this->line('  --force-refresh Force refresh access token');
         return Command::FAILURE;
      }
   }

   /**
    * List files in SharePoint folder
    */
   protected function listSharePointFiles(): int
   {
      $this->info('Listing files in SharePoint folder...');

      $sharePointUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';

      try {
         $files = $this->drive->listSharePointFiles($sharePointUrl);

         if (isset($files['error'])) {
            $this->error('Error: ' . $files['error']);
            return Command::FAILURE;
         }

         if (empty($files)) {
            $this->info('No files found in the SharePoint folder.');
            return Command::SUCCESS;
         }

         $this->info('Files found in SharePoint folder:');
         $this->newLine();

         $fileCount = 0;
         $folderCount = 0;
         $totalSize = 0;

         foreach ($files as $file) {
            $icon = $file['type'] === 'folder' ? '📁' : '📄';

            if ($file['type'] === 'folder') {
               $this->line("  {$icon} {$file['name']} (folder - {$file['childCount']} items)");
               $folderCount++;
            } else {
               $size = $this->formatFileSize($file['size']);
               $modified = isset($file['lastModified']) ?
                  date('Y-m-d H:i:s', strtotime($file['lastModified'])) : 'Unknown';

               $this->line("  {$icon} {$file['name']} ({$size}) - Modified: {$modified}");
               $fileCount++;
               $totalSize += $file['size'];
            }
         }

         $this->newLine();
         $this->info("Summary: {$fileCount} files, {$folderCount} folders");
         $this->info("Total size: " . $this->formatFileSize($totalSize));

         // Show .fls files specifically
         $flsFiles = array_filter($files, function ($file) {
            return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
         });

         if (!empty($flsFiles)) {
            $this->newLine();
            $this->info('.fls files found:');
            foreach ($flsFiles as $file) {
               $size = $this->formatFileSize($file['size']);
               $this->line("  🔧 {$file['name']} ({$size})");
            }
         }

         return Command::SUCCESS;
      } catch (\Exception $e) {
         $this->error('Error listing SharePoint files: ' . $e->getMessage());
         Log::error('SharePoint listing error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
         return Command::FAILURE;
      }
   }

   /**
    * Download .fls files from SharePoint
    */
   protected function downloadFlashFiles(): int
   {
      $this->info("Downloading .fls files from SharePoint folder to 'storage/app/flashfiles'...");

      try {
         $result = $this->drive->fetchFlashFiles('flashfiles');

         if ($result['success']) {
            $this->info('✅ ' . $result['message']);

            if (!empty($result['downloaded'])) {
               $this->newLine();
               $this->info('Downloaded files:');

               $totalSize = 0;
               foreach ($result['downloaded'] as $file) {
                  $size = $this->formatFileSize($file['size']);
                  $this->line("  📄 {$file['file']} ({$size}) → {$file['path']}");
                  $totalSize += $file['size'];
               }

               $this->newLine();
               $this->info('Total downloaded: ' . count($result['downloaded']) . ' files (' . $this->formatFileSize($totalSize) . ')');
            }

            if (!empty($result['errors'])) {
               $this->newLine();
               $this->warn('⚠️  Some files had errors:');
               foreach ($result['errors'] as $error) {
                  $this->warn("  • {$error}");
               }
            }

            return Command::SUCCESS;
         } else {
            $this->error('❌ ' . $result['message']);

            if (!empty($result['errors'])) {
               $this->newLine();
               $this->error('Errors encountered:');
               foreach ($result['errors'] as $error) {
                  $this->error("  • {$error}");
               }
            }

            return Command::FAILURE;
         }
      } catch (\Exception $e) {
         $this->error('❌ Error downloading files: ' . $e->getMessage());
         Log::error('SharePoint download error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
         return Command::FAILURE;
      }
   }

   /**
    * Format file size in human-readable format
    */
   protected function formatFileSize(int $bytes): string
   {
      if ($bytes === 0) {
         return '0 B';
      }

      $units = ['B', 'KB', 'MB', 'GB', 'TB'];
      $unitIndex = 0;

      while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
         $bytes /= 1024;
         $unitIndex++;
      }

      return round($bytes, 2) . ' ' . $units[$unitIndex];
   }

   /**
    * Display authentication help
    */
   protected function showAuthHelp(): void
   {
      $this->newLine();
      $this->info('Authentication Help:');
      $this->line('1. Start your Laravel server: php artisan serve');
      $this->line('2. Visit: http://localhost:8000/onedrive/login');
      $this->line('3. Complete the Microsoft authentication');
      $this->line('4. Run this command again');
      $this->newLine();
      $this->line('Or try: php artisan onedrive:sync --force-refresh');
   }
}
