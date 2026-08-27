<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderClaim;
use App\Models\Store;
use App\Services\Admin\StoreContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(StoreContext $storeContext)
    {
        $currentStore = $storeContext->current();
        $stores = $storeContext->all();

        $today = Carbon::now()->startOfDay();
        $last30 = Carbon::now()->subDays(30);

        $storeIds = $stores->pluck('id')->all();

        // Estadísticas globales plataforma
        $stats = [
            'products'    => DB::table('products')->count(),
            'orders'      => DB::table('orders')->count(),
            'stores_mini' => Store::query()->mini()->active()->count(),
            'stores_live' => Store::query()->active()->where('status', 'live')->count(),
            'markets'     => DB::table('markets')->where('is_active', true)->count(),
            'admins'      => DB::table('users')->where('is_active', true)->count(),
        ];

        // Ventas plataforma 30 días
        $platformOrders30 = Order::query()
            ->whereIn('store_id', $storeIds ?: [0])
            ->where('created_at', '>=', $last30)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END) as paid")
            ->selectRaw("SUM(CASE WHEN payment_status='paid' THEN total ELSE 0 END) as revenue")
            ->first();

        $stats['paid_30']     = (int) ($platformOrders30->paid ?? 0);
        $stats['orders_30']   = (int) ($platformOrders30->total ?? 0);
        $stats['revenue_30']  = (float) ($platformOrders30->revenue ?? 0);
        $stats['claims_open'] = OrderClaim::query()
            ->whereIn('store_id', $storeIds ?: [0])
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
        $stats['new_sales_unread'] = Order::query()
            ->whereIn('store_id', $storeIds ?: [0])
            ->where('payment_status', 'paid')
            ->whereNull('admin_seen_at')
            ->count();

        // Gráfica últimos 14 días (plataforma completa)
        $days = collect(range(13, 0))->map(fn (int $d) => Carbon::now()->subDays($d)->startOfDay());
        $ordersMap = Order::query()
            ->whereIn('store_id', $storeIds ?: [0])
            ->whereBetween('created_at', [$days->first(), Carbon::now()->endOfDay()])
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
                'day'     => $day->format('d/m'),
                'orders'  => (int) ($row->total_orders ?? 0),
                'paid'    => (int) ($row->paid_orders ?? 0),
                'revenue' => (float) ($row->paid_revenue ?? 0),
            ];
        })->values();

        // Ventas no leídas por tienda (para la tabla)
        $storeNewSales = Order::query()
            ->whereIn('store_id', $storeIds ?: [0])
            ->where('payment_status', 'paid')
            ->whereNull('admin_seen_at')
            ->selectRaw('store_id, COUNT(*) as cnt')
            ->groupBy('store_id')
            ->pluck('cnt', 'store_id');

        $storeClaimsOpen = OrderClaim::query()
            ->whereIn('store_id', $storeIds ?: [0])
            ->whereIn('status', ['open', 'in_progress'])
            ->selectRaw('store_id, COUNT(*) as cnt')
            ->groupBy('store_id')
            ->pluck('cnt', 'store_id');

        $recentOrders = Order::query()
            ->whereIn('store_id', $storeIds ?: [0])
            ->where('payment_status', 'paid')
            ->orderByDesc('id')
            ->limit(8)
            ->with('store:id,name')
            ->get(['id', 'number', 'store_id', 'customer_email', 'total', 'currency', 'payment_provider', 'created_at']);

        return view('admin.dashboard', compact(
            'currentStore',
            'stores',
            'stats',
            'chart14',
            'recentOrders',
            'storeNewSales',
            'storeClaimsOpen',
        ));
    }
}
