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
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Kiota\Authentication\PhpLeagueAccessTokenProvider;
use Microsoft\Kiota\Authentication\PhpLeagueAuthenticationProvider;
use Microsoft\Kiota\Serialization\EnumSerializer;

class OneDriveService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $tenantId;
    protected string $redirectUri;
    protected ?string $accessToken = null;
    protected  $graph = null;

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
    // protected function initializeGraph(): bool
    // {
    //     $token = $this->getValidAccessToken();

    //     if (!$token) {
    //         return false;
    //     }

    //     $this->graph = new Graph();
    //     $this->graph->setAccessToken($token);
    //     return true;
    // }

    //! new


    protected function initializeGraph(): bool
    {
        // $token = $this->getValidAccessToken();
        // if (!$token) {
        //     return false;
        // }


        $tokenContext = new ClientCredentialContext(
            config('services.onedrive.tenant_id'),
            config('services.onedrive.client_id'),
            config('services.onedrive.client_secret')
        );
        // Scope defaults to "https://graph.microsoft.com/.default"
        $this->graph = new GraphServiceClient($tokenContext);
        return true;

        // $authProvider = new \Microsoft\Kiota\Authentication\AnonymousAccessTokenProvider(
        //     $token
        // );
        // $this->graph = new GraphServiceClient($authProvider);
        // return true;
    }

    /**
     * Sync files from OneDrive to local storage.
     *
     * @param string $folder OneDrive folder path (must start with /)
     * @param string $localPath Local directory path relative to storage/app
     * @return bool Success status
     */
    public function syncFiles(string $folder = '/', string $localPath = 'onedrive'): bool
    {

        if (!$this->initializeGraph()) {
            return false;
        }

        try {
            $this->syncDirectory($folder, $localPath);
            return true;
        } catch (\Exception $e) {
            Log::error('Error syncing OneDrive files: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync a directory and its contents recursively.
     *
     * @param string $remotePath OneDrive path
     * @param string $localPath Local directory path
     * @return void
     */
    protected function syncDirectory(string $remotePath, string $localPath): void
    {
        Log::info("Syncing directory: {$remotePath} to {$localPath}");

        // Ensure local directory exists
        if (!Storage::exists($localPath)) {
            Storage::makeDirectory($localPath);
        }

        // Normalize path for Graph API
        // $apiPath = $remotePath === '/' ? '/me/drive/root/children' : "/me/drive/root:{$remotePath}:/children";

        // $items = $this->graph->createRequest('GET', $apiPath)
        //     ->setReturnType(DriveItem::class)
        //     ->execute();
        // Get items based on path
        $items = [];
        if ($remotePath === '/') {
            $items = $this->graph->me()->drive()->root->children->get()->getValue();
        } else {
            $cleanPath = ltrim($remotePath, '/');
            $items = $this->graph->me()->drive()->root->getItemWithPath($cleanPath)->children->get()->getValue();
        }

        foreach ($items as $item) {
            $itemName = $item->getName();
            $itemLocalPath = "{$localPath}/{$itemName}";

            if ($item->getFolder()) {
                $itemRemotePath = $remotePath === '/' ? "/{$itemName}" : "{$remotePath}/{$itemName}";
                $this->syncDirectory($itemRemotePath, $itemLocalPath);
            } elseif ($item->getFile()) {
                $this->downloadFile($item, $itemLocalPath);
            }
        }
    }

    /**
     * Download a single file.
     *
     * @param Model\DriveItem $item
     * @param string $localPath
     * @return void
     */
    // protected function downloadFile(DriveItem $item, string $localPath): void
    // {
    //     $downloadUrl = $item->getDownloadUrl();

    //     if (!$downloadUrl) {
    //         // If download URL is not directly available, get it from the API
    //         $itemId = $item->getId();
    //         $downloadUrl = $this->graph->createRequest('GET', "/me/drive/items/{$itemId}/content")
    //             ->addHeaders(["Content-Type" => "application/json"])
    //             ->setReturnType(null)
    //             ->getUrl();
    //     }

    //     // Check if file exists and compare modification times
    //     $remoteModified = $item->getLastModifiedDateTime();
    //     $needsUpdate = true;

    //     if (Storage::exists($localPath)) {
    //         $localModified = Storage::lastModified($localPath);
    //         $remoteTimestamp = strtotime($remoteModified->format('c'));

    //         if ($localModified >= $remoteTimestamp) {
    //             Log::info("File {$localPath} is up to date, skipping download");
    //             $needsUpdate = false;
    //         }
    //     }

    //     if ($needsUpdate) {
    //         Log::info("Downloading file: {$localPath}");

    //         // Stream the file to storage
    //         $tempFile = tmpfile();
    //         $tempFilePath = stream_get_meta_data($tempFile)['uri'];

    //         $client = new Client();
    //         $client->get($downloadUrl, ['sink' => $tempFilePath]);

    //         // Move the file to storage
    //         Storage::put($localPath, file_get_contents($tempFilePath));
    //         fclose($tempFile);
    //     }
    // }


    protected function downloadFile($item, string $localPath): void
    {
        // Check if file exists and compare modification times
        $remoteModified = $item->getLastModifiedDateTime();
        $needsUpdate = true;

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

            // Get file content stream
            $content = $this->graph
                ->drives()
                ->byDriveId('me')
                ->items($item->getId())
                ->content
                ->get();

            // Write to storage
            Storage::put($localPath, $content->read());
        }
    }


    // new
    public function downloadFiles(string $accessToken, string $folder = '/'): void
    {
        $this->initializeGraph();                // makes sure $this->graph is ready
        // v2 call pattern
        $driveItems = $this->graph
            ->me()
            ->drive()
            ->root()
            ->itemWithPath($folder)
            ->children()
            ->get()
            ->getValue();   // returns array of DriveItem objects
        foreach ($driveItems as $item) {
            if ($item->getFile()) {
                $content = $this->graph
                    ->drive()
                    ->items($item->getId())
                    ->content()
                    ->get();          // stream
                $path = storage_path('app/onedrive/' . $item->getName());
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }
                file_put_contents($path, $content->read());
            }
        }
    }

    /**
     * Get OneDrive directory listing.
     *
     * @param string $folder OneDrive folder path
     * @return array|null List of drive items or null on failure
     */
    public function listDirectory(string $folder = '/'): ?array
    {
        if (!$this->initializeGraph()) {
            return null;
        }

        try {
            // $apiPath = $folder === '/' ? '/me/drive/root/children' : "/me/drive/root:{$folder}:/children";

            // $items = $this->graph->createRequest('GET', $apiPath)
            //     ->setReturnType(Model\DriveItem::class)
            //     ->execute();

            $items = [];
            if ($folder === '/') {
                $items = $this->graph->me()->drive()->root->children->get()->getValue();
            } else {
                $cleanPath = ltrim($folder, '/');
                $items = $this->graph->me()->drive()->root->getItemWithPath($cleanPath)->children->get()->getValue();
            }

            $result = [];

            foreach ($items as $item) {
                $result[] = [
                    'name' => $item->getName(),
                    'id' => $item->getId(),
                    'type' => $item->getFolder() ? 'folder' : 'file',
                    'size' => $item->getSize(),
                    'last_modified' => $item->getLastModifiedDateTime()->format('Y-m-d H:i:s'),
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Error listing OneDrive directory: ' . $e->getMessage());
            return null;
        }
    }
}



# SyncOneDive Command Code

```
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\Cache;

class SyncOneDrive extends Command
{
    protected $signature = 'onedrive:sync
                            {--folder=/ : Folder path inside OneDrive to sync}
                            {--local=onedrive : Local directory path relative to storage/app}';

    protected $description = 'Sync files from OneDrive to local storage';

    public function __construct(protected OneDriveService $drive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $folder = $this->option('folder');
        $localPath = $this->option('local');

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

```
