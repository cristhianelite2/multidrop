<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderClaim;
use App\Services\Buyer\BuyerPortalAuth;
use App\Services\Commerce\ShippingQuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class BuyerPortalController extends Controller
{
    public function dashboard()
    {
        $buyer = Auth::guard('buyer')->user();
        $orders = $buyer->ordersQuery()->limit(8)->get();
        $claimsOpen = OrderClaim::query()
            ->where('buyer_account_id', $buyer->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        return view('buyer.dashboard', compact('buyer', 'orders', 'claimsOpen'));
    }

    public function profile()
    {
        $buyer = Auth::guard('buyer')->user();

        return view('buyer.profile', compact('buyer'));
    }

    public function updateProfile(Request $request)
    {
        $buyer = Auth::guard('buyer')->user();
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $buyer->fill($data)->save();

        return back()->with('success', 'Perfil actualizado.');
    }

    public function security()
    {
        $buyer = Auth::guard('buyer')->user();

        return view('buyer.security', compact('buyer'));
    }

    public function updatePassword(Request $request)
    {
        $buyer = Auth::guard('buyer')->user();
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:120', 'confirmed'],
            'current_password' => [$buyer->hasPassword() ? 'required' : 'nullable', 'string'],
        ]);

        if ($buyer->hasPassword() && ! Hash::check((string) ($data['current_password'] ?? ''), $buyer->password)) {
            return back()->with('error', 'La contraseña actual no es correcta.');
        }

        $buyer->password = $data['password'];
        $buyer->save();

        return back()->with('success', 'Contraseña guardada. Ya puedes entrar sin el número de pedido.');
    }

    public function orders()
    {
        $buyer = Auth::guard('buyer')->user();
        $orders = $buyer->ordersQuery()->paginate(20);

        return view('buyer.orders.index', compact('buyer', 'orders'));
    }

    public function showOrder(Order $order, BuyerPortalAuth $auth, ShippingQuoteService $shippingQuote)
    {
        $buyer = Auth::guard('buyer')->user();
        abort_unless($auth->ownsOrder($buyer, $order), 404);
        $order->load([
            'store',
            'items',
            'payments' => fn ($q) => $q->orderByDesc('id'),
            'fulfillments',
            'claims' => fn ($q) => $q->where('buyer_account_id', $buyer->id),
        ]);

        $addr = is_array($order->shipping_address) ? $order->shipping_address : [];
        $country = strtoupper((string) ($addr['country'] ?? ''));
        $eta = $country !== '' ? $shippingQuote->etaFor($country) : ['min' => 8, 'max' => 18];
        $etaLabel = $shippingQuote->etaLabel($eta);

        return view('buyer.orders.show', compact('buyer', 'order', 'eta', 'etaLabel'));
    }

    public function tracking()
    {
        $buyer = Auth::guard('buyer')->user();
        $orders = $buyer->ordersQuery()
            ->whereIn('payment_status', ['paid', 'pending'])
            ->paginate(20);

        return view('buyer.tracking', compact('buyer', 'orders'));
    }

    public function claims()
    {
        $buyer = Auth::guard('buyer')->user();
        $claims = OrderClaim::query()
            ->where('buyer_account_id', $buyer->id)
            ->with(['order', 'store'])
            ->orderByDesc('id')
            ->paginate(20);
        $orders = $buyer->ordersQuery()->limit(50)->get();

        return view('buyer.claims.index', compact('buyer', 'claims', 'orders'));
    }

    public function storeClaim(Request $request, BuyerPortalAuth $auth)
    {
        $buyer = Auth::guard('buyer')->user();
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $order = Order::query()->findOrFail((int) $data['order_id']);
        abort_unless($auth->ownsOrder($buyer, $order), 404);

        OrderClaim::create([
            'order_id' => $order->id,
            'buyer_account_id' => $buyer->id,
            'store_id' => $order->store_id,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'status' => 'open',
        ]);

        return redirect()->route('buyer.claims.index')->with('success', 'Reclamo enviado. La tienda te responderá aquí.');
    }

    public function showClaim(OrderClaim $claim)
    {
        $buyer = Auth::guard('buyer')->user();
        abort_unless($claim->buyer_account_id === $buyer->id, 404);
        $claim->load(['order', 'store']);

        return view('buyer.claims.show', compact('buyer', 'claim'));
    }
}
