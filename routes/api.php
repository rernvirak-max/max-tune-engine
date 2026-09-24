<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\TrackController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
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
});

// Media URLs: temporary signed OR owner bearer
Route::get('tracks/{track}/cover', [TrackController::class, 'cover'])
    ->name('api.tracks.cover');
Route::get('tracks/{track}/stream', [TrackController::class, 'stream'])
    ->name('api.tracks.stream');
