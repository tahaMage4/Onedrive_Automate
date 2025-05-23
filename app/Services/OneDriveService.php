<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use ZipArchive;
use Carbon\Carbon;

class OneDriveService
{
   protected string $clientId;
   protected string $clientSecret;
   protected string $redirectUri;
   protected string $authority = 'https://login.microsoftonline.com/common';
   protected string $graphApiBase = 'https://graph.microsoft.com/v1.0';

   public function __construct()
   {
      $this->clientId = config('services.onedrive.client_id');
      $this->clientSecret = config('services.onedrive.client_secret');
      // $this->tenantId = config('services.onedrive.tenant_id');
      $this->redirectUri = config('services.onedrive.redirect_uri');
   }

   /**
    * Get authorization URL for OAuth
    */
   public function getAuthUrl(): string
   {
      $params = [
         'client_id' => $this->clientId,
         'response_type' => 'code',
         'redirect_uri' => $this->redirectUri,
         'scope' => 'https://graph.microsoft.com/Files.ReadWrite.All offline_access',
         'response_mode' => 'query'
      ];

      return $this->authority . '/oauth2/v2.0/authorize?' . http_build_query($params);
   }

   /**
    * Exchange authorization code for access token
    */
   public function getAccessToken(string $code): array
   {
      $response = Http::asForm()->post($this->authority . '/oauth2/v2.0/token', [
         'client_id' => $this->clientId,
         'client_secret' => $this->clientSecret,
         'code' => $code,
         'redirect_uri' => $this->redirectUri,
         'grant_type' => 'authorization_code',
      ]);

      if ($response->successful()) {
         $tokenData = $response->json();

         // Store tokens in cache
         Cache::put('onedrive_access_token', $tokenData['access_token'], now()->addSeconds($tokenData['expires_in'] - 300));
         Cache::put('onedrive_refresh_token', $tokenData['refresh_token'], now()->addDays(90));

         return $tokenData;
      }

      throw new \Exception('Failed to get access token: ' . $response->body());
   }

   /**
    * Refresh access token using refresh token
    */
   public function refreshAccessToken(): bool
   {
      $refreshToken = Cache::get('onedrive_refresh_token');

      if (!$refreshToken) {
         return false;
      }

      $response = Http::asForm()->post($this->authority . '/oauth2/v2.0/token', [
         'client_id' => $this->clientId,
         'client_secret' => $this->clientSecret,
         'refresh_token' => $refreshToken,
         'grant_type' => 'refresh_token',
      ]);

      if ($response->successful()) {
         $tokenData = $response->json();

         Cache::put('onedrive_access_token', $tokenData['access_token'], now()->addSeconds($tokenData['expires_in'] - 300));

         if (isset($tokenData['refresh_token'])) {
            Cache::put('onedrive_refresh_token', $tokenData['refresh_token'], now()->addDays(90));
         }

         return true;
      }

      return false;
   }

   /**
    * Get valid access token (refresh if needed)
    */
   public function getValidAccessToken(): ?string
   {
      $token = Cache::get('onedrive_access_token');

      if ($token) {
         return $token;
      }

      if ($this->refreshAccessToken()) {
         return Cache::get('onedrive_access_token');
      }

      return null;
   }

   /**
    * Test connection to OneDrive
    */
   public function testConnection(): array
   {
      $token = $this->getValidAccessToken();

      if (!$token) {
         return ['error' => 'No valid access token available'];
      }

      $response = Http::withToken($token)->get($this->graphApiBase . '/me');

      if ($response->successful()) {
         $user = $response->json();
         return [
            'success' => true,
            'user' => $user['displayName'] ?? 'Unknown',
            'email' => $user['mail'] ?? $user['userPrincipalName'] ?? 'Unknown'
         ];
      }

      return ['error' => 'Failed to connect to OneDrive: ' . $response->status()];
   }

   /**
    * Extract SharePoint site and folder information from URL
    */
   protected function parseSharePointUrl(string $url): array
   {
      // Extract site ID and folder path from SharePoint URL
      if (preg_match('/https:\/\/([^\/]+)-my\.sharepoint\.com\/.*\/personal\/([^\/]+)\/.*\/([^\/\?]+)/', $url, $matches)) {
         $tenant = $matches[1];
         $user = $matches[2];

         return [
            'tenant' => $tenant,
            'user' => $user,
            'site_url' => "https://{$tenant}-my.sharepoint.com/personal/{$user}",
            'type' => 'personal'
         ];
      }

      throw new \Exception('Could not parse SharePoint URL');
   }

