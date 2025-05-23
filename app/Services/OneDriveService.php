<?php

namespace App\Services;

use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Models\DriveItem;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
      $scopes = 'offline_access https://graph.microsoft.com/Sites.Read.All https://graph.microsoft.com/Files.Read.All https://graph.microsoft.com/User.Read';
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
      $response = $client->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
         'form_params' => [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'credentials',
            // 'code' => $authCode,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'offline_access https://graph.microsoft.com/Sites.Read.All https://graph.microsoft.com/Files.Read.All https://graph.microsoft.com/User.Read',
         ],
      ]);

      $tokenData = json_decode((string) $response->getBody(), true);
      $this->cacheTokenData($tokenData);

      return $tokenData;
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
               'scope' => 'offline_access https://graph.microsoft.com/Sites.Read.All https://graph.microsoft.com/Files.Read.All https://graph.microsoft.com/User.Read',
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
      // For sharing URLs, we need to decode the sharing token
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

         // If it's a 401, try to refresh the token
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
      // Clean up the site path
      $sitePath = ltrim($sitePath, '/');

      // Get site by hostname and path
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
         // Encode the sharing URL
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
         // First, try to access the shared item directly
         $sharedItem = $this->getSharedItem($sharePointUrl);

         if ($sharedItem && isset($sharedItem['id'])) {
            Log::info("Successfully accessed shared item: " . $sharedItem['name']);

            // Get children of the shared folder
            $endpoint = "shares/" . base64_encode($sharePointUrl) . "/driveItem/children";
            $encodedUrl = base64_encode($sharePointUrl);
            $encodedUrl = rtrim($encodedUrl, '=');
            $encodedUrl = 'u!' . strtr($encodedUrl, '+/', '-_');

            $endpoint = "shares/{$encodedUrl}/driveItem/children";
            $items = $this->makeGraphRequest($endpoint);

            return $items['value'] ?? [];
         }

         // Fallback: try parsing URL and accessing via site
         $parsedUrl = $this->parseSharePointUrl($sharePointUrl);
         if (!$parsedUrl) {
            return null;
         }

         // Get site information
         $siteInfo = $this->getSiteInfo($parsedUrl['hostname'], $parsedUrl['sitePath']);

         if (!$siteInfo) {
            Log::error('Could not get site information');
            return null;
         }

         Log::info("Site ID: " . $siteInfo['id']);

         $siteId = $siteInfo['id'];

         // Get the site's default drive
         $driveEndpoint = "sites/{$siteId}/drive";
         $driveInfo = $this->makeGraphRequest($driveEndpoint);

         if (!$driveInfo) {
            Log::error('Could not get drive information');
            return null;
         }

         Log::info("Drive ID: " . $driveInfo['id']);

         // Get root items from the drive
         $itemsEndpoint = "sites/{$siteId}/drive/root/children";
         $items = $this->makeGraphRequest($itemsEndpoint);

         return $items['value'] ?? [];
      } catch (\Exception $e) {
         Log::error('Error getting SharePoint folder contents: ' . $e->getMessage());
         return null;
      }
   }

   /**
    * Download files from SharePoint folder
    */
   public function downloadFlashFolders(string $localBasePath = 'flashfiles'): array
   {
      $sharePointUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';

      $result = [
         'success' => false,
         'downloaded' => [],
         'errors' => [],
         'message' => ''
      ];

      if (!$this->initializeAuth()) {
         $result['errors'][] = 'Failed to initialize authentication';
         $result['message'] = 'Authentication error: No valid access token available';
         return $result;
      }

      // Ensure base directory exists
      if (!Storage::exists($localBasePath)) {
         Storage::makeDirectory($localBasePath);
      }

      // Get folder contents
      $items = $this->getSharePointFolderContents($sharePointUrl);

      if (!$items) {
         $result['errors'][] = 'Could not access SharePoint folder';
         $result['message'] = 'Failed to retrieve folder contents from SharePoint';
         return $result;
      }

      Log::info("Found " . count($items) . " items in SharePoint folder");

      // Process items
      foreach ($items as $item) {
         if (isset($item['file']) && preg_match('/\.(fls|FLS)$/i', $item['name'])) {
            $this->downloadFileFromSharePoint($item, $localBasePath, $result);
         } elseif (isset($item['folder'])) {
            // Handle subfolders if needed
            $this->processSharePointSubfolder($item, $localBasePath, $result);
         }
      }

      $result['success'] = count($result['downloaded']) > 0;
      if ($result['success']) {
         $result['message'] = count($result['downloaded']) . ' files downloaded successfully';
      } else {
         $result['message'] = 'No .fls files found to download';
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
            // Try to get download URL via Graph API
            $fileEndpoint = "me/drive/items/{$item['id']}/content";

            try {
               $client = new Client();
               $response = $client->get("https://graph.microsoft.com/v1.0/{$fileEndpoint}", [
                  'headers' => [
                     'Authorization' => 'Bearer ' . $this->accessToken,
                  ],
                  'allow_redirects' => false
               ]);

               // Get the redirect location
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
    * Process SharePoint subfolders
    */
   protected function processSharePointSubfolder(array $folder, string $localBasePath, array &$result): void
   {
      try {
         $folderName = $folder['name'];
         $folderPath = "{$localBasePath}/{$folderName}";

         if (!Storage::exists($folderPath)) {
            Storage::makeDirectory($folderPath);
         }

         // Get subfolder contents
         if (isset($folder['id'])) {
            $subfolderEndpoint = "me/drive/items/{$folder['id']}/children";
            $subItems = $this->makeGraphRequest($subfolderEndpoint);

            if ($subItems && isset($subItems['value'])) {
               foreach ($subItems['value'] as $subItem) {
                  if (isset($subItem['file']) && preg_match('/\.(fls|FLS)$/i', $subItem['name'])) {
                     $this->downloadFileFromSharePoint($subItem, $folderPath, $result);
                  }
               }
            }
         }
      } catch (\Exception $e) {
         Log::error("Error processing subfolder {$folder['name']}: " . $e->getMessage());
      }
   }

   /**
    * Get all files from the SharePoint folder
    */
   public function fetchFlashFiles(string $localBasePath = 'flashfiles'): array
   {
      return $this->downloadFlashFolders($localBasePath);
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

      // Test basic Graph API access
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


   // More Added

   /**
    * Get folder data by URL (wrapper method for consistency)
    */
   public function getFolderByUrl(string $url): array
   {
      // Check if it's a SharePoint URL
      if (strpos($url, 'sharepoint.com') !== false) {
         return $this->listSharePointFiles($url);
      }

      // Check if it's a personal OneDrive URL
      if (strpos($url, 'onedrive.live.com') !== false) {
         // Personal OneDrive URLs need different handling
         return ['error' => 'Personal OneDrive URLs not supported yet'];
      }

      return ['error' => 'Unsupported URL format'];
   }

   /**
    * Download files from specific folders
    */
   public function downloadSpecificFolders(array $folderNames, string $localBasePath): bool
   {
      // This is a placeholder implementation
      // You would need to implement the logic based on your specific requirements

      $success = true;
      foreach ($folderNames as $folderName) {
         try {
            // Here you would implement the logic to download from specific folders
            Log::info("Processing folder: {$folderName}");

            // For now, just use the existing download functionality
            if ($folderName === 'MOD Flash') {
               $result = $this->fetchFlashFiles($localBasePath . '/' . strtolower(str_replace(' ', '_', $folderName)));
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
}
