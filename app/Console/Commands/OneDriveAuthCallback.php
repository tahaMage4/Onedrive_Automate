<?php

namespace App\Console\Commands;

use App\Services\OneDriveService;
use Illuminate\Console\Command;

class OneDriveAuthCallback extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'onedrive:auth-callback {code}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process the OAuth callback code from Microsoft and get access token';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $code = $this->argument('code');
        
        if (empty($code)) {
            $this->error('Authorization code is required');
            return 1;
        }
        
        $this->info('Processing authorization code...');
        
        try {
            $oneDriveService = new OneDriveService();
            $tokenData = $oneDriveService->getAccessToken($code);
            
            if (!$tokenData || !isset($tokenData['access_token'])) {
                $this->error('Failed to obtain access token. Please try the authentication process again.');
                return 1;
            }
            
            $this->info('Authentication successful!');
            $this->info('Access token has been cached.');
            $this->info('');
            $this->info('You can now run the OneDrive sync command:');
            $this->info('php artisan onedrive:sync --flashfiles');
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Error processing authorization code: ' . $e->getMessage());
            return 1;
        }
    }
}

