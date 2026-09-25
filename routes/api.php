<?php

use App\Http\Controllers\Api\Admin\ImportAdminController;
use App\Http\Controllers\Api\Admin\InviteController as AdminInviteController;
use App\Http\Controllers\Api\Admin\TrackAdminController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\Admin\YoutubeAdminController;
use App\Http\Controllers\Api\AppConfigController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MediaImportController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\TrackController;
use Illuminate\Support\Facades\Route;

Route::get('app', [AppConfigController::class, 'show']);

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('me', [MeController::class, 'show']);

    Route::get('tracks', [TrackController::class, 'index']);
    Route::post('tracks', [TrackController::class, 'store']);
    Route::get('tracks/{track}', [TrackController::class, 'show']);
    Route::delete('tracks/{track}', [TrackController::class, 'destroy']);

    Route::get('playlists', [PlaylistController::class, 'index']);
    Route::post('playlists', [PlaylistController::class, 'store']);
    Route::get('playlists/{playlist}', [PlaylistController::class, 'show']);
    Route::patch('playlists/{playlist}', [PlaylistController::class, 'update']);
    Route::delete('playlists/{playlist}', [PlaylistController::class, 'destroy']);
    Route::post('playlists/{playlist}/tracks', [PlaylistController::class, 'attachTrack']);
    Route::delete('playlists/{playlist}/tracks/{track}', [PlaylistController::class, 'detachTrack']);

    Route::get('likes', [LikeController::class, 'index']);
    Route::post('tracks/{track}/like', [LikeController::class, 'store']);
    Route::delete('tracks/{track}/like', [LikeController::class, 'destroy']);

    Route::get('catalog/jamendo', [CatalogController::class, 'searchJamendo']);
    Route::post('catalog/jamendo/import', [CatalogController::class, 'importJamendo']);

    Route::get('imports', [MediaImportController::class, 'index']);
    Route::post('imports/youtube', [MediaImportController::class, 'storeYoutube']);
    Route::get('imports/{mediaImport}', [MediaImportController::class, 'show']);
    Route::post('imports/{mediaImport}/retry', [MediaImportController::class, 'retry']);
    Route::delete('imports/{mediaImport}', [MediaImportController::class, 'destroy']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('invites', [AdminInviteController::class, 'index']);
        Route::post('invites', [AdminInviteController::class, 'store']);
        Route::post('invites/{invite}/revoke', [AdminInviteController::class, 'revoke']);

        Route::get('users', [UserAdminController::class, 'index']);
        Route::post('users/{user}/disable', [UserAdminController::class, 'disable']);
        Route::post('users/{user}/enable', [UserAdminController::class, 'enable']);
        Route::patch('users/{user}/quota', [UserAdminController::class, 'updateQuota']);

        Route::get('tracks', [TrackAdminController::class, 'index']);
        Route::delete('tracks/{track}', [TrackAdminController::class, 'destroy']);

        Route::get('imports', [ImportAdminController::class, 'index']);
        Route::delete('imports/{mediaImport}', [ImportAdminController::class, 'destroy']);

        Route::get('youtube/status', [YoutubeAdminController::class, 'status']);
        Route::put('youtube/cookies', [YoutubeAdminController::class, 'updateCookies']);
    });
});

// Media URLs: temporary signed OR owner bearer
Route::get('tracks/{track}/cover', [TrackController::class, 'cover'])
    ->name('api.tracks.cover');
Route::get('tracks/{track}/stream', [TrackController::class, 'stream'])
    ->name('api.tracks.stream');
Route::get('imports/{mediaImport}/thumbnail', [MediaImportController::class, 'thumbnail'])
    ->name('api.imports.thumbnail');
