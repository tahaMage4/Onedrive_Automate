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
      $localFileSummary = [];

      if ($isConnected) {
         // Get data for MOD Flash folder
         $modFlashUrl = env('MOD_FILE_URL');
         $modFlashData = $this->oneDriveService->listSharePointFiles($modFlashUrl);

         $oriFlashUrl = env('ORI_FILE_URL');
         $oriFlashData = $this->oneDriveService->listSharePointFiles($oriFlashUrl);

         $flashFolderData = [
            'MOD Flash' => $modFlashData,
            'ORIGINAL Flash' => $oriFlashData
         ];

         // Get local file summary
         $localFileSummary = $this->oneDriveService->getLocalFileSummary('flashfiles');
      }

      return view('onedrive.status', [
         'isConnected' => $isConnected,
         'hasRefreshToken' => $hasRefreshToken,
         'flashFolderData' => $flashFolderData,
         'localFileSummary' => $localFileSummary
      ]);
   }

   /**
    * Download flash folder files.
    */
   public function sync(Request $request)
   {
      try {
         $sharePointUrl_MOD = env('MOD_FILE_URL');
         $sharePointUrl_ORI = env('ORI_FILE_URL');

         // Pass URLs in order: MOD first, ORI second
         $result = $this->oneDriveService->fetchFlashFiles(
            $request->input('local_path', 'flashfiles'),
            [
               $sharePointUrl_MOD,
               $sharePointUrl_ORI
            ]
         );

         if ($result['success']) {
            return redirect()
               ->route('onedrive.status')
               ->with('success', $result['message']);
         } else {
            return redirect()
               ->route('onedrive.status')
               ->with('error', $result['message']);
         }
      } catch (\Exception $e) {
         Log::error("Sync failed: " . $e->getMessage());
         return redirect()
            ->route('onedrive.status')
            ->with('error', 'Sync failed: ' . $e->getMessage());
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

   /**
    * Test endpoint for debugging.
    */
   public function test()
   {
      try {
         $testResult = $this->oneDriveService->testConnection();

         if (isset($testResult['error'])) {
            return response()->json([
               'success' => false,
               'error' => $testResult['error']
            ]);
         }

         return response()->json([
            'success' => true,
            'data' => $testResult
         ]);
      } catch (\Exception $e) {
         return response()->json([
            'success' => false,
            'error' => $e->getMessage()
         ], 500);
      }
   }

   // Add this new method to your OneDriveController
   public function processFlashProducts(Request $request)
   {
      try {
         $result = $this->oneDriveService->processFlashFiles($request->input('local_path', 'flashfiles'));


         if ($result['success']) {
            $message = sprintf(
               "Processed flash files: %d categories, %d products created, %d updated, %d skipped",
               $result['created_categories'],
               $result['created_products'],
               $result['updated_products'],
               $result['skipped_products']
            );

            if (!empty($result['errors'])) {
               $message .= " (with " . count($result['errors']) . " errors)";
               Log::error('Flash files processing errors', $result['errors']);
            }

            return redirect()
               ->route('onedrive.status')
               ->with('success', $message);
         } else {
            return redirect()
               ->route('onedrive.status')
               ->with('error', 'Failed to process flash files: ' . implode(', ', $result['errors']));
         }
      } catch (\Exception $e) {
         Log::error("Flash files processing failed: " . $e->getMessage());
         return redirect()
            ->route('onedrive.status')
            ->with('error', 'Flash files processing failed: ' . $e->getMessage());
      }
   }
}
