<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Models\DriveItem;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OneDriveService
{
   protected string $clientId;
   protected string $clientSecret;
   protected string $tenantId;
   protected string $redirectUri;
   protected ?string $accessToken = null;
   protected $graph = null;

   public function __construct()
   {
      $this->clientId = config('services.onedrive.client_id');
      $this->clientSecret = config('services.onedrive.client_secret');
      $this->tenantId = config('services.onedrive.tenant_id', 'common');
      $this->redirectUri = config('services.onedrive.redirect_uri');
   }

   /**
    * Get Microsoft OAuth2 authorization URL for SharePoint access
    */
   public function getAuthUrl(): string
   {
      // $scopes = 'offline_access https://graph.microsoft.com/Sites.Read.All https://graph.microsoft.com/Files.Read.All https://graph.microsoft.com/User.Read';
      $scopes = 'offline_access https://graph.microsoft.com/.default';
      return 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/authorize?' . http_build_query([
         'client_id' => $this->clientId,
         'response_type' => 'code',
         'redirect_uri' => $this->redirectUri,
         'response_mode' => 'query',
         'scope' => $scopes,
      ]);
   }

   /**
    * Cache token data
    */
   protected function cacheTokenData(array $tokenData): void
   {
      $expiresIn = $tokenData['expires_in'] ?? 3600;

      Cache::put('onedrive_access_token', $tokenData['access_token'], now()->addSeconds($expiresIn - 300));

      if (isset($tokenData['refresh_token'])) {
         Cache::put('onedrive_refresh_token', $tokenData['refresh_token'], now()->addDays(30));
      }

      $this->accessToken = $tokenData['access_token'];
   }

   /**
    * Get access token from authorization code
    */
   public function getAccessToken(string $authCode): array
   {
      $client = new Client();
      try {
         $response = $client->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'form_params' => [
               'client_id' => $this->clientId,
               'client_secret' => $this->clientSecret,
               'code' => $authCode,
               'grant_type' => 'authorization_code', //  client_credentials
               'redirect_uri' => $this->redirectUri,
               // 'scope' => 'offline_access https://graph.microsoft.com/Sites.Read.All https://graph.microsoft.com/Files.Read.All https://graph.microsoft.com/User.Read',
               'scope' => 'https://graph.microsoft.com/.default',
            ],
         ]);

         $tokenData = json_decode((string) $response->getBody(), true);
         $this->cacheTokenData($tokenData);

         return $tokenData;
      } catch (\GuzzleHttp\Exception\ClientException $e) {
         $response = $e->getResponse();
         $responseBody = $response->getBody()->getContents();
         Log::error('Token request failed: ' . $responseBody);
         throw new \Exception('Failed to get access token: ' . $responseBody);
      }
   }

   /**
    * Refresh the access token using the refresh token
    */
   public function refreshAccessToken(): bool
   {
      $refreshToken = Cache::get('onedrive_refresh_token');

      if (!$refreshToken) {
         Log::error('No refresh token available. User needs to login again.');
         return false;
      }

      try {
         $client = new Client();
         $response = $client->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'form_params' => [
               'client_id' => $this->clientId,
               'client_secret' => $this->clientSecret,
               'grant_type' => 'refresh_token',
               'refresh_token' => $refreshToken,
               // 'scope' => 'offline_access https://graph.microsoft.com/Sites.Read.All https://graph.microsoft.com/Files.Read.All https://graph.microsoft.com/User.Read',
               'scope' => 'https://graph.microsoft.com/.default',
            ],
         ]);

         $tokenData = json_decode((string) $response->getBody(), true);
         $this->cacheTokenData($tokenData);

         return true;
      } catch (\Exception $e) {
         Log::error('Failed to refresh access token: ' . $e->getMessage());
         Cache::forget('onedrive_access_token');
         Cache::forget('onedrive_refresh_token');
         return false;
      }
   }

   /**
    * Get a valid access token
    */
   public function getValidAccessToken(): ?string
   {
      $token = Cache::get('onedrive_access_token');

      if (!$token && !$this->refreshAccessToken()) {
         return null;
      }

      return Cache::get('onedrive_access_token');
   }

   /**
    * Initialize access token for requests
    */
   protected function initializeAuth(): bool
   {
      $this->accessToken = $this->getValidAccessToken();

      if (!$this->accessToken) {
         Log::error('No valid access token available');
         return false;
      }

      return true;
   }

   /**
    * Parse SharePoint sharing URL to extract encoded sharing information
    */
   protected function parseSharePointUrl(string $url): ?array
   {
      if (preg_match('/https:\/\/([^\/]+)-my\.sharepoint\.com\/:f:\/g\/personal\/([^\/]+)\/([^?]+)/', $url, $matches)) {
         $tenantName = $matches[1];
         $userPath = $matches[2];
         $resourceId = $matches[3];

         return [
            'tenantName' => $tenantName,
            'userPath' => $userPath,
            'resourceId' => $resourceId,
            'siteUrl' => "https://{$tenantName}-my.sharepoint.com/personal/{$userPath}",
            'hostname' => "{$tenantName}-my.sharepoint.com",
            'sitePath' => "/personal/{$userPath}"
         ];
      }

      Log::error('Could not parse SharePoint URL: ' . $url);
      return null;
   }

   /**
    * Make authenticated Graph API request
    */
   protected function makeGraphRequest(string $endpoint): ?array
   {
      if (!$this->accessToken) {
         Log::error('No access token available for Graph request');
         return null;
      }

      try {
         $client = new Client();
         $response = $client->get("https://graph.microsoft.com/v1.0/{$endpoint}", [
            'headers' => [
               'Authorization' => 'Bearer ' . $this->accessToken,
               'Accept' => 'application/json',
            ],
         ]);

         return json_decode($response->getBody()->getContents(), true);
      } catch (\Exception $e) {
         Log::error("Graph API request failed for {$endpoint}: " . $e->getMessage());

         if (strpos($e->getMessage(), '401') !== false) {
            if ($this->refreshAccessToken()) {
               return $this->makeGraphRequest($endpoint);
            }
         }

         return null;
      }
   }

   /**
    * Get site information from SharePoint URL
    */
   protected function getSiteInfo(string $hostname, string $sitePath): ?array
   {
      $sitePath = ltrim($sitePath, '/');
      $endpoint = "sites/{$hostname}:/{$sitePath}";
      Log::info("Requesting site info from endpoint: {$endpoint}");
      return $this->makeGraphRequest($endpoint);
   }

   /**
    * Get shared item from sharing URL
    */
   protected function getSharedItem(string $shareUrl): ?array
   {
      try {
         $encodedUrl = base64_encode($shareUrl);
         $encodedUrl = rtrim($encodedUrl, '=');
         $encodedUrl = 'u!' . strtr($encodedUrl, '+/', '-_');

         $endpoint = "shares/{$encodedUrl}/driveItem";
         Log::info("Requesting shared item from endpoint: {$endpoint}");

         return $this->makeGraphRequest($endpoint);
      } catch (\Exception $e) {
         Log::error('Error getting shared item: ' . $e->getMessage());
         return null;
      }
   }

   /**
    * Get folder contents from SharePoint
    */
   public function getSharePointFolderContents(string $sharePointUrl): ?array
   {
      if (!$this->initializeAuth()) {
         return null;
      }

      try {
         $sharedItem = $this->getSharedItem($sharePointUrl);

         if ($sharedItem && isset($sharedItem['id'])) {
            Log::info("Successfully accessed shared item: " . $sharedItem['name']);

            $encodedUrl = base64_encode($sharePointUrl);
            $encodedUrl = rtrim($encodedUrl, '=');
            $encodedUrl = 'u!' . strtr($encodedUrl, '+/', '-_');

            $endpoint = "shares/{$encodedUrl}/driveItem/children";
            $items = $this->makeGraphRequest($endpoint);

            return $items['value'] ?? [];
         }

         // Fallback method
         $parsedUrl = $this->parseSharePointUrl($sharePointUrl);
         if (!$parsedUrl) {
            return null;
         }

         $siteInfo = $this->getSiteInfo($parsedUrl['hostname'], $parsedUrl['sitePath']);
         if (!$siteInfo) {
            Log::error('Could not get site information');
            return null;
         }

         Log::info("Site ID: " . $siteInfo['id']);
         $siteId = $siteInfo['id'];

         $driveEndpoint = "sites/{$siteId}/drive";
         $driveInfo = $this->makeGraphRequest($driveEndpoint);

         if (!$driveInfo) {
            Log::error('Could not get drive information');
            return null;
         }

         Log::info("Drive ID: " . $driveInfo['id']);

         $itemsEndpoint = "sites/{$siteId}/drive/root/children";
         $items = $this->makeGraphRequest($itemsEndpoint);

         return $items['value'] ?? [];
      } catch (\Exception $e) {
         Log::error('Error getting SharePoint folder contents: ' . $e->getMessage());
         return null;
      }
   }

   /**
    * Check which files are already present locally
    */
   protected function getExistingLocalFiles(string $localPath): array
   {
      $existingFiles = [];

      try {
         if (Storage::exists($localPath)) {
            $files = Storage::allFiles($localPath);

            foreach ($files as $file) {
               $fileName = basename($file);
               $filePath = Storage::path($file);
               $fileSize = file_exists($filePath) ? filesize($filePath) : 0;

               $existingFiles[$fileName] = [
                  'path' => $file,
                  'size' => $fileSize,
                  'name' => $fileName
               ];
            }
         }
      } catch (\Exception $e) {
         Log::error("Error checking existing files in {$localPath}: " . $e->getMessage());
      }

      Log::info("Found " . count($existingFiles) . " existing files in {$localPath}");
      return $existingFiles;
   }

   /**
    * Check if file needs to be downloaded (missing or different size)
    */
   protected function shouldDownloadFile(array $remoteFile, array $existingFiles): bool
   {
      $fileName = $remoteFile['name'];

      // If file doesn't exist locally, download it
      if (!isset($existingFiles[$fileName])) {
         return true;
      }

      // Check file size difference (basic integrity check)
      $remoteSize = $remoteFile['size'] ?? 0;
      $localSize = $existingFiles[$fileName]['size'] ?? 0;

      if ($remoteSize !== $localSize) {
         Log::info("File size mismatch for {$fileName}: remote={$remoteSize}, local={$localSize}");
         return true;
      }

      // File exists and sizes match, skip download
      return false;
   }

   /**
    * Download files from SharePoint folder with incremental sync
    */
   public function downloadFlashFolders(string $localPath, string $shareUrl): array
   {
      $result = [
         'success' => false,
         'downloaded' => [],
         'skipped' => [],
         'errors' => [],
         'message' => ''
      ];

      if (!$this->initializeAuth()) {
         $result['errors'][] = 'Failed to initialize authentication';
         $result['message'] = 'Authentication error: No valid access token available';
         return $result;
      }

      // Get existing local files
      $existingFiles = $this->getExistingLocalFiles($localPath);

      // Get folder contents from SharePoint
      $items = $this->getSharePointFolderContents($shareUrl);

      if (!$items) {
         $result['errors'][] = 'Could not access SharePoint folder';
         $result['message'] = 'Failed to retrieve folder contents from SharePoint';
         return $result;
      }

      Log::info("Found " . count($items) . " items in SharePoint folder");

      // Process items
      foreach ($items as $item) {
         if (isset($item['file']) && preg_match('/\.(fls|FLS)$/i', $item['name'])) {
            if ($this->shouldDownloadFile($item, $existingFiles)) {
               $this->downloadFileFromSharePoint($item, $localPath, $result);
            } else {
               $result['skipped'][] = [
                  'file' => $item['name'],
                  'reason' => 'File already exists with same size',
                  'size' => $item['size'] ?? 0
               ];
               Log::info("Skipped {$item['name']} - already exists");
            }
         } elseif (isset($item['folder'])) {
            $this->processSharePointSubfolder($item, $localPath, $result, $existingFiles);
         }
      }

      $totalProcessed = count($result['downloaded']) + count($result['skipped']);
      $result['success'] = $totalProcessed > 0;

      if ($result['success']) {
         $downloadedCount = count($result['downloaded']);
         $skippedCount = count($result['skipped']);
         $result['message'] = "Processed {$totalProcessed} files: {$downloadedCount} downloaded, {$skippedCount} skipped";
      } else {
         $result['message'] = 'No .fls files found to process';
      }

      return $result;
   }

   /**
    * Download a single file from SharePoint
    */
   protected function downloadFileFromSharePoint(array $item, string $localBasePath, array &$result): void
   {
      try {
         $fileName = $item['name'];
         $downloadUrl = $item['@microsoft.graph.downloadUrl'] ?? null;

         if (!$downloadUrl && isset($item['id'])) {
            $fileEndpoint = "me/drive/items/{$item['id']}/content";

            try {
               $client = new Client();
               $response = $client->get("https://graph.microsoft.com/v1.0/{$fileEndpoint}", [
                  'headers' => [
                     'Authorization' => 'Bearer ' . $this->accessToken,
                  ],
                  'allow_redirects' => false
               ]);

               $downloadUrl = $response->getHeader('Location')[0] ?? null;
            } catch (\Exception $e) {
               Log::error("Error getting download URL for {$fileName}: " . $e->getMessage());
            }
         }

         if ($downloadUrl) {
            $client = new Client();
            $response = $client->get($downloadUrl);

            $filePath = "{$localBasePath}/{$fileName}";
            Storage::put($filePath, $response->getBody()->getContents());

            $result['downloaded'][] = [
               'file' => $fileName,
               'size' => $item['size'] ?? 0,
               'path' => $filePath
            ];

            Log::info("Downloaded {$filePath}");
         } else {
            $result['errors'][] = "Could not get download URL for {$fileName}";
         }
      } catch (\Exception $e) {
         $result['errors'][] = "Error downloading {$item['name']}: " . $e->getMessage();
         Log::error("Error downloading {$item['name']}: " . $e->getMessage());
      }
   }

   /**
    * Process SharePoint subfolders with incremental sync
    */
   protected function processSharePointSubfolder(array $folder, string $localBasePath, array &$result, array $existingFiles = []): void
   {
      try {
         $folderName = $folder['name'];
         $folderPath = "{$localBasePath}/{$folderName}";

         if (!Storage::exists($folderPath)) {
            Storage::makeDirectory($folderPath);
         }

         // Get existing files in subfolder
         $subfolderExistingFiles = $this->getExistingLocalFiles($folderPath);

         if (isset($folder['id'])) {
            $subfolderEndpoint = "me/drive/items/{$folder['id']}/children";
            $subItems = $this->makeGraphRequest($subfolderEndpoint);

            if ($subItems && isset($subItems['value'])) {
               foreach ($subItems['value'] as $subItem) {
                  if (isset($subItem['file']) && preg_match('/\.(fls|FLS)$/i', $subItem['name'])) {
                     if ($this->shouldDownloadFile($subItem, $subfolderExistingFiles)) {
                        $this->downloadFileFromSharePoint($subItem, $folderPath, $result);
                     } else {
                        $result['skipped'][] = [
                           'file' => $subItem['name'],
                           'reason' => 'File already exists in subfolder',
                           'size' => $subItem['size'] ?? 0,
                           'folder' => $folderName
                        ];
                     }
                  }
               }
            }
         }
      } catch (\Exception $e) {
         Log::error("Error processing subfolder {$folder['name']}: " . $e->getMessage());
      }
   }

   /**
    * Get all files from the SharePoint folder and organize them into MOD/ORI subfolders with incremental sync
    */
   public function fetchFlashFiles(string $localBasePath = 'flashfiles', array $shareUrls = []): array
   {
      $result = [
         'success' => false,
         'downloaded' => [],
         'skipped' => [],
         'errors' => [],
         'message' => 'No URLs provided'
      ];

      if (empty($shareUrls)) {
         return $result;
      }

      // Ensure base directory exists
      if (!Storage::exists($localBasePath)) {
         Storage::makeDirectory($localBasePath);
      }

      // Create MOD and ORI subdirectories if they don't exist
      $modPath = $localBasePath . '/MOD';
      $oriPath = $localBasePath . '/ORI';

      if (!Storage::exists($modPath)) {
         Storage::makeDirectory($modPath);
      }

      if (!Storage::exists($oriPath)) {
         Storage::makeDirectory($oriPath);
      }

      // Process each URL
      foreach ($shareUrls as $index => $url) {
         $targetSubfolder = ($index === 0) ? $modPath : $oriPath;
         $folderType = ($index === 0) ? 'MOD' : 'ORI';

         Log::info("Processing {$folderType} folder: {$url}");

         $downloadResult = $this->downloadFlashFolders($targetSubfolder, $url);

         // Merge results
         $result['downloaded'] = array_merge($result['downloaded'], $downloadResult['downloaded'] ?? []);
         $result['skipped'] = array_merge($result['skipped'], $downloadResult['skipped'] ?? []);
         $result['errors'] = array_merge($result['errors'], $downloadResult['errors'] ?? []);
      }

      $totalDownloaded = count($result['downloaded']);
      $totalSkipped = count($result['skipped']);
      $totalProcessed = $totalDownloaded + $totalSkipped;

      $result['success'] = $totalProcessed > 0;
      $result['message'] = $result['success']
         ? "Processed {$totalProcessed} files: {$totalDownloaded} downloaded, {$totalSkipped} skipped"
         : 'No .fls files found to process';

      return $result;
   }

   /**
    * List files in SharePoint folder without downloading
    */
   public function listSharePointFiles(string $sharePointUrl): array
   {
      if (!$this->initializeAuth()) {
         return ['error' => 'Failed to initialize authentication'];
      }

      $items = $this->getSharePointFolderContents($sharePointUrl);

      if (!$items) {
         return ['error' => 'Could not access SharePoint folder'];
      }

      $fileList = [];
      foreach ($items as $item) {
         if (isset($item['file'])) {
            $fileList[] = [
               'name' => $item['name'],
               'size' => $item['size'] ?? 0,
               'lastModified' => $item['lastModifiedDateTime'] ?? null,
               'type' => 'file'
            ];
         } elseif (isset($item['folder'])) {
            $fileList[] = [
               'name' => $item['name'],
               'type' => 'folder',
               'childCount' => $item['folder']['childCount'] ?? 0
            ];
         }
      }

      return $fileList;
   }

   /**
    * Debug method to test authentication and basic Graph API access
    */
   public function testConnection(): array
   {
      if (!$this->initializeAuth()) {
         return ['error' => 'Authentication failed'];
      }

      $userInfo = $this->makeGraphRequest('me');

      if ($userInfo) {
         return [
            'success' => true,
            'user' => $userInfo['displayName'] ?? 'Unknown',
            'email' => $userInfo['mail'] ?? $userInfo['userPrincipalName'] ?? 'Unknown'
         ];
      }

      return ['error' => 'Could not access Graph API'];
   }

   /**
    * Get local file summary for status display
    */
   public function getLocalFileSummary(string $localBasePath = 'flashfiles'): array
   {
      $summary = [
         'total_files' => 0,
         'total_size' => 0,
         'folders' => [],
         'last_sync' => null
      ];

      try {
         if (!Storage::exists($localBasePath)) {
            return $summary;
         }

         // Check MOD folder
         $modPath = $localBasePath . '/MOD';
         if (Storage::exists($modPath)) {
            $modFiles = Storage::allFiles($modPath);
            $modSize = 0;
            foreach ($modFiles as $file) {
               $filePath = Storage::path($file);
               if (file_exists($filePath)) {
                  $modSize += filesize($filePath);
               }
            }
            $summary['folders']['MOD'] = [
               'count' => count($modFiles),
               'size' => $modSize
            ];
         }

         // Check ORI folder
         $oriPath = $localBasePath . '/ORI';
         if (Storage::exists($oriPath)) {
            $oriFiles = Storage::allFiles($oriPath);
            $oriSize = 0;
            foreach ($oriFiles as $file) {
               $filePath = Storage::path($file);
               if (file_exists($filePath)) {
                  $oriSize += filesize($filePath);
               }
            }
            $summary['folders']['ORI'] = [
               'count' => count($oriFiles),
               'size' => $oriSize
            ];
         }

         $summary['total_files'] = ($summary['folders']['MOD']['count'] ?? 0) +
            ($summary['folders']['ORI']['count'] ?? 0);
         $summary['total_size'] = ($summary['folders']['MOD']['size'] ?? 0) +
            ($summary['folders']['ORI']['size'] ?? 0);

         // Get last sync time from cache or file timestamp
         $summary['last_sync'] = Cache::get('onedrive_last_sync');
      } catch (\Exception $e) {
         Log::error("Error getting local file summary: " . $e->getMessage());
      }

      return $summary;
   }

   // Existing methods remain the same...
   public function getFolderByUrl(string $url): array
   {
      if (strpos($url, 'sharepoint.com') !== false) {
         return $this->listSharePointFiles($url);
      }

      if (strpos($url, 'onedrive.live.com') !== false) {
         return ['error' => 'Personal OneDrive URLs not supported yet'];
      }

      return ['error' => 'Unsupported URL format'];
   }

   public function downloadSpecificFolders(array $folderNames, string $localBasePath): bool
   {
      $sharePointUrl_MOD = env('MOD_FILE_URL');
      $sharePointUrl_ORI = env('ORI_FILE_URL');

      $success = true;
      foreach ($folderNames as $folderName) {
         try {
            Log::info("Processing folder: {$folderName}");

            if ($folderName === 'MOD Flash') {
               $result = $this->fetchFlashFiles($localBasePath . '/' . strtolower(str_replace(' ', '_', $folderName)), [$sharePointUrl_MOD]);
               if (!$result['success']) {
                  $success = false;
               }
            } else if ($folderName === 'ORIGINAL Flash') {
               $result = $this->fetchFlashFiles($localBasePath . '/' . strtolower(str_replace(' ', '_', $folderName)), [$sharePointUrl_ORI]);
               if (!$result['success']) {
                  $success = false;
               }
            }
         } catch (\Exception $e) {
            Log::error("Error processing folder {$folderName}: " . $e->getMessage());
            $success = false;
         }
      }

      return $success;
   }

   // Product Create

   /**
    * Process files from the flashfiles folder with category and product management
    */
   // public function processFlashFiles(string $localBasePath = 'flashfiles'): array
   // {
   //    $result = [
   //       'success' => false,
   //       'created_categories' => 0,
   //       'created_products' => 0,
   //       'updated_products' => 0,
   //       'skipped_products' => 0,
   //       'errors' => [],
   //    ];

   //    try {
   //       if (!Storage::exists($localBasePath)) {
   //          $result['errors'][] = "Base directory {$localBasePath} does not exist";
   //          return $result;
   //       }

   //       // Get all subdirectories in flashfiles
   //       $subdirectories = Storage::directories($localBasePath);
   //       $allFiles = [];

   //       foreach ($subdirectories as $subdirectory) {
   //          $folderName = basename($subdirectory);

   //          // Get or create category
   //          $category = $this->getOrCreateFlashCategory($folderName);
   //          if (!$category) {
   //             $result['errors'][] = "Failed to create category for {$folderName}";
   //             continue;
   //          }

   //          // Process files in the subdirectory
   //          $files = Storage::allFiles($subdirectory);

   //          foreach ($files as $file) {
   //             $this->processFlashFile($file, $category, $result);
   //          }
   //       }

   //       $result['success'] = true;
   //       return $result;
   //    } catch (\Exception $e) {
   //       Log::error("Error processing flash files: " . $e->getMessage());
   //       $result['errors'][] = "System error: " . $e->getMessage();
   //       return $result;
   //    }
   // }

   public function processFlashFiles(string $localBasePath = 'flashfiles', int $batchSize = 50, int $delay = 10): array
   {
      $result = [
         'success' => false,
         'created_categories' => 0,
         'created_products' => 0,
         'updated_products' => 0,
         'skipped_products' => 0,
         'errors' => [],
      ];

      try {
         if (!Storage::exists($localBasePath)) {
            $result['errors'][] = "Base directory {$localBasePath} does not exist";
            return $result;
         }

         // Get all subdirectories in flashfiles
         $subdirectories = Storage::directories($localBasePath);
         $processedCount = 0;

         foreach ($subdirectories as $subdirectory) {
            $folderName = basename($subdirectory);

            // Get or create category
            $category = $this->getOrCreateFlashCategory($folderName);
            if (!$category) {
               $result['errors'][] = "Failed to create category for {$folderName}";
               continue;
            }
            $result['created_categories']++;

            // Process files in the subdirectory in batches
            $files = Storage::allFiles($subdirectory);
            $batches = array_chunk($files, $batchSize);

            foreach ($batches as $batch) {
               foreach ($batch as $file) {
                  $this->processFlashFile($file, $category, $result);
                  $processedCount++;
               }

               // Delay between batches if there are more files to process
               if ($processedCount < count($files)) {
                  sleep($delay);
               }
            }
         }

         $result['success'] = true;
         return $result;
      } catch (\Exception $e) {
         Log::error("Error processing flash files: " . $e->getMessage());
         $result['errors'][] = "System error: " . $e->getMessage();
         return $result;
      }
   }


   /**
    * Get or create a category for flash files
    */
   protected function getOrCreateFlashCategory(string $folderName): ?Category
   {
      $slug = Str::slug($folderName);

      return Category::firstOrCreate(
         ['slug' => $slug],
         [
            'name' => $folderName,
            'slug' => $slug,
            'icon' => '',
            'status' => 1,
            'is_featured' => 0,
            'is_top' => 0,
            'is_popular' => 0,
            'is_trending' => 0,
            'price' => 30, // Default price
            'thumb_image' => '',
         ]
      );
   }

   /**
    * Process an individual flash file
    */
   protected function processFlashFile(string $filePath, Category $category, array &$result): void
   {
      try {
         $fileName = basename($filePath);
         $fileId = md5($filePath); // Using file path hash as ID since we don't have OneDrive ID here
         $productName = pathinfo($fileName, PATHINFO_FILENAME);
         $fileSize = Storage::size($filePath);
         $lastModified = Storage::lastModified($filePath);

         // Check if product already exists
         $existingProduct = Product::where('onedrive_id', $fileId)
            ->orWhere('name', $productName)
            ->first();

         if ($existingProduct) {
            // Update existing product if file has changed
            if ($existingProduct->updated_at->timestamp < $lastModified) {
               $existingProduct->update([
                  'price' => $category->price,
                  'category_id' => $category->id,
                  'download_file' => $filePath,
                  'last_synced_at' => now(),
                  'updated_at' => now(),
               ]);
               $result['updated_products']++;
            } else {
               $result['skipped_products']++;
            }
            return;
         }

         // Create new product
         Product::create([
            'onedrive_id' => $fileId,
            'name' => $productName,
            'short_name' => Str::limit($productName, 20),
            'slug' => Str::slug($productName),
            'category_id' => $category->id,
            'sub_category_id' => 0,
            'child_category_id' => 0,
            'brand_id' => $category->brand_id ?? 1,
            'price' => $category->price ?? 0,
            'qty' => 10000,
            'short_description' => "Flash file {$productName}",
            'long_description' => "Flash file {$productName} from {$category->name} category",
            'download_file' => $filePath,
            'thumb_image' => $category->thumb_image ?? null,
            'banner_image' => null,
            'vendor_id' => 0,
            'video_link' => null,
            'sku' => null,
            'seo_title' => $productName,
            'seo_description' => "Download {$productName} flash file",
            'offer_price' => null,
            'offer_start_date' => null,
            'offer_end_date' => null,
            'tax_id' => 1,
            'is_cash_delivery' => 0,
            'is_return' => 0,
            'return_policy_id' => null,
            'tags' => null,
            'is_warranty' => 0,
            'show_homepage' => 0,
            'is_undefine' => 1,
            'is_featured' => 0,
            'serial' => null,
            'is_wholesale' => 0,
            'new_product' => 0,
            'is_top' => 0,
            'is_best' => 0,
            'is_flash_deal' => 0,
            'flash_deal_date' => null,
            'buyone_getone' => 0,
            'status' => 1,
            'last_synced_at' => now(),
            'is_specification' => 1,
         ]);


         $result['created_products']++;
      } catch (\Exception $e) {
         Log::error("Error processing flash file {$filePath}: " . $e->getMessage());
         $result['errors'][] = "Error processing {$filePath}: " . $e->getMessage();
      }
   }
}
