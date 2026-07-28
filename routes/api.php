<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StorefrontSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::get('/categories', [CategoryController::class, 'index'])->name('api.categories');
Route::get('/products', [ProductController::class, 'index'])->name('api.products');
Route::get('/products/featured', [ProductController::class, 'featured'])->name('api.products.featured');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('api.products.show');
Route::get('/settings/storefront', [StorefrontSettingsController::class, 'show'])->name('api.settings.storefront');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
