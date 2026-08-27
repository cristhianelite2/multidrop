<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Models\OrderClaim;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderClaimController extends Controller
{
    public function index(StoreContext $storeContext, Request $request)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $status = $request->query('status');
        $claims = OrderClaim::query()
            ->where('store_id', $store->id)
            ->with(['order', 'buyer'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.store.claims.index', compact('store', 'claims', 'status'));
    }

    public function show(OrderClaim $claim, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $claim->store_id === $store->id, 404);
        $claim->load(['order', 'buyer']);
        $buyer = $claim->buyer;

        return view('admin.store.claims.show', compact('store', 'claim', 'buyer'));
    }

    public function update(OrderClaim $claim, StoreContext $storeContext, Request $request)
    {
        $store = $storeContext->current();
        abort_unless($store && $claim->store_id === $store->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(OrderClaim::STATUSES))],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $claim->fill($data)->save();

        return back()->with('success', 'Reclamo actualizado.');
    }
}
