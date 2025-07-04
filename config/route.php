<?php
// Jophiel/config/route.php

use Webman\Route;
use app\controller\FeedController;
use app\controller\SyncController;
use app\controller\TasteController;

// Endpoint para que los clientes (como Sword) consuman las recomendaciones.
Route::get('/v1/feed/{user_id:\d+}', [FeedController::class, 'get']);
Route::get('/v1/feed/points/{user_id:\d+}', [FeedController::class, 'getWithScores']);
Route::get('/v1/taste/{user_id:\d+}', [TasteController::class, 'get']);

// --- RUTAS DE SINCRONIZACIÓN EXPLÍCITAS ---
// Cada ruta llama a un método específico, eliminando la ambigüedad.
Route::group('/v1', function () {
    // Samples
    Route::get('/samples/checksum', [SyncController::class, 'getSamplesChecksum']);
    Route::get('/samples/ids',      [SyncController::class, 'getSamplesIds']);

    // Users
    Route::get('/users/checksum',   [SyncController::class, 'getUsersChecksum']);
    Route::get('/users/ids',        [SyncController::class, 'getUsersIds']);

    // Interactions
    Route::group('/interactions', function() {
        // Likes
        Route::get('/likes/checksum',   [SyncController::class, 'getLikesChecksum']);
        Route::get('/likes/ids',        [SyncController::class, 'getLikesIds']);
        // Follows
        Route::get('/follows/checksum', [SyncController::class, 'getFollowsChecksum']);
        Route::get('/follows/ids',      [SyncController::class, 'getFollowsIds']);
    });
});

// Deshabilitamos cualquier ruta que no sea estática para evitar conflictos.
Route::disableDefaultRoute();