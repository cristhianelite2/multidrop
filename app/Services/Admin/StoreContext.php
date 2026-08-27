<?php

namespace App\Services\Admin;

use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

class StoreContext
{
    public const SESSION_KEY = 'admin.current_store_id';

    public function all(): Collection
    {
        $stores = Store::query()
            ->with(['market', 'brand', 'parent.parent'])
            ->withCount('products')
            ->active()
            ->get();

        return Store::flattenTree($stores);
    }

    public function current(): ?Store
    {
        $id = Session::get(self::SESSION_KEY);

        if ($id) {
            $store = Store::query()
                ->with(['market', 'brand', 'parent.parent'])
                ->withCount('products')
                ->active()
                ->find($id);
            if ($store) {
                $store->setAttribute('tree_depth', $store->depth());

                return $store;
            }
        }

        $fallback = Store::query()
            ->with(['market', 'brand', 'parent.parent'])
            ->withCount('products')
            ->active()
            ->orderByRaw("CASE store_type WHEN 'mega' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE status WHEN 'live' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if ($fallback) {
            $this->switchTo($fallback->id);
            $fallback->setAttribute('tree_depth', $fallback->depth());
        }

        return $fallback;
    }

    public function switchTo(int $storeId): ?Store
    {
        $store = Store::query()->withCount('products')->active()->findOrFail($storeId);
        Session::put(self::SESSION_KEY, $store->id);

        return $store->load(['market', 'brand', 'parent.parent']);
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
