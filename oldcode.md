<?php

namespace App\Services;

use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use GuzzleHttp\Client;
use Microsoft\Graph\Generated\Models\DriveItem;

class OneDriveService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $tenantId;
    protected string $redirectUri;
    /**
     * Microsoft Graph API client.
     */
    protected $graph;

    public function __construct()
    {
        $this->clientId     = config('services.onedrive.client_id');
        $this->clientSecret = config('services.onedrive.client_secret');
        $this->tenantId     = config('services.onedrive.tenant_id');
        $this->redirectUri  = config('services.onedrive.redirect_uri');
    }

    public function getAuthUrl(): string
    {
        $scopes = 'offline_access Files.Read.All';
        return 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'response_mode' => 'query',
            'scope'         => $scopes,
        ]);
    }

    public function getAccessToken(string $authCode): array
    {
        $client = new Client();

        $response = $client->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'form_params' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'authorization_code',
                'code'          => $authCode,
                'redirect_uri'  => $this->redirectUri,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    public function downloadFiles(string $accessToken, string $folder = '/'): void
    {
        $this->graph = new Graph();
        $this->graph->setAccessToken($accessToken);

        /** @var Model\DriveItem[] $items */
        $items = $this->graph->createRequest('GET', "/me/drive/root:" . $folder . ":/children")
            ->setReturnType(DriveItem::class)
            ->execute();

        foreach ($items as $item) {
            if ($item->getFile()) {
                $downloadUrl = $item->get('@microsoft.graph.downloadUrl');
                $fileName    = $item->getName();
                $path        = storage_path('app/onedrive/' . $fileName);

                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }

                file_put_contents($path, fopen($downloadUrl, 'r'));
            }
        }
    }
}




<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\Cache;

class SyncOneDrive extends Command
{
    protected $signature = 'onedrive:sync {--folder=/ : Folder path inside OneDrive to sync}';
    protected $description = 'Sync files from OneDrive to local storage';

    public function __construct(protected OneDriveService $drive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $token = Cache::get('onedrive_access_token');

        if (!$token) {
            $this->error('No access token cached. Visit /onedrive/login first.');
            return Command::FAILURE;
        }

        $folder = $this->option('folder');
        $this->drive->downloadFiles($token, $folder);

        $this->info('OneDrive files synced successfully.');
        return Command::SUCCESS;
    }
}


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
use Microsoft\Kiota\Serialization\EnumSerializer;


class OneDriveService
{
    protected function initializeGraph(): bool
    {
        $token = $this->getValidAccessToken();
        if (!$token) {
            return false;
        }
        $authProvider = new \Microsoft\Kiota\Authentication\AnonymousAccessTokenProvider(
            $token
        );
        $this->graph = new GraphServiceClient($authProvider);
        return true;
    }
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
}
