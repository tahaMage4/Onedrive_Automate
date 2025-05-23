<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncOneDrive extends Command
{
   protected $signature = 'onedrive:sync
                            {--zip : Download files as ZIP archives (default behavior)}
                            {--individual : Download files individually (legacy mode)}
                            {--list : List files in SharePoint folder without downloading}
                            {--force-full : Force full sync, ignoring delta tokens}
                            {--force-refresh : Force refresh the access token before sync}
                            {--folder= : Sync specific folder only (MOD or ORI)}
                            {--stats : Show sync statistics}
                            {--report : Show detailed sync report}';

   protected $description = 'Sync files from SharePoint OneDrive to local storage with ZIP optimization and delta sync';

   public function __construct(protected OneDriveService $drive)
   {
      parent::__construct();
   }

   public function handle(): int
   {
      // Show stats if requested
      if ($this->option('stats')) {
         return $this->showSyncStats();
      }

      // Show detailed report if requested
      if ($this->option('report')) {
         return $this->showSyncReport();
      }

      // Force refresh token if requested
      if ($this->option('force-refresh')) {
         $this->info('🔄 Force refreshing access token...');
         if (!$this->drive->refreshAccessToken()) {
            $this->error('❌ Failed to refresh access token.');
            $this->showAuthHelp();
            return Command::FAILURE;
         }
         $this->info('✅ Access token refreshed successfully.');
      }

      // Check for valid access token
      $token = $this->drive->getValidAccessToken();
      if (!$token) {
         $this->error('❌ No valid access token available.');
         $this->showAuthHelp();
         return Command::FAILURE;
      }

      // Handle different sync modes
      if ($this->option('list')) {
         return $this->listSharePointFiles();
      } elseif ($this->option('individual')) {
         return $this->downloadIndividualFiles();
      } else {
         // Default to ZIP sync
         return $this->downloadWithZip();
      }
   }

   /**
    * Download files using ZIP optimization with delta sync
    */
   protected function downloadWithZip(): int
   {
      $this->info('🚀 Starting ZIP-optimized sync with delta support...');
      $this->newLine();

      $forceFullSync = $this->option('force-full');
      $specificFolder = $this->option('folder');

      if ($forceFullSync) {
         $this->warn('⚠️  Force full sync enabled - delta tokens will be ignored');
      }

      if ($specificFolder && !in_array(strtoupper($specificFolder), ['MOD', 'ORI'])) {
         $this->error('❌ Invalid folder specified. Use MOD or ORI.');
         return Command::FAILURE;
      }

      try {
         if ($specificFolder) {
            $result = $this->syncSpecificFolder(strtoupper($specificFolder), $forceFullSync);
         } else {
            if ($forceFullSync) {
               $result = $this->drive->forceFullSync();
            } else {
               $result = $this->drive->fetchFlashFiles();
            }
         }

         if ($result['success']) {
            $this->displaySyncResults($result, $forceFullSync);
            return Command::SUCCESS;
         } else {
            $this->displaySyncErrors($result);
            return Command::FAILURE;
         }
      } catch (\Exception $e) {
         $this->error('❌ Sync failed with exception: ' . $e->getMessage());
         Log::error('Command sync error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
         return Command::FAILURE;
      }
   }

   /**
    * Sync specific folder only
    */
   protected function syncSpecificFolder(string $folder, bool $forceFullSync): array
   {
      $this->info("📁 Syncing {$folder} folder only...");

      $sharePointUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';

      if ($forceFullSync) {
         $deltaTokenKey = "onedrive_delta_{$folder}";
         Cache::forget($deltaTokenKey);
         $this->line("🔄 Cleared delta token for {$folder}");
      }

      // Get folder contents
      $deltaToken = $forceFullSync ? null : Cache::get("onedrive_delta_{$folder}");
      $folderData = $this->drive->getFolderContents($sharePointUrl, $folder, $deltaToken);

      if (isset($folderData['error'])) {
         return [
            'success' => false,
            'message' => "Failed to get {$folder} folder contents",
            'errors' => [$folderData['error']]
         ];
      }

      $files = $folderData['files'] ?? [];
      $flashFiles = array_filter($files, function ($file) {
         return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
      });

      if (empty($flashFiles)) {
         return [
            'success' => true,
            'message' => "No .fls files found in {$folder} folder",
            $folder . '_files' => [
               'success' => true,
               'files_count' => 0,
               'is_incremental' => !$forceFullSync
            ]
         ];
      }

      // Download as ZIP and extract
      $zipResult = $this->drive->downloadFilesAsZip($flashFiles, "{$folder}_files_" . date('Y-m-d_H-i-s'));

      if (!$zipResult['success']) {
         return [
            'success' => false,
            'message' => "Failed to create ZIP for {$folder} folder",
            'errors' => [$zipResult['error'] ?? 'Unknown ZIP error']
         ];
      }

      $localDir = strtolower($folder) . '-files';
      $extractPath = storage_path("app/{$localDir}");
      $extractResult = $this->drive->extractZipToDirectory($zipResult['zipPath'], $extractPath);

      if (!$extractResult['success']) {
         return [
            'success' => false,
            'message' => "Failed to extract ZIP for {$folder} folder",
            'errors' => [$extractResult['error'] ?? 'Unknown extraction error']
         ];
      }

      // Store delta token
      if ($folderData['deltaToken']) {
         Cache::put("onedrive_delta_{$folder}", $folderData['deltaToken'], now()->addDays(30));
      }

      return [
         'success' => true,
         'message' => "{$folder} folder synced successfully",
         $folder . '_files' => [
            'success' => true,
            'files_count' => $extractResult['totalFiles'],
            'is_incremental' => !$forceFullSync && !empty($deltaToken),
            'extracted_files' => $extractResult['extractedFiles']
         ]
      ];
   }

   /**
    * Display sync results
    */
   protected function displaySyncResults(array $result, bool $wasForced): void
   {
      $this->info('✅ ' . $result['message']);
      $this->newLine();

      $totalFiles = 0;
      $folders = ['MOD' => 'mod_files', 'ORI' => 'ori_files'];

      foreach ($folders as $folderName => $resultKey) {
         if (!empty($result[$resultKey]) && $result[$resultKey]['success']) {
            $folderResult = $result[$resultKey];
            $syncType = ($folderResult['is_incremental'] && !$wasForced) ? 'incremental' : 'full';
            $fileCount = $folderResult['files_count'];

            $this->line("📁 {$folderName} folder: {$fileCount} files ({$syncType} sync)");

            if (!empty($folderResult['extracted_files'])) {
               $this->line("   📍 Saved to: storage/app/" . strtolower($folderName) . "-files/");

               if ($this->option('verbose')) {
                  foreach (array_slice($folderResult['extracted_files'], 0, 5) as $file) {
                     $size = $this->formatFileSize($file['size']);
                     $this->line("      📄 {$file['name']} ({$size})");
                  }

                  if (count($folderResult['extracted_files']) > 5) {
                     $remaining = count($folderResult['extracted_files']) - 5;
                     $this->line("      ... and {$remaining} more files");
                  }
               }
            }

            $totalFiles += $fileCount;
         }
      }

      if ($totalFiles > 0) {
         $this->newLine();
         $this->info("📊 Total files synced: {$totalFiles}");

         if ($wasForced) {
            $this->warn("🔄 Full sync was forced - all delta tokens were reset");
         }
      }

      // Show any errors
      if (!empty($result['errors'])) {
         $this->newLine();
         $this->warn('⚠️  Some issues occurred:');
         foreach ($result['errors'] as $error) {
            $this->warn("   • {$error}");
         }
      }
   }

   /**
    * Display sync errors
    */
   protected function displaySyncErrors(array $result): void
   {
      $this->error('❌ ' . ($result['message'] ?? 'Sync failed'));

      if (!empty($result['errors'])) {
         $this->newLine();
         $this->error('Errors encountered:');
         foreach ($result['errors'] as $error) {
            $this->error("  • {$error}");
         }
      }
   }

   /**
    * Download files individually (legacy mode)
    */
   protected function downloadIndividualFiles(): int
   {
      $this->warn('⚠️  Using legacy individual file download mode');
      $this->info("📥 Downloading .fls files individually to 'storage/app/flashfiles'...");

      try {
         $result = $this->drive->downloadFlashFolders('flashfiles');

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
    * List files in SharePoint folder
    */
   protected function listSharePointFiles(): int
   {
      $this->info('📋 Listing files in SharePoint folders...');
      $this->newLine();

      $sharePointUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';

      try {
         $folders = ['MOD', 'ORI'];

         foreach ($folders as $folder) {
            $this->info("📁 {$folder} Folder:");

            $folderData = $this->drive->getFolderContents($sharePointUrl, $folder);

            if (isset($folderData['error'])) {
               $this->error("   ❌ Error: {$folderData['error']}");
               continue;
            }

            $files = $folderData['files'] ?? [];
            $flashFiles = array_filter($files, function ($file) {
               return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
            });

            if (empty($flashFiles)) {
               $this->line('   📭 No .fls files found');
            } else {
               $totalSize = 0;

               foreach ($flashFiles as $file) {
                  $size = $this->formatFileSize($file['size']);
                  $modified = isset($file['lastModified']) ?
                     date('Y-m-d H:i:s', strtotime($file['lastModified'])) : 'Unknown';

                  $this->line("   📄 {$file['name']} ({$size}) - Modified: {$modified}");
                  $totalSize += $file['size'];
               }

               $this->line("   📊 Total: " . count($flashFiles) . " files (" . $this->formatFileSize($totalSize) . ")");
            }

            $this->newLine();
         }

         return Command::SUCCESS;
      } catch (\Exception $e) {
         $this->error('❌ Error listing SharePoint files: ' . $e->getMessage());
         Log::error('SharePoint listing error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
         return Command::FAILURE;
      }
   }

   /**
    * Show sync statistics
    */
   protected function showSyncStats(): int
   {
      $this->info('📊 OneDrive Sync Statistics');
      $this->newLine();

      try {
         $stats = $this->drive->getSyncStatus();

         // Connection status
         $this->line('🔗 Connection Status: ' . ($stats['token_expires'] === 'Valid' ? '✅ Connected' : '❌ Token Expired'));
         $this->newLine();

         // Last sync times
         $this->line('🕐 Last Sync Times:');
         $this->line('   MOD: ' . ($stats['last_mod_sync'] ? date('Y-m-d H:i:s', strtotime($stats['last_mod_sync'])) : 'Never'));
         $this->line('   ORI: ' . ($stats['last_ori_sync'] ? date('Y-m-d H:i:s', strtotime($stats['last_ori_sync'])) : 'Never'));
         $this->newLine();

         // File counts
         $this->line('📄 File Counts:');
         $this->line('   MOD files: ' . $stats['mod_files_count']);
         $this->line('   ORI files: ' . $stats['ori_files_count']);
         $this->newLine();

         // Delta sync status
         $this->line('🔄 Delta Sync Status:');
         $this->line('   MOD delta token: ' . ($stats['has_mod_delta'] ? '✅ Available' : '❌ Not available'));
         $this->line('   ORI delta token: ' . ($stats['has_ori_delta'] ? '✅ Available' : '❌ Not available'));

         return Command::SUCCESS;
      } catch (\Exception $e) {
         $this->error('❌ Failed to get sync statistics: ' . $e->getMessage());
         return Command::FAILURE;
      }
   }

   /**
    * Show detailed sync report
    */
   protected function showSyncReport(): int
   {
      $this->info('📋 Detailed Sync Report');
      $this->newLine();

      try {
         $stats = $this->drive->getSyncStatus();

         // Check local file counts
         $modLocalPath = storage_path('app/mod-files');
         $oriLocalPath = storage_path('app/ori-files');

         $modLocalCount = 0;
         $oriLocalCount = 0;
         $modLocalSize = 0;
         $oriLocalSize = 0;

         if (is_dir($modLocalPath)) {
            $modFiles = glob($modLocalPath . '/*.{fls,FLS}', GLOB_BRACE);
            $modLocalCount = count($modFiles);
            foreach ($modFiles as $file) {
               if (is_file($file)) {
                  $modLocalSize += filesize($file);
               }
            }
         }

         if (is_dir($oriLocalPath)) {
            $oriFiles = glob($oriLocalPath . '/*.{fls,FLS}', GLOB_BRACE);
            $oriLocalCount = count($oriFiles);
            foreach ($oriFiles as $file) {
               if (is_file($file)) {
                  $oriLocalSize += filesize($file);
               }
            }
         }

         // Display comprehensive report
         $this->table(
            ['Metric', 'MOD', 'ORI'],
            [
               [
                  'Last Sync',
                  $stats['last_mod_sync'] ? date('Y-m-d H:i:s', strtotime($stats['last_mod_sync'])) : 'Never',
                  $stats['last_ori_sync'] ? date('Y-m-d H:i:s', strtotime($stats['last_ori_sync'])) : 'Never'
               ],
               ['Local Files', $modLocalCount, $oriLocalCount],
               ['Local Size', $this->formatFileSize($modLocalSize), $this->formatFileSize($oriLocalSize)],
               ['Delta Token', $stats['has_mod_delta'] ? '✅' : '❌', $stats['has_ori_delta'] ? '✅' : '❌'],
               ['Storage Path', 'storage/app/mod-files', 'storage/app/ori-files']
            ]
         );

         $this->newLine();
         $this->line('🔗 Token Status: ' . ($stats['token_expires'] === 'Valid' ? '✅ Valid' : '❌ Expired'));
         $this->line('📁 Total Local Files: ' . ($modLocalCount + $oriLocalCount));
         $this->line('💾 Total Local Size: ' . $this->formatFileSize($modLocalSize + $oriLocalSize));

         return Command::SUCCESS;
      } catch (\Exception $e) {
         $this->error('❌ Failed to generate sync report: ' . $e->getMessage());
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
      $this->info('🔐 Authentication Help:');
      $this->line('1. Start your Laravel server: php artisan serve');
      $this->line('2. Visit: http://localhost:8000/onedrive/login');
      $this->line('3. Complete the Microsoft authentication');
      $this->line('4. Run this command again');
      $this->newLine();
      $this->line('Or try: php artisan onedrive:sync --force-refresh');
   }
}
