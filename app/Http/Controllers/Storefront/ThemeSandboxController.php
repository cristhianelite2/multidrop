<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Services\Buyer\BuyerPortalAuth;
use App\Services\Storefront\DesignThemeService;
use App\Services\Storefront\ThemeSandboxRenderer;
use App\Services\Storefront\ThemeSandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ThemeSandboxController extends Controller
{
    public function __construct(
        protected ThemeSandboxService $sandbox,
        protected ThemeSandboxRenderer $renderer,
        protected DesignThemeService $themes,
        protected BuyerPortalAuth $buyerAuth
    ) {}

    public function show(Request $request, Theme $theme): Response
    {
        $this->sandbox->absorbModulesFromRequest($theme, $request);

        return $this->renderer->response($theme, ['handle' => 'index']);
    }

    public function page(Request $request, Theme $theme, string $handle): Response
    {
        $this->sandbox->absorbModulesFromRequest($theme, $request);

        $design = $this->themes->normalizeDesign(
            is_array($theme->design) ? $theme->design : [],
            $theme->name
        );
        $reserved = ['catalog', 'cart', 'checkout', 'index', 'product', 'about', 'faq', 'page'];

        $productPage = $this->themes->findPageByType($design, 'product', false);

        if ($handle === 'product') {
            $sample = $this->sandbox->demoProducts($theme)->first();

            return $this->renderer->response($theme, [
                'page_id' => $productPage['id'] ?? null,
                'handle' => 'product',
                'product' => $sample,
            ]);
        }

        if ($productPage && ! in_array($handle, $reserved, true)) {
            $product = $this->sandbox->findProduct($theme, $handle);
            if ($product) {
                return $this->renderer->response($theme, [
                    'page_id' => $productPage['id'] ?? null,
                    'handle' => 'product',
                    'product' => $product,
                ]);
            }
        }

        return $this->renderer->response($theme, ['handle' => $handle]);
    }

    public function products(Theme $theme): JsonResponse
    {
        return response()->json([
            'store' => [
                'id' => 0,
                'name' => $theme->name,
                'slug' => $theme->slug,
            ],
            'sandbox' => true,
            'products' => $this->sandbox->demoProducts($theme)->values()->all(),
        ]);
    }

    public function cartShow(Theme $theme): JsonResponse
    {
        return response()->json(['ok' => true, 'cart' => $this->sandbox->cart($theme)]);
    }

    public function cartAdd(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $cart = $this->sandbox->addToCart($theme, (int) $data['product_id'], (int) ($data['qty'] ?? 1));

        return response()->json(['ok' => true, 'cart' => $cart, 'message' => 'Agregado al carrito (sandbox)']);
    }

    public function cartUpdate(Request $request, Theme $theme, int $product): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $cart = $this->sandbox->updateCartItem($theme, $product, (int) $data['qty']);

        return response()->json(['ok' => true, 'cart' => $cart]);
    }

    public function cartRemove(Theme $theme, int $product): JsonResponse
    {
        $cart = $this->sandbox->removeCartItem($theme, $product);

        return response()->json(['ok' => true, 'cart' => $cart]);
    }

    public function cartCoupon(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);
        $code = strtoupper(trim($data['code']));
        $cart = $this->sandbox->applyCoupon($theme, $code);
        $ok = ! empty($cart['coupon']) && (float) ($cart['totals']['discount'] ?? 0) > 0;
        $pct = $code === 'SAVE15' ? '15%' : '10%';

        return response()->json([
            'ok' => $ok,
            'cart' => $cart,
            'message' => $ok
                ? ('Cupón '.$cart['coupon'].' aplicado (−'.$pct.').')
                : 'Cupón no válido. En sandbox prueba DEMO10, SAVE10 o SAVE15.',
        ], $ok ? 200 : 422);
    }

    public function cartClearCoupon(Theme $theme): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'cart' => $this->sandbox->clearCoupon($theme),
            'message' => 'Cupón quitado.',
        ]);
    }

    public function cartShipping(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'max:8'],
        ]);
        $result = $this->sandbox->setShippingCountry($theme, $data['country']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function cartCrossSell(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
        $result = $this->sandbox->addMagicCrossSell(
            $theme,
            (int) $data['product_id'],
            (int) ($data['qty'] ?? 1)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function cartUpsell(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
        ]);
        $result = $this->sandbox->acceptUpsell(
            $theme,
            isset($data['product_id']) ? (int) $data['product_id'] : null
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function newsletterSubscribe(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);
        $cfg = app(\App\Services\Commerce\NewsletterService::class)->forSandbox();
        $token = \Illuminate\Support\Str::random(24);
        session([
            'sandbox_nl.'.$theme->id.'.'.$token => [
                'email' => strtolower($data['email']),
                'at' => now()->timestamp,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Sandbox: confirma con el enlace (no se envía correo real).',
            'confirm_url' => route('theme.sandbox.newsletter.confirm', [
                'theme' => $theme->slug,
                'token' => $token,
            ]),
            'coupon_hint' => $cfg['coupon_hint'],
            'coupon_days' => $cfg['coupon_days'],
        ]);
    }

    public function newsletterConfirm(Theme $theme, string $token)
    {
        $key = 'sandbox_nl.'.$theme->id.'.'.$token;
        $payload = session($key);
        $cfg = app(\App\Services\Commerce\NewsletterService::class)->forSandbox();
        if (! is_array($payload)) {
            return view('storefront.newsletter-confirm', [
                'store' => (object) ['name' => $theme->name, 'slug' => $theme->slug],
                'ok' => false,
                'message' => 'Enlace sandbox inválido o expirado.',
                'couponCode' => null,
                'couponHint' => null,
                'days' => null,
                'expires' => null,
                'shopUrl' => route('theme.sandbox.show', $theme->slug),
            ]);
        }
        session()->forget($key);
        $code = strtoupper(($cfg['coupon_prefix'] ?? 'NL').'-SB'.strtoupper(\Illuminate\Support\Str::random(4)));

        return view('storefront.newsletter-confirm', [
            'store' => (object) ['name' => $theme->name, 'slug' => $theme->slug],
            'ok' => true,
            'message' => 'Sandbox confirmado. Cupón de prueba generado.',
            'couponCode' => $code,
            'couponHint' => $cfg['coupon_hint'],
            'days' => $cfg['coupon_days'],
            'expires' => now()->addDays((int) $cfg['coupon_days'])->format('d/m/Y'),
            'shopUrl' => route('theme.sandbox.show', $theme->slug),
        ]);
    }

    public function checkout(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'tel' => ['nullable', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:250'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip' => ['nullable', 'string', 'max:20'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:500'],
            'newsletter_opt_in' => ['nullable', 'boolean'],
        ]);

        $data['name'] = trim((string) ($data['name'] ?? ''));
        if ($data['name'] === '') {
            $data['name'] = trim(trim((string) ($data['first_name'] ?? '')).' '.trim((string) ($data['last_name'] ?? '')));
        }
        if ($data['name'] === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Indica tu nombre.',
            ], 422);
        }
        $data['phone'] = (string) ($data['phone'] ?: ($data['tel'] ?? ''));
        $data['zip'] = (string) ($data['zip'] ?: ($data['postal_code'] ?? ''));

        $result = $this->sandbox->placeOrder($theme, $data);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $result['error'] ?? 'No se pudo crear el pedido sandbox.',
            ], 422);
        }

        $newsletterCoupon = null;
        if ($request->boolean('newsletter_opt_in')) {
            $cfg = app(\App\Services\Commerce\NewsletterService::class)->forSandbox();
            $newsletterCoupon = strtoupper(($cfg['coupon_prefix'] ?? 'NL').'-SB'.strtoupper(\Illuminate\Support\Str::random(4)));
            session(['sandbox_nl_checkout.'.$theme->id => [
                'email' => strtolower($data['email']),
                'code' => $newsletterCoupon,
                'hint' => $cfg['coupon_hint'],
                'days' => $cfg['coupon_days'],
            ]]);
        }

        return response()->json([
            'ok' => true,
            'order_number' => $result['order']['number'] ?? null,
            'confirm_url' => $result['confirm_url'] ?? null,
            'track_url' => $result['track_url'] ?? null,
            'checkout_url' => null,
            'newsletter_coupon' => $newsletterCoupon,
            'cj_status' => $result['order']['fulfillment_status'] ?? null,
            'message' => 'Pago simulado. Pedido enviado a CJ Dropshipping.',
        ]);
    }

    public function confirm(Request $request, Theme $theme): View
    {
        $number = strtoupper(trim((string) $request->query('number', '')));
        $email = strtolower(trim((string) $request->query('email', '')));
        $order = null;
        $error = null;

        if ($number === '' || $email === '') {
            $error = 'Falta el número de pedido o el email.';
        } else {
            $order = $this->sandbox->findOrder($theme, $number, $email);
            if (! $order) {
                $error = 'No encontramos ese pedido sandbox.';
            }
        }

        return view('storefront.theme-confirm', [
            'theme' => $theme,
            'order' => $order,
            'error' => $error,
            'number' => $number,
            'email' => $email,
            'debugAdmin' => (bool) config('multidrop.sandbox_cj_debug'),
        ]);
    }

    public function track(Request $request, Theme $theme)
    {
        $sessionKey = $this->sandboxBuyerSessionKey($theme);
        $number = strtoupper(trim((string) $request->query('number', $request->input('number', ''))));
        $email = strtolower(trim((string) $request->query('email', $request->input('email', ''))));
        $wantsLogin = $request->boolean('login') || ($number !== '' && $email !== '');

        // Entrar al admin de comprador (sandbox) con email + número
        if ($wantsLogin && $number !== '' && $email !== '') {
            $result = $this->buyerAuth->loginWithSandboxOrder($theme, $email, $number);
            if ($result['ok'] ?? false) {
                return redirect()
                    ->route('theme.sandbox.cuenta.dashboard', $theme->slug)
                    ->with('success', 'Entraste a tu cuenta de comprador (sandbox).');
            }

            return view('storefront.theme-track', [
                'theme' => $theme,
                'order' => null,
                'error' => $result['error'] ?? 'No encontramos un pedido sandbox con esos datos.',
                'number' => $number,
                'email' => $email,
                'loggedIn' => false,
            ]);
        }

        $session = session($sessionKey);
        if (is_array($session) && ! empty($session['email']) && ! empty($session['number'])) {
            return redirect()->route('theme.sandbox.cuenta.dashboard', $theme->slug);
        }

        return view('storefront.theme-track', [
            'theme' => $theme,
            'order' => null,
            'error' => null,
            'number' => $number,
            'email' => $email,
            'loggedIn' => false,
        ]);
    }

    public function trackLookup(Request $request, Theme $theme)
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        $number = strtoupper(trim($data['number']));
        $email = strtolower(trim($data['email']));
        $result = $this->buyerAuth->loginWithSandboxOrder($theme, $email, $number);

        if (! ($result['ok'] ?? false)) {
            return redirect()
                ->route('theme.sandbox.track', $theme->slug)
                ->withInput($data)
                ->with('error', $result['error'] ?? 'No encontramos un pedido sandbox con esos datos.');
        }

        return redirect()
            ->route('theme.sandbox.cuenta.dashboard', $theme->slug)
            ->with('success', 'Entraste a tu cuenta de comprador (sandbox).');
    }

    public function trackLogout(Theme $theme)
    {
        session()->forget($this->sandboxBuyerSessionKey($theme));

        return redirect()->route('theme.sandbox.track', $theme->slug);
    }

    protected function sandboxBuyerSessionKey(Theme $theme): string
    {
        return 'theme_sandbox_buyer.'.$theme->id;
    }
}
