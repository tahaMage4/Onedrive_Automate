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
    * Show OneDrive connection status with enhanced sync information.
    */
   public function status()
   {
      $token = Cache::get('onedrive_access_token');
      $refreshToken = Cache::get('onedrive_refresh_token');
      $isConnected = !empty($token);
      $hasRefreshToken = !empty($refreshToken);

      $flashFolderData = [];
      $syncStatus = [];

      if ($isConnected) {
         // Get sync status
         $syncStatus = $this->oneDriveService->getSyncStatus();

         // Get data for MOD Flash folder
         $modFlashUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';

         // Get MOD folder contents
         $modFlashData = $this->oneDriveService->getFolderContents($modFlashUrl, 'MOD');
         if (!isset($modFlashData['error'])) {
            $modFiles = array_filter($modFlashData['files'] ?? [], function ($file) {
               return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
            });
            $modFlashData = [
               'files' => $modFiles,
               'count' => count($modFiles),
               'total_size' => array_sum(array_column($modFiles, 'size'))
            ];
         }

         // Get ORI folder contents
         $oriFlashData = $this->oneDriveService->getFolderContents($modFlashUrl, 'ORI');
         if (!isset($oriFlashData['error'])) {
            $oriFiles = array_filter($oriFlashData['files'] ?? [], function ($file) {
               return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
            });
            $oriFlashData = [
               'files' => $oriFiles,
               'count' => count($oriFiles),
               'total_size' => array_sum(array_column($oriFiles, 'size'))
            ];
         } else {
            $oriFlashData = ['error' => 'Could not access ORI folder'];
         }

         $flashFolderData = [
            'MOD Flash' => $modFlashData,
            'ORI Flash' => $oriFlashData
         ];
      }

      return view('onedrive.status', [
         'isConnected' => $isConnected,
         'hasRefreshToken' => $hasRefreshToken,
         'flashFolderData' => $flashFolderData,
         'syncStatus' => $syncStatus,
      ]);
   }

   /**
    * Enhanced sync with ZIP download and delta sync support.
    */
   public function sync(Request $request)
   {
      $option = $request->input('option', 'zip_delta');
      $forceFullSync = $request->has('force_full_sync');

      try {
         switch ($option) {
            case 'zip_delta':
               if ($forceFullSync) {
                  Log::info('Force full sync requested');
                  $result = $this->oneDriveService->forceFullSync();
               } else {
                  Log::info('Delta sync requested');
                  $result = $this->oneDriveService->fetchFlashFiles();
               }

               if ($result['success']) {
                  $message = $this->buildSyncSuccessMessage($result, $forceFullSync);
                  return redirect()->route('onedrive.status')->with('success', $message);
               } else {
                  $message = $this->buildSyncErrorMessage($result);
                  return redirect()->route('onedrive.status')->with('error', $message);
               }

            case 'individual_files':
               Log::info('Individual file download requested (fallback mode)');
               // Fallback to original individual file download
               $result = $this->oneDriveService->downloadFlashFolders('flashfiles');

               if ($result['success']) {
                  return redirect()->route('onedrive.status')->with('success', $result['message']);
               } else {
                  return redirect()->route('onedrive.status')->with('error', $result['message']);
               }

            default:
               return redirect()->route('onedrive.status')->with('error', 'Invalid sync option selected');
         }
      } catch (\Exception $e) {
         Log::error('Error during sync: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
         return redirect()->route('onedrive.status')->with('error', 'Error during sync: ' . $e->getMessage());
      }
   }

   /**
    * Build success message for ZIP sync
    */
   protected function buildSyncSuccessMessage(array $result, bool $wasForced = false): string
   {
      $messages = [];

      if (!empty($result['mod_files']) && isset($result['mod_files']['success'])) {
         $modInfo = $result['mod_files'];
         $type = $modInfo['is_incremental'] && !$wasForced ? 'incremental' : 'full';
         $messages[] = "MOD files: {$modInfo['files_count']} files ({$type} sync)";
      }

      if (!empty($result['ori_files']) && isset($result['ori_files']['success'])) {
         $oriInfo = $result['ori_files'];
         $type = $oriInfo['is_incremental'] && !$wasForced ? 'incremental' : 'full';
         $messages[] = "ORI files: {$oriInfo['files_count']} files ({$type} sync)";
      }

      $mainMessage = $wasForced ?
         'Files synchronized successfully with ZIP optimization (Full sync forced)' :
         'Files synchronized successfully with ZIP optimization';

      if (!empty($messages)) {
         $mainMessage .= ' - ' . implode(', ', $messages);
      }

      // Add performance info
      $totalFiles = 0;
      if (!empty($result['mod_files']['files_count'])) {
         $totalFiles += $result['mod_files']['files_count'];
      }
      if (!empty($result['ori_files']['files_count'])) {
         $totalFiles += $result['ori_files']['files_count'];
      }

      if ($totalFiles > 0) {
         $mainMessage .= " (Total: {$totalFiles} files)";
      }

      return $mainMessage;
   }

   /**
    * Build error message for ZIP sync
    */
   protected function buildSyncErrorMessage(array $result): string
   {
      $errors = $result['errors'] ?? [];
      $mainMessage = $result['message'] ?? 'Sync failed';

      if (!empty($errors)) {
         $errorDetails = implode('; ', array_slice($errors, 0, 3));
         $mainMessage .= ' - Errors: ' . $errorDetails;

         if (count($errors) > 3) {
            $mainMessage .= ' (and ' . (count($errors) - 3) . ' more)';
         }
      }

      return $mainMessage;
   }

   /**
    * Test connection endpoint
    */
   public function test()
   {
      try {
         $result = $this->oneDriveService->testConnection();

         if (isset($result['success'])) {
            return response()->json([
               'status' => 'success',
               'message' => 'Connection successful',
               'user' => $result['user'],
               'email' => $result['email']
            ]);
         } else {
            return response()->json([
               'status' => 'error',
               'message' => $result['error']
            ]);
         }
      } catch (\Exception $e) {
         return response()->json([
            'status' => 'error',
            'message' => 'Connection test failed: ' . $e->getMessage()
         ]);
      }
   }

   /**
    * Get sync statistics
    */
   public function syncStats()
   {
      try {
         $syncStatus = $this->oneDriveService->getSyncStatus();

         return response()->json([
            'status' => 'success',
            'data' => $syncStatus
         ]);
      } catch (\Exception $e) {
         return response()->json([
            'status' => 'error',
            'message' => 'Failed to get sync stats: ' . $e->getMessage()
         ]);
      }
   }

   /**
    * Force full sync (reset delta tokens)
    */
   public function forceFullSync()
   {
      try {
         Log::info('Force full sync initiated via dedicated endpoint');
         $result = $this->oneDriveService->forceFullSync();

         if ($result['success']) {
            $message = $this->buildSyncSuccessMessage($result, true);
            return redirect()->route('onedrive.status')->with('success', $message);
         } else {
            $message = $this->buildSyncErrorMessage($result);
            return redirect()->route('onedrive.status')->with('error', $message);
         }
      } catch (\Exception $e) {
         Log::error('Error during force full sync: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
         return redirect()->route('onedrive.status')->with('error', 'Error during force full sync: ' . $e->getMessage());
      }
   }

   /**
    * Get folder preview (AJAX endpoint)
    */
   public function folderPreview(Request $request)
   {
      $folderName = $request->input('folder');

      if (!in_array($folderName, ['MOD', 'ORI'])) {
         return response()->json(['error' => 'Invalid folder name'], 400);
      }

      try {
         $sharePointUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';
         $folderData = $this->oneDriveService->getFolderContents($sharePointUrl, $folderName);

         if (isset($folderData['error'])) {
            return response()->json(['error' => $folderData['error']], 500);
         }

         $files = array_filter($folderData['files'] ?? [], function ($file) {
            return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
         });

         return response()->json([
            'success' => true,
            'files' => array_values($files),
            'count' => count($files),
            'total_size' => array_sum(array_column($files, 'size'))
         ]);
      } catch (\Exception $e) {
         Log::error("Error getting {$folderName} folder preview: " . $e->getMessage());
         return response()->json(['error' => 'Failed to get folder preview'], 500);
      }
   }

   /**
    * Download specific folder as ZIP
    */
   public function downloadFolderZip(Request $request)
   {
      $folderName = $request->input('folder');

      if (!in_array($folderName, ['MOD', 'ORI'])) {
         return response()->json(['error' => 'Invalid folder name'], 400);
      }

      try {
         $sharePointUrl = 'https://csfsoftware-my.sharepoint.com/:f:/g/personal/daviddieter_csfsoftware_onmicrosoft_com/Em0UIzXicIVKkWAh31GM1BYBfmalbmPCiAzeElLPSI4N2w?e=gj8mbh';
         $folderData = $this->oneDriveService->getFolderContents($sharePointUrl, $folderName);

         if (isset($folderData['error'])) {
            return response()->json(['error' => $folderData['error']], 500);
         }

         $files = array_filter($folderData['files'] ?? [], function ($file) {
            return $file['type'] === 'file' && preg_match('/\.(fls|FLS)$/i', $file['name']);
         });

         if (empty($files)) {
            return response()->json(['error' => 'No .fls files found in folder'], 404);
         }

         $zipResult = $this->oneDriveService->downloadFilesAsZip($files, "{$folderName}_files_" . date('Y-m-d_H-i-s'));

         if (!$zipResult['success']) {
            return response()->json(['error' => $zipResult['error']], 500);
         }

         return response()->download($zipResult['zipPath'], "{$folderName}_files.zip")->deleteFileAfterSend();
      } catch (\Exception $e) {
         Log::error("Error downloading {$folderName} folder ZIP: " . $e->getMessage());
         return response()->json(['error' => 'Failed to create ZIP download'], 500);
      }
   }

   /**
    * Clear cached tokens and delta tokens.
    */
   public function logout()
   {
      Cache::forget('onedrive_access_token');
      Cache::forget('onedrive_refresh_token');
      Cache::forget('onedrive_delta_MOD');
      Cache::forget('onedrive_delta_ORI');

      Log::info('OneDrive tokens and delta cache cleared');

      return redirect()->route('onedrive.status')->with('success', 'Logged out successfully. All cached data cleared.');
   }

   /**
    * Get detailed sync report
    */
   public function syncReport()
   {
      try {
         $syncStatus = $this->oneDriveService->getSyncStatus();

         // Get local file counts
         $modLocalPath = storage_path('app/mod-files');
         $oriLocalPath = storage_path('app/ori-files');

         $modLocalCount = 0;
         $oriLocalCount = 0;

         if (is_dir($modLocalPath)) {
            $modLocalCount = count(glob($modLocalPath . '/*.{fls,FLS}', GLOB_BRACE));
         }

         if (is_dir($oriLocalPath)) {
            $oriLocalCount = count(glob($oriLocalPath . '/*.{fls,FLS}', GLOB_BRACE));
         }

         return response()->json([
            'status' => 'success',
            'data' => array_merge($syncStatus, [
               'mod_local_files' => $modLocalCount,
               'ori_local_files' => $oriLocalCount,
               'local_storage_paths' => [
                  'MOD' => 'storage/app/mod-files',
                  'ORI' => 'storage/app/ori-files'
               ]
            ])
         ]);
      } catch (\Exception $e) {
         return response()->json([
            'status' => 'error',
            'message' => 'Failed to generate sync report: ' . $e->getMessage()
         ]);
      }
   }
}
