<?php

namespace App\Http\Middleware;

use App\Models\Theme;
use App\Services\Buyer\BuyerPortalLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSandboxBuyer
{
    public function __construct(protected BuyerPortalLocale $locale) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Theme|null $theme */
        $theme = $request->route('theme');
        if (! $theme instanceof Theme) {
            abort(404);
        }

        $this->locale->applyForTheme($theme);

        $session = session('theme_sandbox_buyer.'.$theme->id);
        if (! is_array($session) || empty($session['email']) || empty($session['number'])) {
            return redirect()
                ->route('theme.sandbox.track', $theme->slug)
                ->with('error', __('buyer.auth.required'));
        }

        $request->attributes->set('sandbox_buyer_session', $session);

        return $next($request);
    }
}