   /**
    * List files in SharePoint folder
    */
   public function listSharePointFiles(string $sharePointUrl): array
   {
      $token = $this->getValidAccessToken();

      if (!$token) {
         return ['error' => 'No valid access token available'];
      }

      try {
         $parsedUrl = $this->parseSharePointUrl($sharePointUrl);

         // Get site information
         $siteResponse = Http::withToken($token)
            ->get($this->graphApiBase . "/sites/{$parsedUrl['tenant']}-my.sharepoint.com:/personal/{$parsedUrl['user']}");

         if (!$siteResponse->successful()) {
            return ['error' => 'Failed to access SharePoint site: ' . $siteResponse->status()];
         }

         $siteId = $siteResponse->json()['id'];

         // Get drive
         $driveResponse = Http::withToken($token)
            ->get($this->graphApiBase . "/sites/{$siteId}/drive");

         if (!$driveResponse->successful()) {
            return ['error' => 'Failed to access drive: ' . $driveResponse->status()];
         }

         $driveId = $driveResponse->json()['id'];

         // List root folder contents
         $filesResponse = Http::withToken($token)
            ->get($this->graphApiBase . "/sites/{$siteId}/drives/{$driveId}/root/children");

         if (!$filesResponse->successful()) {
            return ['error' => 'Failed to list files: ' . $filesResponse->status()];
         }

         $items = $filesResponse->json()['value'] ?? [];
         $files = [];

         foreach ($items as $item) {
            $files[] = [
               'id' => $item['id'],
               'name' => $item['name'],
               'type' => isset($item['folder']) ? 'folder' : 'file',
               'size' => $item['size'] ?? 0,
               'lastModified' => $item['lastModifiedDateTime'] ?? null,
               'downloadUrl' => $item['@microsoft.graph.downloadUrl'] ?? null,
               'childCount' => $item['folder']['childCount'] ?? 0,
               'webUrl' => $item['webUrl'] ?? null
            ];
         }

         return $files;
      } catch (\Exception $e) {
         Log::error('SharePoint listing error: ' . $e->getMessage());
         return ['error' => $e->getMessage()];
      }
   }

   /**
    * Get files from a specific folder with delta sync support
    */
   public function getFolderContents(string $sharePointUrl, string $folderName = null, string $deltaToken = null): array
   {
      $token = $this->getValidAccessToken();

      if (!$token) {
         return ['error' => 'No valid access token available'];
      }

      try {
         $parsedUrl = $this->parseSharePointUrl($sharePointUrl);

         // Get site and drive IDs
         $siteResponse = Http::withToken($token)
            ->get($this->graphApiBase . "/sites/{$parsedUrl['tenant']}-my.sharepoint.com:/personal/{$parsedUrl['user']}");

         if (!$siteResponse->successful()) {
            return ['error' => 'Failed to access SharePoint site'];
         }

         $siteId = $siteResponse->json()['id'];

         $driveResponse = Http::withToken($token)
            ->get($this->graphApiBase . "/sites/{$siteId}/drive");

         if (!$driveResponse->successful()) {
            return ['error' => 'Failed to access drive'];
         }

         $driveId = $driveResponse->json()['id'];

         // Build the endpoint URL
         $endpoint = $this->graphApiBase . "/sites/{$siteId}/drives/{$driveId}/root";

         if ($folderName) {
            $endpoint .= ":/{$folderName}:";
         }

         // Use delta endpoint if delta token is provided
         if ($deltaToken) {
            $endpoint .= "/delta?token=" . urlencode($deltaToken);
         } else {
            $endpoint .= "/children";
         }

         $response = Http::withToken($token)->get($endpoint);

         if (!$response->successful()) {
            return ['error' => 'Failed to get folder contents: ' . $response->status()];
         }

         $data = $response->json();
         $items = $data['value'] ?? [];
         $files = [];

         foreach ($items as $item) {
            // Skip deleted items in delta sync
            if (isset($item['deleted'])) {
               continue;
            }

            $files[] = [
               'id' => $item['id'],
               'name' => $item['name'],
               'type' => isset($item['folder']) ? 'folder' : 'file',
               'size' => $item['size'] ?? 0,
               'lastModified' => $item['lastModifiedDateTime'] ?? null,
               'downloadUrl' => $item['@microsoft.graph.downloadUrl'] ?? null,
               'eTag' => $item['eTag'] ?? null,
               'webUrl' => $item['webUrl'] ?? null
            ];
         }

         return [
            'files' => $files,
            'deltaToken' => $data['@odata.deltaLink'] ?? null,
            'nextLink' => $data['@odata.nextLink'] ?? null
         ];
      } catch (\Exception $e) {
         Log::error('Folder contents error: ' . $e->getMessage());
         return ['error' => $e->getMessage()];
      }
   }

