<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Store;
use App\Services\Admin\StoreContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesCurrentStore
{
    protected function currentStoreOrFail(StoreContext $storeContext): Store
    {
        $store = $storeContext->current();
        if (! $store) {
            throw new HttpException(404, 'Selecciona un sitio primero.');
        }

        return $store;
    }

    protected function storeProductOptions(Store $store)
    {
        return $store->id
            ? \App\Models\Product::query()
                ->where('store_id', $store->id)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'status'])
            : collect();
    }
}
