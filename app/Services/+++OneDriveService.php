<?php

namespace App\Services;

use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Microsoft\Graph\Generated\Models\DriveItem;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider;

class OneDriveService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $tenantId;
    protected string $redirectUri;
    protected ?string $accessToken = null;
    protected $graph = null;

    /**
     * Microsoft Graph API client.
     */
    public function __construct()
    {
        $this->clientId = config('services.onedrive.client_id');
        $this->clientSecret = config('services.onedrive.client_secret');
        $this->tenantId = config('services.onedrive.tenant_id');
        $this->redirectUri = config('services.onedrive.redirect_uri');
    }

    /**
     * Get Microsoft OAuth2 authorization URL.
     */
    public function getAuthUrl(): string
    {
        $scopes = 'offline_access Files.Read.All';
        return 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => $scopes,
        ]);
    }

    /**
     * Cache token data.
     *
     * @param array $tokenData
     * @return void
     */
    protected function cacheTokenData(array $tokenData): void
    {
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        // Cache access token (with 5 minute buffer before expiration)
        Cache::put('onedrive_access_token', $tokenData['access_token'], now()->addSeconds($expiresIn - 300));

        // Store refresh token (longer expiration)
        if (isset($tokenData['refresh_token'])) {
            Cache::put('onedrive_refresh_token', $tokenData['refresh_token'], now()->addDays(30));
        }

        $this->accessToken = $tokenData['access_token'];
    }

    /**
     * Get access token from authorization code.
     *
     * @param string $authCode
     * @return array
     * @throws GuzzleException
     */
    public function getAccessToken(string $authCode): array
    {
        $client = new Client();
        $response = $client->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'form_params' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'redirect_uri' => $this->redirectUri,
            ],
        ]);

        $tokenData = json_decode((string) $response->getBody(), true);
        $this->cacheTokenData($tokenData);

        return $tokenData;
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @return bool Success status
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
     * Get a valid access token (refreshes if needed).
     *
     * @return string|null Access token or null if not available
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
     * Initialize Microsoft Graph client with valid token.
     *
     * @return bool Success status
     */
    protected function initializeGraph(): bool
    {
        try {
            // Initialize using client credential context for v2 API
            $tokenContext = new ClientCredentialContext(
                $this->tenantId,
                $this->clientId,
                $this->clientSecret
            );
            $this->graph = new \Microsoft\Graph\GraphServiceClient($tokenContext);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to initialize Graph v2 client: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch all files from OneDrive, including nested folders
     *
     * @param string $localBasePath Local base directory path
     * @return bool Success status
     */
    public function fetchAllFiles(string $folder = '/', string $localBasePath = 'onedrive'): bool
    {
        if (!$this->initializeGraph()) {
            Log::error('Failed to initialize Graph client');
            return false;
        }
        try {
            // Ensure base directory exists
            if (!Storage::exists($localBasePath)) {
                Storage::makeDirectory($localBasePath);
            }


            // dd($this->graph->me()->drive());
            // $items = $this->graph->me()->drive()->itemsById('root')->children()->get()->wait();
            // $items = $this->graph->me()->drive()->root()->children()->get()->wait();
            // $graphServiceClient->drives()->byDriveId('drive-id')->items()->byDriveItemId('driveItem-id')->children()->get()->wait();
            // $items = $this->graph->me()->drive()->getRoot()->getChildren()->get();
            $items = $this->graph->drives()->byDriveId('269E8719BEA9023')->items()->byDriveItemId('0269E8719BEA9023')->children()->get();

            // https: //onedrive.live.com/?id=269E8719BEA9023%21sc657299bbffb4caab5117a45c631afe2&cid=0269E8719BEA9023&sb=name&sd=1
            dd($items);

            // Log::info("Found " . count($items) . " items in folder {$folder}");


            foreach ($items as $item) {
                $itemName = $item->getName();
                $itemPath = "{$localBasePath}/{$itemName}";


                // Process folders (especially looking for MOD Flash and ORI Flash)
                if ($item->getFolder()) {
                    Log::info("Processing folder: {$itemName}");

                    // Create local folder
                    if (!Storage::exists($itemPath)) {
                        Storage::makeDirectory($itemPath);
                    }

                    // Process folder contents
                    $this->processFolder($item->getId(), $itemPath);
                } else {
                    // Download root files if needed
                    $this->downloadFileById($item->getId(), $itemPath);
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error fetching files: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process a folder and all its contents using Graph v2 API
     *
     * @param string $folderId Folder ID
     * @param string $localPath Local folder path
     * @return void
     */
    protected function processFolder(string $folderId, string $localPath): void
    {
        try {

            // Get items in the folder using v2 API
            // $items = $this->graph->drives()->byDriveId('me')->items($folderId)->children()->get()->getValue();
            $items = $this->graph->me()->drive()->items($folderId)->children()->get()->wait();
            dd($items);
            // drives()->byDriveId('drive-id')->items()->byDriveItemId('driveItem-id')->children()->get()

            Log::info("Found " . count($items) . " items in folder {$localPath}");

            foreach ($items->getValue() as $item) {
                $itemName = $item->getName();
                $itemLocalPath = "{$localPath}/{$itemName}";

                // Process nested folders
                if ($item->getFolder()) {
                    // Create local folder
                    if (!Storage::exists($itemLocalPath)) {
                        Storage::makeDirectory($itemLocalPath);
                    }

                    // Process subfolder contents
                    $this->processFolder($item->getId(), $itemLocalPath);
                } else {
                    // Download file
                    $this->downloadFileById($item->getId(), $itemLocalPath);
                }
            }
        } catch (\Exception $e) {
            Log::error("Error processing folder {$folderId}: " . $e->getMessage());
        }
    }

    /**
     * Download a file by its ID using Graph v2 API
     *
     * @param string $fileId File ID
     * @param string $localPath Local file path
     * @return bool Success status
     */
    protected function downloadFileById(string $fileId, string $localPath): bool
    {
        try {
            // Get file metadata with v2 API
            // $item = $this->graph->drives()->byDriveId('me')->items($fileId)->get();
            $item = $this->graph->me()->drive()->items($fileId)->get()->wait();

            $remoteModified = $item->getLastModifiedDateTime();
            $needsUpdate = true;

            // Check if file exists and compare modification times
            if (Storage::exists($localPath)) {
                $localModified = Storage::lastModified($localPath);
                $remoteTimestamp = strtotime($remoteModified->format('c'));

                if ($localModified >= $remoteTimestamp) {
                    Log::info("File {$localPath} is up to date, skipping download");
                    $needsUpdate = false;
                }
            }

            if ($needsUpdate) {
                Log::info("Downloading file: {$localPath}");

                // Get content stream with v2 API
                // $stream = $this->graph
                //     ->drives()
                //     ->byDriveId('me')
                //     ->items($fileId)
                //     ->content
                //     ->get();

                $stream = $this->graph->me()->drive()->items($fileId)->content()->get()->wait();

                // Ensure directory exists
                $dirPath = dirname($localPath);
                if (!Storage::exists($dirPath)) {
                    Storage::makeDirectory($dirPath);
                }

                // Save file
                Storage::put($localPath, $stream->read());

                Log::info("Successfully downloaded {$localPath}");
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Error downloading file {$fileId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync files method for the command
     *
     * @param string $folder OneDrive folder path to sync
     * @param string $localBasePath Local folder path
     * @return bool Success status
     */
    public function syncFiles(string $folder = '/', string $localBasePath = 'onedrive'): bool
    {
        return $this->fetchAllFiles($folder, $localBasePath);
    }

    /**
     * Download specific folders by name (e.g., MOD Flash, ORI Flash) using Graph v2 API
     *
     * @param array $folderNames Names of folders to download
     * @param string $localBasePath Local base directory path
     * @return bool Success status
     */
    public function downloadSpecificFolders(array $folderNames, string $localBasePath = 'onedrive'): bool
    {
        if (!$this->initializeGraph()) {
            Log::error('Failed to initialize Graph client');
            return false;
        }

        try {
            // Ensure base directory exists
            if (!Storage::exists($localBasePath)) {
                Storage::makeDirectory($localBasePath);
            }

            // Get root folders using v2 API
            $rootItems = $this->graph->me()->drive()->root->children->get()->getValue();

            dd($rootItems);
            $rootItems = $this->graph->me()->drive()->root()->children()->get()->wait();


            $foundFolders = 0;

            foreach ($rootItems->getValue() as $item) {

                if ($item->getFolder() && in_array($item->getName(), $folderNames)) {
                    $folderName = $item->getName();
                    $folderPath = "{$localBasePath}/{$folderName}";
                    dd($folderName, $folderPath);

                    Log::info("Found requested folder: {$folderName}");
                    $foundFolders++;

                    // Create local folder
                    if (!Storage::exists($folderPath)) {
                        Storage::makeDirectory($folderPath);
                    }

                    // Process folder contents
                    $this->processFolder($item->getId(), $folderPath);
                }
            }

            Log::info("Found and processed {$foundFolders} of " . count($folderNames) . " requested folders");

            return $foundFolders > 0;
        } catch (\Exception $e) {
            Log::error('Error downloading specific folders: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a directory listing with specific file attributes
     *
     * @param string $folder OneDrive folder path
     * @return array|null List of files with details
     */
    public function getFileDetails(string $folder = '/'): ?array
    {
        if (!$this->initializeGraph()) {
            return null;
        }

        try {
            $items = null;
            if ($folder === '/') {
                // $items = $this->graph->me()->drive()->root()->children()->get()->wait();
                $items = $this->graph->drives()->byDriveId('drive-id')->items()->byDriveItemId('driveItem-id')->children()->get();
            } else {
                $cleanPath = ltrim($folder, '/');
                // To handle paths, we need to use a different approach
                $driveId = $this->graph->me()->drive()->get()->wait()->getId();
                $items = $this->graph->drives($driveId)->root()->itemByPath($cleanPath)->children()->get()->wait();
            }



            $result = [];


            foreach ($items as $item) {
                $entry = [
                    'name' => $item->getName(),
                    'id' => $item->getId(),
                    'type' => $item->getFolder() ? 'folder' : 'file',
                    'size' => $item->getSize(),
                    'last_modified' => $item->getLastModifiedDateTime() ?
                        $item->getLastModifiedDateTime()->format('Y-m-d H:i:s') : null,
                ];

                // Add extension info for files
                if (!$item->getFolder()) {
                    $pathInfo = pathinfo($item->getName());
                    $entry['extension'] = $pathInfo['extension'] ?? '';
                }

                // Add folder child count
                if ($item->getFolder()) {
                    $entry['child_count'] = $item->getFolder()->getChildCount();
                }

                $result[] = $entry;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Error getting file details: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch .fls files from both MOD Flash and ORI Flash folders using Graph v2 API
     *
     * @param string $localBasePath Local base directory path
     * @return array Array of downloaded files and any errors
     */
    public function fetchFlashFiles(string $localBasePath = 'flashfiles'): array
    {
        $result = [
            'success' => false,
            'downloaded' => [],
            'errors' => [],
            'message' => ''
        ];

        if (!$this->initializeGraph()) {
            $result['errors'][] = 'Failed to initialize Graph client';
            $result['message'] = 'Authentication error: Could not initialize Graph client';
            return $result;
        }

        try {
            // Ensure base directory exists
            if (!Storage::exists($localBasePath)) {
                Storage::makeDirectory($localBasePath);
            }

            // First check for MOD Flash and ORI Flash folders
            // $rootItems = $this->graph->me()->drive()->root->children->get()->getValue();
            $rootItems = $this->graph->me()->drive()->root()->children()->get()->wait();
            $flashFolders = [];

            foreach ($rootItems->getValue() as $item) {
                $itemName = $item->getName();
                if ($item->getFolder() && ($itemName === 'MOD Flash' || $itemName === 'ORI Flash')) {
                    $flashFolders[$itemName] = $item->getId();
                    Log::info("Found folder: {$itemName}");
                }
            }

            if (empty($flashFolders)) {
                $result['errors'][] = 'Flash folders not found';
                $result['message'] = 'Could not find MOD Flash or ORI Flash folders';
                return $result;
            }

            // Process each folder
            foreach ($flashFolders as $folderName => $folderId) {
                $folderPath = "{$localBasePath}/{$folderName}";

                // Create local folder
                if (!Storage::exists($folderPath)) {
                    Storage::makeDirectory($folderPath);
                }

                // Get folder contents with v2 API
                // $items = $this->graph->drives()->byDriveId('me')->items($folderId)->children->get()->getValue();
                $items = $this->graph->me()->drive()->items($folderId)->children()->get()->wait()->getValue();

                foreach ($items as $item) {
                    $itemName = $item->getName();
                    $itemLocalPath = "{$folderPath}/{$itemName}";

                    // For files with .fls or .FLS extension
                    if (
                        !$item->getFolder() &&
                        preg_match('/\.(fls|FLS)$/i', $itemName)
                    ) {

                        try {
                            // Get content stream using v2 API
                            $stream = $this->graph
                                ->drives()
                                ->byDriveId('me')
                                ->items($item->getId())
                                ->content
                                ->get();

                            // Save file
                            Storage::put($itemLocalPath, $stream->read());

                            $result['downloaded'][] = [
                                'folder' => $folderName,
                                'file' => $itemName,
                                'size' => $item->getSize(),
                                'path' => $itemLocalPath
                            ];

                            Log::info("Downloaded {$itemLocalPath}");
                        } catch (\Exception $e) {
                            $result['errors'][] = "Error downloading {$itemName}: " . $e->getMessage();
                            Log::error("Error downloading {$itemName}: " . $e->getMessage());
                        }
                    }
                }
            }

            $result['success'] = count($result['downloaded']) > 0;
            $result['message'] = count($result['downloaded']) . ' files downloaded successfully';

            return $result;
        } catch (\Exception $e) {
            $result['errors'][] = 'Error: ' . $e->getMessage();
            $result['message'] = 'Failed to fetch flash files: ' . $e->getMessage();
            Log::error('Error fetching flash files: ' . $e->getMessage());
            return $result;
        }
    }

    public function getModFlashContents()
    {
        $token = $this->getValidAccessToken();
        if (!$token) {
            Log::error('No valid access token');
            return null;
        }

        $client = new Client();
        try {
            // First verify the user has a OneDrive
            $response = $client->get('https://graph.microsoft.com/v1.0/me/drive', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ]
            ]);

            $driveInfo = json_decode($response->getBody(), true);
            Log::debug('Drive info:', $driveInfo);

            // Then try accessing the folder
            $response = $client->get('https://graph.microsoft.com/v1.0/me/drive/root:/MOD%20Flash:/children', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'query' => [
                    '$top' => 100, // Limit results
                    '$select' => 'name,size,lastModifiedDateTime,file,folder' // Only get needed fields
                ]
            ]);

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('Graph API Error:', [
                'message' => $e->getMessage(),
                // 'code' => $e->getCode(),
                // 'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }
}