   /**
    * Download multiple files as ZIP
    */
   public function downloadFilesAsZip(array $files, string $zipName): array
   {
      $token = $this->getValidAccessToken();

      if (!$token) {
         return ['success' => false, 'error' => 'No valid access token available'];
      }

      $zipPath = storage_path("app/temp/{$zipName}.zip");
      $tempDir = storage_path('app/temp');

      // Ensure temp directory exists
      if (!is_dir($tempDir)) {
         mkdir($tempDir, 0755, true);
      }

      $zip = new ZipArchive();
      $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

      if ($result !== TRUE) {
         return ['success' => false, 'error' => 'Could not create ZIP file'];
      }

      $downloadedFiles = [];
      $errors = [];

      foreach ($files as $file) {
         if ($file['type'] === 'file' && !empty($file['downloadUrl'])) {
            try {
               $response = Http::timeout(300)->get($file['downloadUrl']);

               if ($response->successful()) {
                  $zip->addFromString($file['name'], $response->body());
                  $downloadedFiles[] = $file['name'];
                  Log::info("Added {$file['name']} to ZIP");
               } else {
                  $errors[] = "Failed to download {$file['name']}: HTTP {$response->status()}";
               }
            } catch (\Exception $e) {
               $errors[] = "Error downloading {$file['name']}: " . $e->getMessage();
            }
         }
      }

      $zip->close();

      if (empty($downloadedFiles)) {
         @unlink($zipPath);
         return [
            'success' => false,
            'error' => 'No files were added to ZIP',
            'errors' => $errors
         ];
      }

      return [
         'success' => true,
         'zipPath' => $zipPath,
         'filesCount' => count($downloadedFiles),
         'downloadedFiles' => $downloadedFiles,
         'errors' => $errors
      ];
   }

   /**
    * Extract ZIP file to specified directory
    */
   public function extractZipToDirectory(string $zipPath, string $extractPath): array
   {
      if (!file_exists($zipPath)) {
         return ['success' => false, 'error' => 'ZIP file not found'];
      }

      // Ensure extract directory exists
      if (!is_dir($extractPath)) {
         mkdir($extractPath, 0755, true);
      }

      $zip = new ZipArchive();
      $result = $zip->open($zipPath);

      if ($result !== TRUE) {
         return ['success' => false, 'error' => 'Could not open ZIP file'];
      }

      $extractedFiles = [];

      for ($i = 0; $i < $zip->numFiles; $i++) {
         $filename = $zip->getNameIndex($i);
         $fileInfo = $zip->statIndex($i);

         if ($zip->extractTo($extractPath, $filename)) {
            $extractedFiles[] = [
               'name' => $filename,
               'size' => $fileInfo['size'],
               'path' => $extractPath . '/' . $filename
            ];
         }
      }

      $zip->close();

      // Clean up ZIP file
      @unlink($zipPath);

      return [
         'success' => true,
         'extractedFiles' => $extractedFiles,
         'totalFiles' => count($extractedFiles)
      ];
   }

   /**
    * Enhanced sync with ZIP download and delta sync
    */
   public function fetchFlashFiles(string $localPath = 'flashfiles'): array
   {
      $sharePointUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';

      $result = [
         'success' => false,
         'message' => '',
         'mod_files' => null,
         'ori_files' => null,
         'errors' => []
      ];

      try {
         // Get MOD folder files
         $modResult = $this->syncFolderWithZip($sharePointUrl, 'MOD', 'mod-files');
         if ($modResult['success']) {
            $result['mod_files'] = $modResult;
         } else {
            $result['errors'][] = 'MOD folder sync failed: ' . ($modResult['error'] ?? 'Unknown error');
         }

         // Get ORI folder files
         $oriResult = $this->syncFolderWithZip($sharePointUrl, 'ORI', 'ori-files');
         if ($oriResult['success']) {
            $result['ori_files'] = $oriResult;
         } else {
            $result['errors'][] = 'ORI folder sync failed: ' . ($oriResult['error'] ?? 'Unknown error');
         }

         // Determine overall success
         $result['success'] = !empty($result['mod_files']) || !empty($result['ori_files']);

         if ($result['success']) {
            $result['message'] = 'Files synchronized successfully with ZIP optimization';
         } else {
            $result['message'] = 'Sync failed for all folders';
         }
      } catch (\Exception $e) {
         $result['errors'][] = 'General sync error: ' . $e->getMessage();
         $result['message'] = 'Sync failed with exception';
         Log::error('Flash files sync error: ' . $e->getMessage());
      }

      return $result;
   }

