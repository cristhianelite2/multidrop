<?php

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CampaignProductMediaController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StoreController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('api.token')
    ->group(function () {
        // Tiendas
        Route::apiResource('stores', StoreController::class);

        // Buscador de productos por tienda
        Route::get('stores/{store}/products', [ProductController::class, 'byStore'])
            ->name('stores.products.index');

        // Productos
        Route::apiResource('products', ProductController::class);

        // Campañas
        Route::apiResource('campaigns', CampaignController::class);

        // Productos por campaña
        Route::get('campaigns/{campaign}/products', [CampaignController::class, 'products'])
            ->name('campaigns.products.index');
        Route::post('campaigns/{campaign}/products/{product}', [CampaignController::class, 'attachProduct'])
            ->name('campaigns.products.attach');
        Route::delete('campaigns/{campaign}/products/{product}', [CampaignController::class, 'detachProduct'])
            ->name('campaigns.products.detach');

        // Multimedia por producto de campaña
        Route::apiResource('campaigns/{campaign}/products/{product}/media', CampaignProductMediaController::class)
            ->parameters(['media' => 'media']);
    });
