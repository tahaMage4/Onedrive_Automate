<?php

namespace App\Http\Controllers;

use App\Services\OneDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OneDriveController extends Controller
{
    protected OneDriveService $oneDriveService;

    public function __construct(OneDriveService $oneDriveService)
    {
        $this->oneDriveService = $oneDriveService;
    }

    /**
     * Redirect to Microsoft login page.
     */
    public function login()
    {
        $authUrl = $this->oneDriveService->getAuthUrl();
        return redirect($authUrl);
    }

    /**
     * Handle OAuth callback.
     */
    public function callback(Request $request)
    {
        if ($request->has('error')) {
            Log::error('OneDrive authentication error: ' . $request->input('error_description'));
            return redirect()->route('onedrive.status')->with('error', 'Authentication failed: ' . $request->input('error_description'));
        }

        $code = $request->input('code');

        if (!$code) {
            return redirect()->route('onedrive.status')->with('error', 'No authorization code received');
        }

        try {
            $tokenData = $this->oneDriveService->getAccessToken($code);
            return redirect()->route('onedrive.status')->with('success', 'Successfully authenticated with OneDrive');
        } catch (\Exception $e) {
            Log::error('OneDrive token error: ' . $e->getMessage());
            return redirect()->route('onedrive.status')->with('error', 'Failed to obtain access token: ' . $e->getMessage());
        }
    }

    /**
     * Show OneDrive connection status.
     */
    public function status()
    {
        $token = Cache::get('onedrive_access_token');
        $refreshToken = Cache::get('onedrive_refresh_token');

        $isConnected = !empty($token);
        $hasRefreshToken = !empty($refreshToken);

        if ($isConnected) {
            $files = $this->oneDriveService->getFileDetails('/');
        } else {
            $files = null;
        }

        return view('onedrive.status', [
            'isConnected' => $isConnected,
            'hasRefreshToken' => $hasRefreshToken,
            'files' => $files,
        ]);
    }

    /**
     * Manually sync files.
     */
    public function sync(Request $request)
    {
        $folder = $request->input('folder', '/');
        $localPath = $request->input('local_path', 'onedrive');

        $success = $this->oneDriveService->syncFiles($folder, $localPath);

        if ($success) {
            return redirect()->route('onedrive.status')->with('success', 'Files synced successfully');
        } else {
            return redirect()->route('onedrive.status')->with('error', 'Failed to sync files. Please check logs for details.');
        }
    }

    /**
     * Clear cached tokens.
     */
    public function logout()
    {
        Cache::forget('onedrive_access_token');
        Cache::forget('onedrive_refresh_token');

        return redirect()->route('onedrive.status')->with('success', 'Logged out from OneDrive');
    }
}
