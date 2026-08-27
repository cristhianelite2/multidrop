<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Buyer\BuyerPortalAuth;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BuyerAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('buyer')->check()) {
            return redirect()->route('buyer.dashboard');
        }

        return view('buyer.auth.login', [
            'turnstileSiteKey' => app(TurnstileVerifier::class)->siteKey(),
        ]);
    }

    public function login(Request $request, BuyerPortalAuth $auth, TurnstileVerifier $turnstile)
    {
        $mode = $request->input('mode', 'order');
        if (! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            return back()->withInput()->with('error', 'Verificación de seguridad fallida. Intenta de nuevo.');
        }

        if ($mode === 'password') {
            $data = $request->validate([
                'email' => ['required', 'email', 'max:190'],
                'password' => ['required', 'string', 'max:120'],
            ]);
            $result = $auth->loginWithPassword($data['email'], $data['password']);
        } else {
            $data = $request->validate([
                'email' => ['required', 'email', 'max:190'],
                'order_number' => ['required', 'string', 'max:40'],
            ]);
            $result = $auth->loginWithOrder($data['email'], $data['order_number']);
        }

        if (! ($result['ok'] ?? false)) {
            return back()->withInput()->with('error', $result['error'] ?? 'No se pudo iniciar sesión.');
        }

        return redirect()->intended(route('buyer.dashboard'))->with('success', 'Bienvenido a tu cuenta.');
    }

    public function logout(Request $request)
    {
        Auth::guard('buyer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('buyer.login')->with('success', 'Sesión cerrada.');
    }

    public function enterFromTrack(Request $request, string $slug, BuyerPortalAuth $auth)
    {
        $token = trim((string) $request->query('token', ''));
        abort_if($token === '', 404);

        $order = Order::query()
            ->where('access_token', $token)
            ->whereHas('store', fn ($q) => $q->where('slug', $slug))
            ->firstOrFail();

        $auth->loginFromOrder($order);

        return redirect()->route('buyer.orders.show', $order)->with('success', 'Entraste con el enlace de tu pedido.');
    }
}
