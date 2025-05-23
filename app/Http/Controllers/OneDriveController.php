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

      $flashFolderData = [];

      if ($isConnected) {
         // Get data for MOD Flash folder
         $modFlashUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';
         $modFlashData = $this->oneDriveService->listSharePointFiles($modFlashUrl);

         // Note: The ORI Flash URL appears to be a personal OneDrive URL, not SharePoint
         // You may need to handle this differently or add support for personal OneDrive URLs
         $oriFlashUrl = 'https://onedrive.live.com/?id=269E8719BEA9023%21s9ed9a3079e1b4957baf9a42d4d9adf87&cid=0269E8719BEA9023&sb=name&sd=1';
         $oriFlashData = ['error' => 'Personal OneDrive URLs not supported yet'];

         $flashFolderData = [
            'MOD Flash' => $modFlashData,
            'ORI Flash' => $oriFlashData
         ];
      }

      return view('onedrive.status', [
         'isConnected' => $isConnected,
         'hasRefreshToken' => $hasRefreshToken,
         'flashFolderData' => $flashFolderData,
      ]);
   }

   /**
    * Download flash folder files.
    */
   public function sync(Request $request)
   {
      $option = $request->input('option', 'flashfiles');

      try {
         if ($option === 'flashfiles') {
            $result = $this->oneDriveService->fetchFlashFiles('flashfiles');

            if ($result['success']) {
               return redirect()->route('onedrive.status')->with('success', $result['message']);
            } else {
               return redirect()->route('onedrive.status')->with('error', $result['message']);
            }
         } else {
            // For now, just use the existing fetchFlashFiles method
            // You can modify this if you need different behavior
            $result = $this->oneDriveService->fetchFlashFiles('onedrive');

            if ($result['success']) {
               return redirect()->route('onedrive.status')->with('success', 'Flash folders synced successfully');
            } else {
               return redirect()->route('onedrive.status')->with('error', 'Failed to sync flash folders');
            }
         }
      } catch (\Exception $e) {
         Log::error('Error during sync: ' . $e->getMessage());
         return redirect()->route('onedrive.status')->with('error', 'Error during sync: ' . $e->getMessage());
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
