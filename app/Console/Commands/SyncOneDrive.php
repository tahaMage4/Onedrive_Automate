<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\{Cache, Log, Storage};


class SyncOneDrive extends Command
{
   protected $signature = 'onedrive:sync
                            {--flashfiles : Only download .fls files from SharePoint folder}
                            {--list : List files in SharePoint folder without downloading}
                            {--force-refresh : Force refresh the access token before sync}
                            {--local-path=flashfiles : Local storage path relative to storage/app}
                            {--process : Process downloaded flash files into products}
                            {--batch-size=50 : Number of products to process at once}
                            {--delay=10 : Delay in seconds between batches}';

   protected $description = 'Sync files from SharePoint OneDrive to local storage';

   public function __construct(protected OneDriveService $drive)
   {
      parent::__construct();
   }

   public function handle(): int
   {
      if ($this->option('force-refresh')) {
         $this->info('Force refreshing access token...');
         if (!$this->drive->refreshAccessToken()) {
            $this->error('Failed to refresh access token.');
            $this->showAuthHelp();
            return Command::FAILURE;
         }
         $this->info('Access token refreshed successfully.');
      }

      // Check for valid access token
      $token = $this->drive->getValidAccessToken();

      if (!$token) {
         $this->error('No valid access token available.');
         $this->showAuthHelp();
         return Command::FAILURE;
      }

      if ($this->option('list')) {
         return $this->listSharePointFiles();
      } elseif ($this->option('flashfiles')) {
         return $this->downloadFlashFiles();
      } elseif ($this->option('process')) {
         return $this->processFlashFiles();
      } else {
         $this->error('Please specify an option:');
         $this->line('  --flashfiles  Download .fls files from SharePoint folder');
         $this->line('  --list        List files in SharePoint folder');
         $this->line('  --force-refresh Force refresh access token');
         $this->line('  --process     Process downloaded flash files into products');
         return Command::FAILURE;
      }
   }

