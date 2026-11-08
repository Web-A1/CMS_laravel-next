<?php

use App\Http\Controllers\ArticleMediaController;
use App\Http\Controllers\LegacyReviewImageController;
use Illuminate\Support\Facades\Route;

Route::get('/review-images/legacy', LegacyReviewImageController::class);
Route::get('/storage/article-media/{filename}', ArticleMediaController::class);
Route::get('/storage/article-media/variants/{sha}/v1/{filename}', [ArticleMediaController::class, 'variant']);
Route::get('/storage/reviews/{path}', LegacyReviewImageController::class)->where('path', '.*');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'backend',
    ]);
});
