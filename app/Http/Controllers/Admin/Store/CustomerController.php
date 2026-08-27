<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Customer;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function index(StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $customers = Customer::query()
            ->where('store_id', $store->id)
            ->withCount('orders')
            ->withSum(['orders as spent' => fn ($q) => $q->where('payment_status', 'paid')], 'total')
            ->orderByDesc('id')
            ->paginate(30);

        $coupons = Coupon::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'type', 'value']);

        return view('admin.store.customers.index', compact('store', 'customers', 'coupons'));
    }

    public function show(Customer $customer, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $customer->store_id === $store->id, 404);
        $customer->load(['orders' => fn ($q) => $q->orderByDesc('id')]);

        return view('admin.store.customers.show', compact('store', 'customer'));
    }

    public function export(StoreContext $storeContext): StreamedResponse
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $filename = 'clientes-'.$store->slug.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($store) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'phone', 'orders', 'spent', 'created_at']);
            Customer::query()
                ->where('store_id', $store->id)
                ->withCount('orders')
                ->withSum(['orders as spent' => fn ($q) => $q->where('payment_status', 'paid')], 'total')
                ->orderBy('email')
                ->chunk(200, function ($rows) use ($out) {
                    foreach ($rows as $c) {
                        fputcsv($out, [
                            $c->email,
                            $c->name,
                            $c->phone,
                            $c->orders_count,
                            $c->spent,
                            optional($c->created_at)->toDateTimeString(),
                        ]);
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