   /**
    * List files in SharePoint folder
    */
   protected function listSharePointFiles(): int
   {
      $this->info('Listing files in SharePoint folders...');

      try {
         // Get data for MOD Flash folder
         $modFlashUrl = env('MOD_FILE_URL');
         $modFlashData = $this->drive->listSharePointFiles($modFlashUrl);

         // Get data for ORI Flash folder
         $oriFlashUrl = env('ORI_FILE_URL');
         $oriFlashData = $this->drive->listSharePointFiles($oriFlashUrl);

         // Combine both sets of files for processing
         $allFiles = array_merge(
            $modFlashData['value'] ?? $modFlashData,
            $oriFlashData['value'] ?? $oriFlashData
         );

         if (isset($allFiles['error'])) {
            $this->error('Error: ' . $allFiles['error']);
            return Command::FAILURE;
         }

         if (empty($allFiles)) {
            $this->info('No files found in the SharePoint folders.');
            return Command::SUCCESS;
         }

         // Get local file summary for comparison
         $localPath = $this->option('local-path');
         $localFiles = $this->drive->getLocalFileSummary($localPath);

         $this->info('Files found in SharePoint folders:');
         $this->newLine();

         $fileCount = 0;
         $folderCount = 0;
         $totalSize = 0;
         $flsFiles = [];
         $existingFiles = [];

         foreach ($allFiles as $file) {
            $icon = ($file['type'] ?? 'file') === 'folder' ? '📁' : '📄';
            $status = '';

            if (($file['type'] ?? 'file') === 'folder') {
               $this->line("  {$icon} {$file['name']} (folder - " . ($file['childCount'] ?: 0) . " items)");
               $folderCount++;
            } else {
               $size = $this->formatFileSize($file['size'] ?? 0);
               $modified = isset($file['lastModified']) ?
                  date('Y-m-d H:i:s', strtotime($file['lastModified'])) : 'Unknown';

               // Check if file exists locally
               $localExists = $this->checkLocalFileExists($file, $localPath);
               $status = $localExists ? '✔️ Exists locally' : '❌ Missing locally';

               $this->line("  {$icon} {$file['name']} ({$size}) - Modified: {$modified} - {$status}");
               $fileCount++;
               $totalSize += $file['size'] ?? 0;

               // Track .fls files
               if (preg_match('/\.fls$/i', $file['name'] ?? '')) {
                  $flsFiles[] = $file;
               }
            }
         }

         $this->newLine();
         $this->info("Summary: {$fileCount} files, {$folderCount} folders");
         $this->info("Total size: " . $this->formatFileSize($totalSize));

         if (!empty($flsFiles)) {
            $this->newLine();
            $this->info('.fls files found:');
            foreach ($flsFiles as $file) {
               $size = $this->formatFileSize($file['size'] ?? 0);
               $this->line("  🔧 {$file['name']} ({$size})");
            }
         }

         // Show local file summary
         $this->newLine();
         $this->info('Local file summary:');
         $this->line("  Path: storage/app/{$localPath}");
         $this->line("  Total files: {$localFiles['total_files']}");
         $this->line("  Total size: " . $this->formatFileSize($localFiles['total_size']));
         if ($localFiles['last_sync']) {
            $this->line("  Last sync: {$localFiles['last_sync']}");
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
      $localPath = $this->option('local-path');
      $this->info("Downloading .fls files from SharePoint folder to 'storage/app/{$localPath}'...");

      $sharePointUrl_MOD = env('MOD_FILE_URL');
      $sharePointUrl_ORI = env('ORI_FILE_URL');

      try {
         // Pass both URLs to the fetchFlashFiles method
         $result = $this->drive->fetchFlashFiles($localPath, [
            $sharePointUrl_MOD,
            $sharePointUrl_ORI
         ]);

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

            if (!empty($result['skipped'])) {
               $this->newLine();
               $this->info('Skipped files (already exist with same size):');
               foreach ($result['skipped'] as $file) {
                  $size = $this->formatFileSize($file['size']);
                  $this->line("  ⏭️ {$file['file']} ({$size})");
               }
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
    * Check if a file exists locally
    */
   protected function checkLocalFileExists(array $remoteFile, string $localPath): bool
   {
      // Determine which subfolder to check based on the URL
      $subfolder = strpos($remoteFile['name'] ?? '', 'MOD') !== false ? 'MOD' : 'ORI';
      $fullPath = "{$localPath}/{$subfolder}/{$remoteFile['name']}";

      return Storage::exists($fullPath) &&
         Storage::size($fullPath) === ($remoteFile['size'] ?? 0);
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
      $this->line('2. Visit: ' . config('app.url') . '/onedrive/login');
      $this->line('3. Complete the Microsoft authentication');
      $this->line('4. Run this command again');
      $this->newLine();
      $this->line('Or try: php artisan onedrive:sync --force-refresh');
   }


   // Add this new method to handle the processing (Product Processin)
   protected function processFlashFiles(): int
   {
      $this->info('Processing flash files into products...');

      $batchSize = (int)$this->option('batch-size');
      $delay = (int)$this->option('delay');

      try {
         $result = $this->drive->processFlashFiles(
            $this->option('local-path'),
            $batchSize,
            $delay
         );

         if ($result['success']) {
            $this->info(sprintf(
               "✅ Successfully processed flash files: %d categories, %d products created, %d updated, %d skipped",
               $result['created_categories'],
               $result['created_products'],
               $result['updated_products'],
               $result['skipped_products']
            ));

            if (!empty($result['errors'])) {
               $this->newLine();
               $this->warn('⚠️  Some files had processing errors:');
               foreach ($result['errors'] as $error) {
                  $this->warn("  • {$error}");
               }
            }

            return Command::SUCCESS;
         } else {
            $this->error('❌ Failed to process flash files');

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
         $this->error('❌ Error processing flash files: ' . $e->getMessage());
         Log::error('Flash files processing error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
         return Command::FAILURE;
      }
   }
}
