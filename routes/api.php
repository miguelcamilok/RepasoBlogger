<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PublicationController;
use Illuminate\Support\Facades\Route;

Route::prefix('categories')->group(function(){
    Route::get('/', [CategoryController::class, 'index']);
    Route::post('/', [CategoryController::class, 'store']);
    Route::get('{id}', [CategoryController::class, 'show']);
    Route::put('{id}', [CategoryController::class, 'update']);
    Route::delete('{id}', [CategoryController::class, 'destroy']);
});

Route::prefix('messages')->group(function(){
    Route::get('/', [MessageController::class, 'index']);
    Route::post('/', [MessageController::class, 'store']);
    Route::get('{id}', [MessageController::class, 'show']);
    Route::put('{id}', [MessageController::class, 'update']);
    Route::delete('{id}', [MessageController::class, 'destroy']);
});

Route::prefix('publications')->group(function(){
    Route::get('/', [PublicationController::class, 'index']);
    Route::post('/', [PublicationController::class, 'store']);
    Route::get('{id}', [PublicationController::class, 'show']);
    Route::put('{id}', [PublicationController::class, 'update']);
    Route::delete('{id}', [PublicationController::class, 'destroy']);
});
