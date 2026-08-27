<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\OrderClaim;
use App\Services\Admin\StoreContext;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareAdminStoreContext
{
    public function __construct(private StoreContext $storeContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $currentStore = $this->storeContext->current();
        $adminStores = $this->storeContext->all();
        $pulse = null;
        $storePulse = collect();
        $globalSalesUnread = 0;
        $globalClaimsOpen = 0;

        if ($adminStores->isNotEmpty()) {
            $storeIds = $adminStores->pluck('id')->all();

            $salesUnreadByStore = Order::query()
                ->selectRaw('store_id, COUNT(*) as total')
                ->whereIn('store_id', $storeIds)
                ->where('payment_status', 'paid')
                ->whereNull('admin_seen_at')
                ->groupBy('store_id')
                ->pluck('total', 'store_id');

            $claimsOpenByStore = OrderClaim::query()
                ->selectRaw('store_id, COUNT(*) as total')
                ->whereIn('store_id', $storeIds)
                ->whereIn('status', ['open', 'in_progress'])
                ->groupBy('store_id')
                ->pluck('total', 'store_id');

            $storePulse = $adminStores->mapWithKeys(function ($store) use ($salesUnreadByStore, $claimsOpenByStore) {
                $salesUnread = (int) ($salesUnreadByStore[$store->id] ?? 0);
                $claimsOpen = (int) ($claimsOpenByStore[$store->id] ?? 0);

                return [$store->id => [
                    'sales_unread' => $salesUnread,
                    'claims_open' => $claimsOpen,
                ]];
            });

            $globalSalesUnread = (int) $storePulse->sum('sales_unread');
            $globalClaimsOpen = (int) $storePulse->sum('claims_open');
        }

        if ($currentStore) {
            $today = Carbon::now()->startOfDay();
            $last30 = Carbon::now()->subDays(30);

            $ordersToday = Order::query()
                ->where('store_id', $currentStore->id)
                ->where('created_at', '>=', $today)
                ->count();
            $newPaidToday = Order::query()
                ->where('store_id', $currentStore->id)
                ->where('created_at', '>=', $today)
                ->where('payment_status', 'paid')
                ->count();
            $newSalesUnread = Order::query()
                ->where('store_id', $currentStore->id)
                ->where('payment_status', 'paid')
                ->whereNull('admin_seen_at')
                ->count();
            $claimsOpen = OrderClaim::query()
                ->where('store_id', $currentStore->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->count();

            $stats30 = Order::query()
                ->where('store_id', $currentStore->id)
                ->where('created_at', '>=', $last30)
                ->selectRaw('COUNT(*) as total_orders')
                ->selectRaw("SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END) as paid_orders")
                ->selectRaw("SUM(CASE WHEN payment_status='paid' THEN total ELSE 0 END) as paid_revenue")
                ->first();

            $totalOrders30 = (int) ($stats30->total_orders ?? 0);
            $paidOrders30 = (int) ($stats30->paid_orders ?? 0);
            $paidRevenue30 = (float) ($stats30->paid_revenue ?? 0);
            $conversion30 = $totalOrders30 > 0
                ? round(($paidOrders30 / $totalOrders30) * 100, 1)
                : 0.0;

            $pulse = [
                'orders_today' => $ordersToday,
                'new_paid_today' => $newPaidToday,
                'new_sales_unread' => $newSalesUnread,
                'claims_open' => $claimsOpen,
                'paid_orders_30' => $paidOrders30,
                'total_orders_30' => $totalOrders30,
                'revenue_30' => $paidRevenue30,
                'conversion_30' => $conversion30,
            ];
        }

        View::share('currentStore', $currentStore);
        View::share('adminStores', $adminStores);
        View::share('adminStorePulse', $pulse);
        View::share('adminStorePulseMap', $storePulse);
        View::share('adminGlobalSalesUnread', $globalSalesUnread);
        View::share('adminGlobalClaimsOpen', $globalClaimsOpen);

        return $next($request);
    }
}
