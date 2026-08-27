<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CrossSellRule;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderClaim;
use App\Models\Product;
use App\Models\RouletteSlide;
use App\Models\Store;
use App\Models\UpsellRule;
use App\Services\Admin\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StoreManageController extends Controller
{
    public function enter(Store $store, StoreContext $storeContext)
    {
        if ($store->status === 'archived') {
            abort(404);
        }

        $storeContext->switchTo($store->id);

        return redirect()
            ->route('admin.store.hub')
            ->with('success', 'Administrando «'.$store->name.'».');
    }

    public function hub(StoreContext $storeContext)
    {
        return redirect()->route('admin.store.stats');
    }

    public function stats(StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $days = collect(range(13, 0))->map(fn (int $d) => Carbon::now()->subDays($d)->startOfDay());
        $start = $days->first();
        $end = Carbon::now()->endOfDay();

        $ordersMap = Order::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END) as paid_orders")
            ->selectRaw("SUM(CASE WHEN payment_status='paid' THEN total ELSE 0 END) as paid_revenue")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $chart14 = $days->map(function (Carbon $day) use ($ordersMap) {
            $k = $day->toDateString();
            $row = $ordersMap->get($k);

            return [
                'day' => $day->format('d/m'),
                'orders' => (int) ($row->total_orders ?? 0),
                'paid' => (int) ($row->paid_orders ?? 0),
                'revenue' => (float) ($row->paid_revenue ?? 0),
            ];
        })->values();

        $orders30 = Order::query()
            ->where('store_id', $store->id)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();
        $paid30 = Order::query()
            ->where('store_id', $store->id)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->where('payment_status', 'paid')
            ->count();
        $revenue30 = (float) Order::query()
            ->where('store_id', $store->id)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->where('payment_status', 'paid')
            ->sum('total');
        $claimsOpen = OrderClaim::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
        $newSalesUnread = Order::query()
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->whereNull('admin_seen_at')
            ->count();

        $stats = [
            'products' => Product::where('store_id', $store->id)->count(),
            'live_products' => Product::where('store_id', $store->id)->where('status', 'live')->count(),
            'coupons' => Coupon::where('store_id', $store->id)->count(),
            'offers' => Offer::where('store_id', $store->id)->count(),
            'upsells' => UpsellRule::where('store_id', $store->id)->count(),
            'cross_sells' => CrossSellRule::where('store_id', $store->id)->count(),
            'slides' => RouletteSlide::where('store_id', $store->id)->count(),
            'urgency' => Offer::where('store_id', $store->id)->where('is_active', true)->whereNotNull('ends_at')->count(),
            'orders_30' => $orders30,
            'paid_30' => $paid30,
            'revenue_30' => $revenue30,
            'conversion_30' => $orders30 > 0 ? round(($paid30 / $orders30) * 100, 1) : 0.0,
            'claims_open' => $claimsOpen,
            'new_sales_unread' => $newSalesUnread,
            'visits_supported' => false,
        ];

        return view('admin.store.stats', compact('store', 'stats', 'chart14'));
    }

    public function switchAndStay(Request $request, StoreContext $storeContext)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);

        $store = $storeContext->switchTo((int) $data['store_id']);

        return redirect()
            ->route('admin.store.stats')
            ->with('success', 'Contexto: «'.$store->name.'».');
    }
}
