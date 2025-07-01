<?php


use Webman\Route;
use app\controller\FeedController;

// Endpoint para que los clientes (como Sword) consuman las recomendaciones.
Route::get('/v1/feed/{user_id:\d+}', [FeedController::class, 'get']);