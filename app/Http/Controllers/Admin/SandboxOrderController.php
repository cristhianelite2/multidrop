<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ThemeSandboxOrder;
use App\Services\Storefront\SandboxCjFulfillmentService;
use Illuminate\View\View;

class SandboxOrderController extends Controller
{
    public function index(): View
    {
        $this->assertVisible();

        $orders = ThemeSandboxOrder::query()
            ->with('theme:id,name,slug')
            ->orderByDesc('id')
            ->paginate(30);

        return view('admin.sandbox.orders.index', [
            'orders' => $orders,
            'showRaw' => $this->showRaw(),
        ]);
    }

    public function show(ThemeSandboxOrder $sandboxOrder): View
    {
        $this->assertVisible();

        return view('admin.sandbox.orders.show', [
            'order' => $sandboxOrder->load('theme'),
            'showRaw' => $this->showRaw(),
        ]);
    }

    public function refresh(ThemeSandboxOrder $sandboxOrder, SandboxCjFulfillmentService $cj)
    {
        $this->assertVisible();
        $cj->refresh($sandboxOrder);

        return back()->with('success', 'Se consultó CJ (detalle + tracking).');
    }

    public function resubmit(ThemeSandboxOrder $sandboxOrder, SandboxCjFulfillmentService $cj)
    {
        $this->assertVisible();
        $sandboxOrder->cj_order_id = null;
        $sandboxOrder->cj_error = null;
        $sandboxOrder->fulfillment_status = 'unfulfilled';
        $sandboxOrder->save();
        $cj->submit($sandboxOrder);

        return back()->with('success', 'Se reenvió el pedido a CJ.');
    }

    protected function assertVisible(): void
    {
        abort_unless(auth()->user()?->isSuperuser() || auth()->user()?->hasAnyPermission(['store.manage', 'settings.general']), 403);
    }

    protected function showRaw(): bool
    {
        return (bool) config('multidrop.sandbox_cj_debug') || app()->environment('local');
    }
}
