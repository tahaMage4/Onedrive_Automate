<?php

namespace App\Console\Commands;

use App\Services\OneDriveService;
use Illuminate\Console\Command;

class GetOneDriveAuthUrl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'onedrive:auth-url';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the OAuth authorization URL for Microsoft OneDrive';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $oneDriveService = new OneDriveService();
        $authUrl = $oneDriveService->getAuthUrl();
        
        $this->info('Visit the following URL in your browser to authenticate with Microsoft:');
        $this->line($authUrl);
        $this->info('');
        $this->info('After authorizing, you will be redirected to your callback URL.');
        $this->info('The URL will contain a "code" parameter that you need for the next step.');
        $this->info('');
        $this->info('To complete the authentication process, run:');
        $this->info('php artisan onedrive:auth-callback {code}');

        return 0;
    }
}

