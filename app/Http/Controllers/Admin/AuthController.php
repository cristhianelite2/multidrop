<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\CloudflareAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(Request $request, CloudflareAccessService $cloudflare)
    {
        if ($cloudflare->isEnabled()) {
            $cfUser = $cloudflare->resolveUser($request);
            if ($cfUser) {
                Auth::login($cfUser);
                $cfUser->markLogin($request->ip());
                $request->session()->regenerate();

                return redirect()->intended(route('admin.dashboard'));
            }
        }

        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login', [
            'cloudflareEnabled' => $cloudflare->isEnabled(),
            'cloudflareRequired' => $cloudflare->isRequired(),
        ]);
    }

    public function login(Request $request, CloudflareAccessService $cloudflare)
    {
        if ($cloudflare->isRequired()) {
            throw ValidationException::withMessages([
                'email' => 'El acceso local está deshabilitado. Entra con Cloudflare Access.',
            ]);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $remember)) {
            throw ValidationException::withMessages([
                'email' => 'Credenciales incorrectas o cuenta inactiva.',
            ]);
        }

        $request->session()->regenerate();
        Auth::user()?->markLogin($request->ip());

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