   /**
    * Sync specific folder with ZIP optimization and delta sync
    */
   protected function syncFolderWithZip(string $sharePointUrl, string $folderName, string $localDir): array
   {
      // Get stored delta token for this folder
      $deltaTokenKey = "onedrive_delta_{$folderName}";
      $deltaToken = Cache::get($deltaTokenKey);

      // Get folder contents (with delta if available)
      $folderData = $this->getFolderContents($sharePointUrl, $folderName, $deltaToken);

      if (isset($folderData['error'])) {
         return ['success' => false, 'error' => $folderData['error']];
      }

      $files = $folderData['files'] ?? [];
      $newDeltaToken = $folderData['deltaToken'];

      // Filter for flash files (.fls)
      $flashFiles = array_filter($files, function ($file) {
         return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
      });

      if (empty($flashFiles)) {
         return [
            'success' => true,
            'files_count' => 0,
            'is_incremental' => !empty($deltaToken),
            'message' => "No .fls files found in {$folderName} folder"
         ];
      }

      // Download files as ZIP
      $zipResult = $this->downloadFilesAsZip($flashFiles, "{$folderName}_files_" . date('Y-m-d_H-i-s'));

      if (!$zipResult['success']) {
         return ['success' => false, 'error' => $zipResult['error'] ?? 'ZIP download failed'];
      }

      // Extract ZIP to local directory
      $extractPath = storage_path("app/{$localDir}");
      $extractResult = $this->extractZipToDirectory($zipResult['zipPath'], $extractPath);

      if (!$extractResult['success']) {
         return ['success' => false, 'error' => $extractResult['error'] ?? 'ZIP extraction failed'];
      }

      // Store new delta token for future incremental syncs
      if ($newDeltaToken) {
         Cache::put($deltaTokenKey, $newDeltaToken, now()->addDays(30));
      }

      // Update sync statistics
      $this->updateSyncStats($folderName, count($flashFiles), $extractResult['totalFiles']);

      return [
         'success' => true,
         'files_count' => $extractResult['totalFiles'],
         'is_incremental' => !empty($deltaToken),
         'extracted_files' => $extractResult['extractedFiles'],
         'errors' => $zipResult['errors'] ?? []
      ];
   }

   /**
    * Force full sync by clearing delta tokens
    */
   public function forceFullSync(): array
   {
      // Clear delta tokens to force full sync
      Cache::forget('onedrive_delta_MOD');
      Cache::forget('onedrive_delta_ORI');

      Log::info('Delta tokens cleared, forcing full sync');

      return $this->fetchFlashFiles();
   }

   /**
    * Get sync status and statistics
    */
   public function getSyncStatus(): array
   {
      return [
         'last_mod_sync' => Cache::get('last_sync_MOD'),
         'last_ori_sync' => Cache::get('last_sync_ORI'),
         'mod_files_count' => Cache::get('sync_stats_MOD_count', 0),
         'ori_files_count' => Cache::get('sync_stats_ORI_count', 0),
         'has_mod_delta' => Cache::has('onedrive_delta_MOD'),
         'has_ori_delta' => Cache::has('onedrive_delta_ORI'),
         'token_expires' => Cache::get('onedrive_access_token') ? 'Valid' : 'Expired'
      ];
   }

   /**
    * Update sync statistics
    */
   protected function updateSyncStats(string $folder, int $totalFiles, int $syncedFiles): void
   {
      $timestamp = now()->toISOString();
      Cache::put("last_sync_{$folder}", $timestamp, now()->addDays(30));
      Cache::put("sync_stats_{$folder}_count", $syncedFiles, now()->addDays(30));
      Cache::put("sync_stats_{$folder}_total", $totalFiles, now()->addDays(30));
   }

   /**
    * Legacy method for backward compatibility
    */
   public function downloadFlashFolders(string $localPath = 'flashfiles'): array
   {
      return $this->fetchFlashFiles($localPath);
   }
}
