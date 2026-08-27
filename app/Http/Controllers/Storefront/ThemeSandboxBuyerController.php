<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\BuyerAccount;
use App\Models\Theme;
use App\Models\ThemeSandboxOrder;
use App\Services\Buyer\BuyerPortalAuth;
use App\Services\Buyer\BuyerPortalLocale;
use App\Services\Storefront\SandboxCjFulfillmentService;
use App\Services\Storefront\ThemeSandboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ThemeSandboxBuyerController extends Controller
{
    public function __construct(
        protected ThemeSandboxService $sandbox,
        protected BuyerPortalAuth $auth,
        protected BuyerPortalLocale $locale,
        protected SandboxCjFulfillmentService $cj
    ) {}

    public function enter(Request $request, Theme $theme)
    {
        $this->locale->applyForTheme($theme);
        $number = strtoupper(trim((string) $request->query('number', '')));
        $email = strtolower(trim((string) $request->query('email', '')));

        if ($number === '' || $email === '') {
            return redirect()
                ->route('theme.sandbox.track', $theme->slug)
                ->with('error', 'Falta el número de pedido o el email.');
        }

        $result = $this->auth->loginWithSandboxOrder($theme, $email, $number);
        if (! ($result['ok'] ?? false)) {
            return redirect()
                ->route('theme.sandbox.track', $theme->slug)
                ->withInput(['number' => $number, 'email' => $email])
                ->with('error', $result['error'] ?? 'No se pudo entrar a la cuenta.');
        }

        return redirect()
            ->route('theme.sandbox.cuenta.dashboard', $theme->slug)
            ->with('success', __('buyer.common.logged_in'));
    }

    public function dashboard(Theme $theme)
    {
        [$buyer, $session, $orders] = $this->context($theme);
        $claims = $this->claimsFor($theme, $buyer->email);
        $openClaims = collect($claims)->whereIn('status', ['open', 'in_progress'])->count();
        $list = $this->syncOrders($orders->take(8));
        $pipelines = $this->pipelinesFor($list);

        return view('sandbox-buyer.dashboard', [
            'theme' => $theme,
            'buyer' => $buyer,
            'orders' => collect($list),
            'pipelines' => $pipelines,
            'claimsOpen' => $openClaims,
            'sandbox' => true,
            'currentOrderNumber' => $session['number'] ?? null,
        ]);
    }

    public function profile(Theme $theme)
    {
        [$buyer, $session, $orders] = $this->context($theme);
        $recent = $orders->first();

        return view('sandbox-buyer.profile', [
            'theme' => $theme,
            'buyer' => $buyer,
            'recent' => $recent,
            'sandbox' => true,
        ]);
    }

    public function updateProfile(Request $request, Theme $theme)
    {
        [$buyer] = $this->context($theme);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $buyer->fill($data)->save();

        return back()->with('success', __('buyer.common.saved'));
    }

    public function security(Theme $theme)
    {
        [$buyer] = $this->context($theme);

        return view('sandbox-buyer.security', [
            'theme' => $theme,
            'buyer' => $buyer,
            'sandbox' => true,
        ]);
    }

    public function updatePassword(Request $request, Theme $theme)
    {
        [$buyer] = $this->context($theme);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:120', 'confirmed'],
            'current_password' => [$buyer->hasPassword() ? 'required' : 'nullable', 'string'],
        ]);

        if ($buyer->hasPassword() && ! Hash::check((string) ($data['current_password'] ?? ''), $buyer->password)) {
            return back()->with('error', 'La contraseña actual no es correcta.');
        }

        $buyer->password = $data['password'];
        $buyer->save();

        return back()->with('success', __('buyer.common.password_saved'));
    }

    public function orders(Theme $theme)
    {
        [$buyer, , $orders] = $this->context($theme);
        $list = $this->syncOrders($orders);

        return view('sandbox-buyer.orders-index', [
            'theme' => $theme,
            'buyer' => $buyer,
            'orders' => collect($list),
            'sandbox' => true,
        ]);
    }

    public function showOrder(Theme $theme, int $order)
    {
        [$buyer] = $this->context($theme);
        $row = ThemeSandboxOrder::query()
            ->where('theme_id', $theme->id)
            ->where('id', $order)
            ->whereRaw('LOWER(email) = ?', [strtolower($buyer->email)])
            ->firstOrFail();

        $row = $this->cj->syncStatus($row, true);

        return view('sandbox-buyer.orders-show', [
            'theme' => $theme,
            'buyer' => $buyer,
            'order' => $row,
            'payload' => $row->toClientArray(),
            'steps' => $this->locale->pipelineFor($row),
            'sandbox' => true,
        ]);
    }

    public function tracking(Theme $theme)
    {
        [$buyer, , $orders] = $this->context($theme);
        $list = $this->syncOrders($orders, true);
        $pipelines = $this->pipelinesFor($list);
        $notifyPrefs = [];
        foreach ($list as $order) {
            $notifyPrefs[$order->id] = $this->notifyPrefsFor($theme, $buyer->email, $order->id);
        }

        return view('sandbox-buyer.tracking', [
            'theme' => $theme,
            'buyer' => $buyer,
            'orders' => collect($list),
            'pipelines' => $pipelines,
            'notifyPrefs' => $notifyPrefs,
            'sandbox' => true,
        ]);
    }

    public function updateTrackingNotify(Request $request, Theme $theme)
    {
        [$buyer] = $this->context($theme);
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'in:confirmed,preparing,warehouse,shipped,delivered'],
        ]);

        $order = ThemeSandboxOrder::query()
            ->where('theme_id', $theme->id)
            ->where('id', (int) $data['order_id'])
            ->whereRaw('LOWER(email) = ?', [strtolower($buyer->email)])
            ->firstOrFail();

        $allowed = $this->locale->pipelineKeys();
        $selected = array_values(array_intersect($allowed, $data['statuses'] ?? []));
        $prefs = array_fill_keys($allowed, false);
        foreach ($selected as $key) {
            $prefs[$key] = true;
        }

        session([$this->notifyKey($theme, $buyer->email, $order->id) => $prefs]);

        return redirect()
            ->route('theme.sandbox.cuenta.tracking', $theme->slug)
            ->with('success', __('buyer.tracking.notify_saved'));
    }

    public function claims(Theme $theme)
    {
        [$buyer, , $orders] = $this->context($theme);
        $claims = $this->claimsFor($theme, $buyer->email);

        return view('sandbox-buyer.claims-index', [
            'theme' => $theme,
            'buyer' => $buyer,
            'orders' => $orders,
            'claims' => $claims,
            'sandbox' => true,
        ]);
    }

    public function storeClaim(Request $request, Theme $theme)
    {
        [$buyer] = $this->context($theme);
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $row = ThemeSandboxOrder::query()
            ->where('theme_id', $theme->id)
            ->where('id', (int) $data['order_id'])
            ->whereRaw('LOWER(email) = ?', [strtolower($buyer->email)])
            ->firstOrFail();

        $claims = $this->claimsFor($theme, $buyer->email);
        $claims[] = [
            'id' => 'sb-'.uniqid(),
            'order_id' => $row->id,
            'order_number' => $row->number,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'status' => 'open',
            'created_at' => now()->toDateTimeString(),
        ];
        $this->saveClaims($theme, $buyer->email, $claims);

        return redirect()
            ->route('theme.sandbox.cuenta.claims', $theme->slug)
            ->with('success', __('buyer.common.claim_saved'));
    }

    public function showClaim(Theme $theme, string $claim)
    {
        [$buyer] = $this->context($theme);
        $claims = $this->claimsFor($theme, $buyer->email);
        $found = collect($claims)->firstWhere('id', $claim);
        abort_unless($found, 404);

        return view('sandbox-buyer.claims-show', [
            'theme' => $theme,
            'buyer' => $buyer,
            'claim' => $found,
            'sandbox' => true,
        ]);
    }

    public function logout(Theme $theme)
    {
        session()->forget('theme_sandbox_buyer.'.$theme->id);
        // No invalidar toda la sesión admin; solo salir del portal sandbox
        if (Auth::guard('buyer')->check()) {
            // Mantener buyer guard si también usa cuenta real; ok dejar logueado
        }

        return redirect()
            ->route('theme.sandbox.track', $theme->slug)
            ->with('success', __('buyer.common.logged_out'));
    }

    /**
     * @return array{0: BuyerAccount, 1: array<string, mixed>, 2: \Illuminate\Support\Collection<int, ThemeSandboxOrder>}
     */
    protected function context(Theme $theme): array
    {
        $this->locale->applyForTheme($theme);
        $session = session('theme_sandbox_buyer.'.$theme->id);
        abort_unless(is_array($session) && ! empty($session['email']), 403);

        $buyer = Auth::guard('buyer')->user();
        if (! $buyer || strtolower($buyer->email) !== strtolower((string) $session['email'])) {
            $buyer = BuyerAccount::query()->firstOrCreate(
                ['email' => strtolower((string) $session['email'])],
                ['name' => $session['name'] ?? null, 'phone' => $session['phone'] ?? null]
            );
            Auth::guard('buyer')->login($buyer, true);
        }

        $orders = ThemeSandboxOrder::query()
            ->where('theme_id', $theme->id)
            ->whereRaw('LOWER(email) = ?', [strtolower($buyer->email)])
            ->orderByDesc('id')
            ->get();

        return [$buyer, $session, $orders];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function claimsFor(Theme $theme, string $email): array
    {
        $raw = session($this->claimsKey($theme, $email), []);

        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $claims
     */
    protected function saveClaims(Theme $theme, string $email, array $claims): void
    {
        session([$this->claimsKey($theme, $email) => array_values($claims)]);
    }

    protected function claimsKey(Theme $theme, string $email): string
    {
        return 'theme_sandbox_claims.'.$theme->id.'.'.md5(strtolower($email));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ThemeSandboxOrder>|\Illuminate\Database\Eloquent\Collection<int, ThemeSandboxOrder>|iterable<ThemeSandboxOrder>  $orders
     * @return array<int, list<array<string, mixed>>>
     */
    protected function pipelinesFor(iterable $orders): array
    {
        $out = [];
        foreach ($orders as $order) {
            $out[$order->id] = $this->locale->pipelineFor($order);
        }

        return $out;
    }

    /**
     * Consulta CJ y actualiza fulfillment/tracking antes de mostrar el portal.
     *
     * @param  iterable<ThemeSandboxOrder>  $orders
     * @return list<ThemeSandboxOrder>
     */
    protected function syncOrders(iterable $orders, bool $force = false): array
    {
        return $this->cj->syncMany($orders, $force);
    }

    /**
     * @return array<string, bool>
     */
    protected function notifyPrefsFor(Theme $theme, string $email, int $orderId): array
    {
        $raw = session($this->notifyKey($theme, $email, $orderId), []);
        $prefs = array_fill_keys($this->locale->pipelineKeys(), false);
        if (! is_array($raw)) {
            return $prefs;
        }
        foreach ($prefs as $key => $_) {
            $prefs[$key] = ! empty($raw[$key]);
        }

        return $prefs;
    }

    protected function notifyKey(Theme $theme, string $email, int $orderId): string
    {
        return 'theme_sandbox_notify.'.$theme->id.'.'.md5(strtolower($email)).'.'.$orderId;
    }
}
