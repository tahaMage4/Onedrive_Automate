<?php

use App\Http\Controllers\OneDriveController;
use Illuminate\Support\Facades\Route;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});



// Route::get('/onedrive/login', function (OneDriveService $drive) {
//     return redirect($drive->getAuthUrl());
// });

// Route::get('/onedrive/callback', function (Request $request, OneDriveService $drive) {
//     if (!$request->has('code')) {
//         abort(400, 'Authorization code missing.');
//     }

//     $tokens = $drive->getAccessToken($request->get('code'));
//     Cache::put('onedrive_access_token', $tokens['access_token'], $tokens['expires_in'] - 60);

//     return redirect('/onedrive/sync');
// });

// Route::get('/onedrive/sync', function (OneDriveService $drive) {
//     $token = Cache::get('onedrive_access_token');
//     if (!$token) {
//         return redirect('/onedrive/login');
//     }

//     $drive->downloadFiles($token);
//     return 'Files synced into storage/app/onedrive';
// });


Route::get('/onedrive/login', [OneDriveController::class, 'login'])->name('onedrive.login');
Route::get('/onedrive/callback', [OneDriveController::class, 'callback'])->name('onedrive.callback');
Route::get('/onedrive/status', [OneDriveController::class, 'status'])->name('onedrive.status');
Route::post('/onedrive/sync', [OneDriveController::class, 'sync'])->name('onedrive.sync');
Route::post('/onedrive/logout', [OneDriveController::class, 'logout'])->name('onedrive.logout');
Route::post('/onedrive/process-flash', [OneDriveController::class, 'processFlashProducts'])
    ->name('onedrive.process-flash');

Route::get('/test', [OneDriveController::class, 'test'])->name('test');
