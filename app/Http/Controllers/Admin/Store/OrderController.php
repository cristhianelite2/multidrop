<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Jobs\FulfillOrderWithCjJob;
use App\Models\BuyerAccount;
use App\Models\Order;
use App\Services\Admin\StoreContext;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $orders = Order::query()
            ->where('store_id', $store->id)
            ->with('fulfillments')
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.store.orders.index', compact('store', 'orders'));
    }

    public function show(Order $order, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $order->store_id === $store->id, 404);

        if ($order->admin_seen_at === null) {
            $order->forceFill(['admin_seen_at' => Carbon::now()])->saveQuietly();
        }

        $order->load(['items.product', 'payments', 'fulfillments', 'customer', 'claims.buyer']);
        $buyerAccount = BuyerAccount::query()
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $order->customer_email)])
            ->first();

        return view('admin.store.orders.show', compact('store', 'order', 'buyerAccount'));
    }

    public function fulfill(Order $order, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $order->store_id === $store->id, 404);
        if (! $order->isPaid()) {
            return back()->with('error', 'El pedido aún no está pagado.');
        }
        FulfillOrderWithCjJob::dispatchSync($order->id);

        return back()->with('success', 'Se reintentó el envío a CJ.');
    }

    public function markPaid(Order $order, StoreContext $storeContext, Request $request)
    {
        $store = $storeContext->current();
        abort_unless($store && $order->store_id === $store->id, 404);
        app(\App\Services\Commerce\CheckoutService::class)->markPaid($order, 'manual-'.$order->number, [
            'manual' => true,
            'by' => $request->user()?->email,
        ]);

        return back()->with('success', 'Pedido marcado como pagado.');
    }
}
