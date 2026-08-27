<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;

class StoreContextController extends Controller
{
    public function switch(Request $request, StoreContext $storeContext)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);

        $store = $storeContext->switchTo((int) $data['store_id']);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'store_type' => $store->store_type,
                    'sector' => $store->sector,
                    'status' => $store->status,
                    'market' => $store->market?->code,
                ],
            ]);
        }

        return back()->with('success', 'Contexto cambiado a «'.$store->name.'».');
    }
}
