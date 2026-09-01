<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AI\AiTaskRouter;
use App\Domain\Scraping\CloudflareBrowserRenderer;
use App\Domain\Suppliers\AliExpress\AliExpressAffiliateClient;
use App\Domain\Suppliers\Cj\CjConnector;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderClaim;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Services\Currency\CurrencyService;
use App\Services\Platform\PlatformContact;
use App\Services\Platform\PlatformMailSettings;
use App\Services\Security\TurnstileVerifier;
use App\Services\Storage\MediaUrl;
use App\Services\Storage\R2StorageManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class GeneralSettingsController extends Controller
{
    public function edit(CjConnector $cj, CurrencyService $currency, TurnstileVerifier $turnstile, PlatformContact $contact, PlatformMailSettings $mail, AiTaskRouter $aiRouter)
    {
        $today = Carbon::now()->startOfDay();
        $last30 = Carbon::now()->subDays(30);

        $payments = [
            'stripe_key' => PlatformSetting::getValue('payments.stripe.key', config('payments.stripe.key')),
            'stripe_secret' => PlatformSetting::getValue('payments.stripe.secret') ? '********' : '',
            'stripe_webhook_secret' => PlatformSetting::getValue('payments.stripe.webhook_secret') ? '********' : '',
            'paypal_client_id' => PlatformSetting::getValue('payments.paypal.client_id', config('payments.paypal.client_id')),
            'paypal_client_secret' => PlatformSetting::getValue('payments.paypal.client_secret') ? '********' : '',
            'paypal_mode' => PlatformSetting::getValue('payments.paypal.mode', config('payments.paypal.mode', 'sandbox')),
            'mp_public_key' => PlatformSetting::getValue('payments.mercadopago.public_key', config('payments.mercadopago.public_key')),
            'mp_access_token' => PlatformSetting::getValue('payments.mercadopago.access_token') ? '********' : '',
            'mp_webhook_secret' => PlatformSetting::getValue('payments.mercadopago.webhook_secret') ? '********' : '',
        ];

        $hasDb = [
            'stripe_secret' => (bool) PlatformSetting::getValue('payments.stripe.secret'),
            'stripe_webhook_secret' => (bool) PlatformSetting::getValue('payments.stripe.webhook_secret'),
            'paypal_client_secret' => (bool) PlatformSetting::getValue('payments.paypal.client_secret'),
            'mp_access_token' => (bool) PlatformSetting::getValue('payments.mercadopago.access_token'),
            'mp_webhook_secret' => (bool) PlatformSetting::getValue('payments.mercadopago.webhook_secret'),
            'cj_api_key' => (bool) PlatformSetting::getValue('cj.api_key') || (bool) config('cj.api_key'),
            'cj_access_token' => (bool) PlatformSetting::getValue('cj.access_token') || (bool) config('cj.access_token'),
            'openai_api_key' => (bool) PlatformSetting::getValue('ai.openai.api_key') || (bool) config('ai.providers.openai.api_key'),
            'miia_api_key' => (bool) PlatformSetting::getValue('ai.miia.api_key') || (bool) config('ai.providers.miia.api_key'),
            'turnstile_secret' => (bool) PlatformSetting::getValue('cloudflare.turnstile.secret_key') || (bool) config('cloudflare.turnstile.secret_key'),
            'turnstile_site' => (bool) ($turnstile->siteKey()),
            'resend_api_key' => (bool) PlatformSetting::getValue('platform.mail.resend_api_key') || (bool) config('services.resend.key'),
            'aliexpress_app_key' => (bool) PlatformSetting::getValue('aliexpress.app_key') || (bool) config('aliexpress.app_key'),
            'aliexpress_app_secret' => (bool) PlatformSetting::getValue('aliexpress.app_secret') || (bool) config('aliexpress.app_secret'),
            'cf_api_token' => (bool) PlatformSetting::getValue('cloudflare.api_token') || (bool) config('cloudflare.api_token'),
            'cf_account_id' => (bool) PlatformSetting::getValue('cloudflare.account_id') || (bool) config('cloudflare.account_id'),
        ];

        $accessToken = PlatformSetting::getValue('cj.access_token', config('cj.access_token'));

        $cjData = [
            'email' => PlatformSetting::getValue('cj.email', config('cj.email')),
            'api_key' => $hasDb['cj_api_key'] ? '********' : '',
            'authorized_at' => PlatformSetting::getValue('cj.authorized_at'),
            'has_access_token' => $hasDb['cj_access_token'],
            'mcp_url' => $cj->mcpServerUrl($accessToken),
            'mcp_url_masked' => $this->maskMcpUrl($cj->mcpServerUrl($accessToken)),
            'last_test_at' => PlatformSetting::getValue('cj.last_test_at'),
            'last_test_ok' => filter_var(PlatformSetting::getValue('cj.last_test_ok', '0'), FILTER_VALIDATE_BOOLEAN),
            'last_test_message' => PlatformSetting::getValue('cj.last_test_message'),
        ];

        $aliexpress = [
            'app_key' => $hasDb['aliexpress_app_key'] ? '********' : '',
            'app_secret' => $hasDb['aliexpress_app_secret'] ? '********' : '',
            'tracking_id' => PlatformSetting::getValue('aliexpress.tracking_id', config('aliexpress.tracking_id')),
            'ship_to' => PlatformSetting::getValue('aliexpress.ship_to', config('aliexpress.ship_to', 'MX')) ?: 'MX',
            'has_app_key' => $hasDb['aliexpress_app_key'],
            'has_app_secret' => $hasDb['aliexpress_app_secret'],
            'last_test_at' => PlatformSetting::getValue('aliexpress.last_test_at'),
            'last_test_ok' => filter_var(PlatformSetting::getValue('aliexpress.last_test_ok', '0'), FILTER_VALIDATE_BOOLEAN),
            'last_test_message' => PlatformSetting::getValue('aliexpress.last_test_message'),
            'test_product_id' => config('aliexpress.test_product_id'),
        ];

        $cfBrowser = [
            'account_id' => PlatformSetting::getValue('cloudflare.account_id', config('cloudflare.account_id')) ?: '',
            'api_token' => $hasDb['cf_api_token'] ? '********' : '',
            'enabled' => (bool) config('cloudflare.enabled'),
            'has_token' => $hasDb['cf_api_token'],
            'has_account' => $hasDb['cf_account_id'] || trim((string) config('cloudflare.account_id')) !== '',
            'ready' => app(CloudflareBrowserRenderer::class)->enabled(),
            'last_test_at' => PlatformSetting::getValue('cloudflare.browser_last_test_at'),
            'last_test_ok' => filter_var(PlatformSetting::getValue('cloudflare.browser_last_test_ok', '0'), FILTER_VALIDATE_BOOLEAN),
            'last_test_message' => PlatformSetting::getValue('cloudflare.browser_last_test_message'),
            'docs' => config('cloudflare.docs.browser_rendering'),
        ];

        $r2Manager = app(R2StorageManager::class);
        $r2Manager->applyFromPlatformSettings();
        $r2Account = trim((string) (PlatformSetting::getValue('storage.r2.account_id') ?: PlatformSetting::getValue('cloudflare.account_id') ?: config('r2.account_id')));
        $r2Storage = [
            'enabled' => $r2Manager->enabled(),
            'account_id' => $r2Account,
            'access_key_id' => (bool) PlatformSetting::getValue('storage.r2.access_key_id') ? '********' : '',
            'secret_access_key' => (bool) PlatformSetting::getValue('storage.r2.secret_access_key') ? '********' : '',
            'bucket' => PlatformSetting::getValue('storage.r2.bucket', config('r2.bucket')) ?: '',
            'endpoint' => PlatformSetting::getValue('storage.r2.endpoint', config('r2.endpoint')) ?: ($r2Account !== '' ? 'https://'.$r2Account.'.r2.cloudflarestorage.com' : ''),
            'has_access_key' => (bool) PlatformSetting::getValue('storage.r2.access_key_id'),
            'has_secret' => (bool) PlatformSetting::getValue('storage.r2.secret_access_key'),
            'configured' => $r2Manager->configured(),
            'ready' => $r2Manager->enabled(),
            'public_prefix' => MediaUrl::prefix(),
            'last_test_at' => PlatformSetting::getValue('storage.r2.last_test_at'),
            'last_test_ok' => filter_var(PlatformSetting::getValue('storage.r2.last_test_ok', '0'), FILTER_VALIDATE_BOOLEAN),
            'last_test_message' => PlatformSetting::getValue('storage.r2.last_test_message'),
            'docs' => config('r2.docs', []),
        ];

        $aiEngines = $aiRouter->listEngines();
        $ai = [
            'miia_api_key' => $hasDb['miia_api_key'] ? '********' : '',
            'miia_base_url' => PlatformSetting::getValue('ai.miia.base_url', config('ai.providers.miia.base_url')),
            'miia_model' => PlatformSetting::getValue('ai.miia.model', config('ai.providers.miia.model')),
            'engines' => $aiEngines,
            'engines_chat' => $aiRouter->enginesForKind('chat', $aiEngines),
            'engines_image' => $aiRouter->enginesForKind('image', $aiEngines),
            'tasks' => $this->aiTasksForView($aiRouter, $aiEngines),
            'engines_url' => route('admin.settings.general.ai.engines'),
        ];

        $security = [
            'turnstile_site_key' => PlatformSetting::getValue('cloudflare.turnstile.site_key', config('cloudflare.turnstile.site_key')),
            'turnstile_secret_key' => $hasDb['turnstile_secret'] ? '********' : '',
            'bot_fight_ack' => filter_var(PlatformSetting::getValue('cloudflare.bot_fight_ack', '0'), FILTER_VALIDATE_BOOLEAN),
            'ai_crawl_ack' => filter_var(PlatformSetting::getValue('cloudflare.ai_crawl_ack', '0'), FILTER_VALIDATE_BOOLEAN),
            'access_enabled' => (bool) config('cloudflare.access.enabled'),
            'turnstile_ready' => $turnstile->enabled(),
            'browser_ready' => $cfBrowser['ready'],
            'docs' => config('cloudflare.docs', []),
            'max_orders_per_hour' => (int) PlatformSetting::getValue(
                'fraud.max_orders_per_hour',
                config('multidrop.fraud.max_orders_per_hour', 8)
            ),
        ];

        $mailStatus = $mail->status();

        $stores = Store::query()
            ->active()
            ->with('market:id,code')
            ->orderBy('store_type')
            ->orderBy('name')
            ->get(['id', 'name', 'store_type', 'market_id', 'settings']);

        $ordersByStore = Order::query()
            ->selectRaw('store_id')
            ->selectRaw('COUNT(*) as orders_total')
            ->selectRaw("SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as orders_today", [$today])
            ->selectRaw("SUM(CASE WHEN created_at >= ? AND payment_status='paid' THEN 1 ELSE 0 END) as paid_today", [$today])
            ->selectRaw("SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as orders_30", [$last30])
            ->selectRaw("SUM(CASE WHEN created_at >= ? AND payment_status='paid' THEN 1 ELSE 0 END) as paid_30", [$last30])
            ->selectRaw("SUM(CASE WHEN created_at >= ? AND payment_status='paid' THEN total ELSE 0 END) as revenue_30", [$last30])
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $claimsByStore = OrderClaim::query()
            ->selectRaw('store_id, COUNT(*) as open_claims')
            ->whereIn('status', ['open', 'in_progress'])
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $storeSalesRows = $stores->map(function (Store $store) use ($ordersByStore, $claimsByStore, $r2Manager) {
            $orderAgg = $ordersByStore->get($store->id);
            $orders30 = (int) ($orderAgg->orders_30 ?? 0);
            $paid30 = (int) ($orderAgg->paid_30 ?? 0);
            $r2Bytes = (int) data_get($store->settings, 'storage.r2_bytes', 0);

            return [
                'id' => $store->id,
                'name' => $store->name,
                'type' => $store->store_type ?: 'mini',
                'market' => $store->market?->code ?? '—',
                'orders_today' => (int) ($orderAgg->orders_today ?? 0),
                'paid_today' => (int) ($orderAgg->paid_today ?? 0),
                'orders_30' => $orders30,
                'paid_30' => $paid30,
                'revenue_30' => (float) ($orderAgg->revenue_30 ?? 0),
                'open_claims' => (int) ($claimsByStore->get($store->id)->open_claims ?? 0),
                'conversion_paid_30' => $orders30 > 0 ? round(($paid30 / $orders30) * 100, 1) : 0.0,
                'r2_bytes' => $r2Bytes,
                'r2_files' => (int) data_get($store->settings, 'storage.r2_files', 0),
                'r2_images' => (int) data_get($store->settings, 'storage.r2_images', 0),
                'r2_videos' => (int) data_get($store->settings, 'storage.r2_videos', 0),
                'r2_synced_at' => data_get($store->settings, 'storage.r2_synced_at'),
                'r2_human' => $r2Manager->formatBytes($r2Bytes),
            ];
        })->sortByDesc('paid_today')->values();

        $salesByType = collect(['mini', 'mega'])->mapWithKeys(function (string $type) use ($storeSalesRows) {
            $items = $storeSalesRows->where('type', $type);

            return [$type => [
                'stores' => $items->count(),
                'new_sales' => (int) $items->sum('paid_today'),
                'orders_30' => (int) $items->sum('orders_30'),
                'paid_30' => (int) $items->sum('paid_30'),
                'revenue_30' => (float) $items->sum('revenue_30'),
                'open_claims' => (int) $items->sum('open_claims'),
            ]];
        });

        return view('admin.settings.general', [
            'payments' => $payments,
            'hasDb' => $hasDb,
            'cj' => $cjData,
            'aliexpress' => $aliexpress,
            'cfBrowser' => $cfBrowser,
            'r2Storage' => $r2Storage,
            'ai' => $ai,
            'security' => $security,
            'contact' => $contact->all(),
            'mail' => [
                'driver' => $mailStatus['driver'],
                'resend_api_key' => $hasDb['resend_api_key'] ? '********' : '',
                'from_address' => $mailStatus['from_address'],
                'from_name' => $mailStatus['from_name'],
                'ready' => $mailStatus['ready'],
            ],
            'currency' => [
                'base' => $currency->base(),
                'catalog' => $currency->catalog(),
                'rounding_modes' => CurrencyService::ROUNDING_MODES,
                'updated_at' => $currency->updatedAt(),
                'fetch_url' => route('admin.settings.general.currency.fetch'),
            ],
            'pixels' => [
                'ga_measurement_id' => PlatformSetting::getValue('marketing.ga_measurement_id', ''),
                'meta_pixel_id' => PlatformSetting::getValue('marketing.meta_pixel_id', ''),
            ],
            'commerce' => [
                'sales_by_type' => $salesByType,
                'stores' => $storeSalesRows,
                'ga_tracking_on' => (bool) PlatformSetting::getValue('marketing.ga_measurement_id', ''),
            ],
        ]);
    }

    public function update(Request $request, CurrencyService $currency, PlatformContact $contact, PlatformMailSettings $mail, AiTaskRouter $aiRouter)
    {
        $data = $request->validate([
            'stripe_key' => ['nullable', 'string', 'max:255'],
            'stripe_secret' => ['nullable', 'string', 'max:255'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:255'],
            'paypal_client_id' => ['nullable', 'string', 'max:255'],
            'paypal_client_secret' => ['nullable', 'string', 'max:255'],
            'paypal_mode' => ['required', Rule::in(['sandbox', 'live'])],
            'mp_public_key' => ['nullable', 'string', 'max:255'],
            'mp_access_token' => ['nullable', 'string', 'max:255'],
            'mp_webhook_secret' => ['nullable', 'string', 'max:255'],
            'miia_api_key' => ['nullable', 'string', 'max:500'],
            'miia_base_url' => ['nullable', 'string', 'max:255'],
            'miia_model' => ['nullable', 'string', 'max:120'],
            'ai_task_engines' => ['nullable', 'array'],
            'ai_task_engines.*' => ['nullable', 'string', 'max:120'],
            'currency_base' => ['required', 'string', 'size:3'],
            'fx_rates' => ['nullable', 'array'],
            'fx_rates.*' => ['nullable', 'numeric', 'min:0'],
            'fx_rounding' => ['nullable', 'array'],
            'fx_rounding.*' => ['nullable', 'string', Rule::in(array_keys(CurrencyService::ROUNDING_MODES))],
            'turnstile_site_key' => ['nullable', 'string', 'max:255'],
            'turnstile_secret_key' => ['nullable', 'string', 'max:255'],
            'bot_fight_ack' => ['nullable', 'boolean'],
            'ai_crawl_ack' => ['nullable', 'boolean'],
            'fraud_max_orders_per_hour' => ['nullable', 'integer', 'min:1', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_whatsapp' => ['nullable', 'string', 'max:40'],
            'contact_hours' => ['nullable', 'string', 'max:120'],
            'contact_note' => ['nullable', 'string', 'max:500'],
            'mail_driver' => ['required', Rule::in(['resend', 'log', 'smtp', 'array'])],
            'resend_api_key' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:190'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'ga_measurement_id' => ['nullable', 'string', 'max:40'],
            'meta_pixel_id' => ['nullable', 'string', 'max:40'],
        ]);

        PlatformSetting::put('payments.stripe.key', $data['stripe_key'] ?? null, 'payments');
        PlatformSetting::put('payments.paypal.client_id', $data['paypal_client_id'] ?? null, 'payments');
        PlatformSetting::put('payments.paypal.mode', $data['paypal_mode'], 'payments');
        PlatformSetting::put('payments.mercadopago.public_key', $data['mp_public_key'] ?? null, 'payments');

        $this->putSecretIfPresent('payments.stripe.secret', $data['stripe_secret'] ?? null);
        $this->putSecretIfPresent('payments.stripe.webhook_secret', $data['stripe_webhook_secret'] ?? null);
        $this->putSecretIfPresent('payments.paypal.client_secret', $data['paypal_client_secret'] ?? null);
        $this->putSecretIfPresent('payments.mercadopago.access_token', $data['mp_access_token'] ?? null);
        $this->putSecretIfPresent('payments.mercadopago.webhook_secret', $data['mp_webhook_secret'] ?? null);

        PlatformSetting::put('ai.miia.base_url', $data['miia_base_url'] ?: 'https://ia.ceballosleon.com', 'ai');
        PlatformSetting::put('ai.miia.model', $data['miia_model'] ?: 'auto', 'ai');
        $this->putSecretIfPresent('ai.miia.api_key', $data['miia_api_key'] ?? null, 'ai');

        $taskMap = [];
        foreach (array_keys(config('ai.tasks', [])) as $taskKey) {
            $taskMap[$taskKey] = $aiRouter->sanitizeEngineForTask(
                $taskKey,
                trim((string) ($data['ai_task_engines'][$taskKey] ?? ''))
            );
        }
        PlatformSetting::put('ai.task_engines', json_encode($taskMap, JSON_UNESCAPED_UNICODE), 'ai');
        config(['ai.task_engines' => $taskMap]);

        PlatformSetting::put('cloudflare.turnstile.site_key', $data['turnstile_site_key'] ?? null, 'cloudflare');
        $this->putSecretIfPresent('cloudflare.turnstile.secret_key', $data['turnstile_secret_key'] ?? null, 'cloudflare');
        PlatformSetting::put('cloudflare.bot_fight_ack', $request->boolean('bot_fight_ack') ? '1' : '0', 'cloudflare');
        PlatformSetting::put('cloudflare.ai_crawl_ack', $request->boolean('ai_crawl_ack') ? '1' : '0', 'cloudflare');
        PlatformSetting::put(
            'fraud.max_orders_per_hour',
            (string) ($data['fraud_max_orders_per_hour'] ?? config('multidrop.fraud.max_orders_per_hour', 8)),
            'fraud'
        );

        $contact->save([
            'email' => $data['contact_email'] ?? null,
            'phone' => $data['contact_phone'] ?? null,
            'whatsapp' => $data['contact_whatsapp'] ?? null,
            'hours' => $data['contact_hours'] ?? null,
            'note' => $data['contact_note'] ?? null,
        ]);

        $mail->save([
            'mail_driver' => $data['mail_driver'],
            'resend_api_key' => $data['resend_api_key'] ?? null,
            'mail_from_address' => $data['mail_from_address'] ?? null,
            'mail_from_name' => $data['mail_from_name'] ?? null,
        ]);

        PlatformSetting::put('marketing.ga_measurement_id', trim((string) ($data['ga_measurement_id'] ?? '')) ?: null, 'marketing');
        PlatformSetting::put('marketing.meta_pixel_id', trim((string) ($data['meta_pixel_id'] ?? '')) ?: null, 'marketing');

        $currency->save(
            strtoupper($data['currency_base']),
            $data['fx_rates'] ?? [],
            $data['fx_rounding'] ?? []
        );

        Artisan::call('config:clear');

        return back()->with('success', 'Configuración de General guardada.');
    }

    public function fetchCurrencyRates(Request $request, CurrencyService $currency)
    {
        $data = $request->validate([
            'base' => ['nullable', 'string', 'size:3'],
            'persist' => ['nullable', 'boolean'],
        ]);

        $base = strtoupper($data['base'] ?? $currency->base());
        $result = $currency->fetchFromPublicApi($base);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'No se pudieron obtener tasas',
            ], 422);
        }

        if ($request->boolean('persist')) {
            $currency->save($base, $result['rates'] ?? [], $currency->roundingMap());
        }

        return response()->json([
            'success' => true,
            'base' => $result['base'],
            'rates' => $result['rates'],
            'source' => $result['source'] ?? null,
            'date' => $result['date'] ?? null,
            'persisted' => $request->boolean('persist'),
            'message' => 'Tasas actualizadas desde '.($result['source'] ?? 'API').
                ($result['date'] ?? null ? ' ('.$result['date'].')' : ''),
        ]);
    }

    public function authorizeCj(Request $request, CjConnector $cj)
    {
        $apiKey = $this->resolveCjApiKey($request);
        if (empty($apiKey)) {
            return back()->with('error', 'Ingresa la API Key de CJ Dropshipping.');
        }

        Cache::forget('cj.access_token');
        $result = $cj->authorizeWithApiKey($apiKey);

        if (! ($result['success'] ?? false)) {
            return back()->with('error', 'CJ: '.($result['error'] ?? 'No se pudo autorizar.'));
        }

        $this->syncCursorMcpQuietly();

        return back()->with('success', 'API Key de CJ agregada y autorizada. MCP listo para sincronizar.');
    }

    public function testCj(Request $request, CjConnector $cj)
    {
        $hasKey = PlatformSetting::getValue('cj.api_key') || config('cj.api_key');
        $apiKey = $this->resolveCjApiKey($request);
        if (empty($apiKey) && empty($hasKey)) {
            return $this->testJson($request, false, 'Primero guarda una API Key de CJ.');
        }
        config(['cj.api_key' => $apiKey ?: PlatformSetting::getValue('cj.api_key', config('cj.api_key'))]);

        Cache::forget('cj.access_token');
        $result = $cj->testApi(config('cj.api_key'));

        PlatformSetting::put('cj.last_test_at', now()->toIso8601String(), 'cj');
        PlatformSetting::put('cj.last_test_ok', ($result['success'] ?? false) ? '1' : '0', 'cj');
        PlatformSetting::put(
            'cj.last_test_message',
            ($result['success'] ?? false)
                ? ($result['message'] ?? 'OK')
                : ($result['error'] ?? 'Falló'),
            'cj'
        );

        if (! ($result['success'] ?? false)) {
            return $this->testJson($request, false, 'Prueba CJ falló: '.($result['error'] ?? 'Error desconocido'));
        }

        $this->syncCursorMcpQuietly();

        $msg = $result['message'] ?? 'API CJ OK.';
        if (! empty($result['mcp_url'])) {
            $msg .= ' MCP remoto sincronizado en .cursor/mcp.json.';
        }

        return $this->testJson($request, true, $msg);
    }

    public function saveAliExpress(Request $request)
    {
        $data = $request->validate([
            'aliexpress_app_key' => ['nullable', 'string', 'max:120'],
            'aliexpress_app_secret' => ['nullable', 'string', 'max:255'],
            'aliexpress_tracking_id' => ['nullable', 'string', 'max:80'],
            'aliexpress_ship_to' => ['nullable', 'string', 'size:2'],
        ]);

        $key = $data['aliexpress_app_key'] ?? '';
        if ($key !== '' && $key !== '********') {
            PlatformSetting::put('aliexpress.app_key', $key, 'aliexpress');
            config(['aliexpress.app_key' => $key]);
        }
        $this->putSecretIfPresent('aliexpress.app_secret', $data['aliexpress_app_secret'] ?? null, 'aliexpress');
        PlatformSetting::put('aliexpress.tracking_id', trim((string) ($data['aliexpress_tracking_id'] ?? '')) ?: null, 'aliexpress');
        $ship = strtoupper(trim((string) ($data['aliexpress_ship_to'] ?? 'MX'))) ?: 'MX';
        PlatformSetting::put('aliexpress.ship_to', $ship, 'aliexpress');
        config([
            'aliexpress.tracking_id' => PlatformSetting::getValue('aliexpress.tracking_id'),
            'aliexpress.ship_to' => $ship,
        ]);

        return back()->with('success', 'Credenciales de AliExpress Affiliate guardadas.');
    }

    public function saveCloudflareBrowser(Request $request)
    {
        $data = $request->validate([
            'cf_account_id' => ['nullable', 'string', 'max:64'],
            'cf_api_token' => ['nullable', 'string', 'max:500'],
            'cf_browser_rendering' => ['nullable', 'boolean'],
        ]);

        $account = trim((string) ($data['cf_account_id'] ?? ''));
        if ($account !== '' && $account !== '********') {
            PlatformSetting::put('cloudflare.account_id', $account, 'cloudflare');
            config(['cloudflare.account_id' => $account]);
        }
        $this->putSecretIfPresent('cloudflare.api_token', $data['cf_api_token'] ?? null, 'cloudflare');
        $enabled = $request->boolean('cf_browser_rendering');
        PlatformSetting::put('cloudflare.browser_rendering', $enabled ? '1' : '0', 'cloudflare');
        config(['cloudflare.enabled' => $enabled]);

        return back()->with('success', $enabled
            ? 'Cloudflare Browser Rendering activado. Product Hunter lo usará para AliExpress.'
            : 'Cloudflare Browser Rendering guardado (apagado).');
    }

    public function saveR2(Request $request, R2StorageManager $r2)
    {
        $data = $request->validate([
            'r2_enabled' => ['nullable', 'boolean'],
            'r2_account_id' => ['nullable', 'string', 'max:64'],
            'r2_access_key_id' => ['nullable', 'string', 'max:120'],
            'r2_secret_access_key' => ['nullable', 'string', 'max:255'],
            'r2_bucket' => ['nullable', 'string', 'max:120'],
            'r2_endpoint' => ['nullable', 'string', 'max:255'],
        ]);

        $enabled = $request->boolean('r2_enabled');
        PlatformSetting::put('storage.r2.enabled', $enabled ? '1' : '0', 'storage');

        $account = trim((string) ($data['r2_account_id'] ?? ''));
        if ($account !== '' && $account !== '********') {
            PlatformSetting::put('storage.r2.account_id', $account, 'storage');
        }
        $accessKey = trim((string) ($data['r2_access_key_id'] ?? ''));
        if ($accessKey !== '' && $accessKey !== '********') {
            PlatformSetting::put('storage.r2.access_key_id', $accessKey, 'storage');
        }
        $this->putSecretIfPresent('storage.r2.secret_access_key', $data['r2_secret_access_key'] ?? null, 'storage');
        PlatformSetting::put('storage.r2.bucket', trim((string) ($data['r2_bucket'] ?? '')) ?: null, 'storage');

        $endpoint = trim((string) ($data['r2_endpoint'] ?? ''));
        if ($endpoint === '' && $account !== '' && $account !== '********') {
            $endpoint = 'https://'.$account.'.r2.cloudflarestorage.com';
        }
        PlatformSetting::put('storage.r2.endpoint', $endpoint ?: null, 'storage');

        $r2->applyFromPlatformSettings();

        return back()->with('success', $enabled
            ? 'Cloudflare R2 activado. Los imports copiarán imágenes y videos a /f/.'
            : 'Cloudflare R2 guardado (apagado).');
    }

    public function refreshR2StoreStats(R2StorageManager $r2)
    {
        if (! $r2->enabled()) {
            return back()->with('error', 'Activa y configura R2 antes de recalcular el almacenamiento.');
        }

        $stores = Store::query()->active()->get(['id', 'settings']);
        foreach ($stores as $store) {
            $r2->refreshStoreStats($store);
        }

        return back()->with('success', 'Uso de R2 recalculado para todas las tiendas activas.');
    }

    public function testApi(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['miia', 'stripe', 'paypal', 'mercadopago', 'resend', 'turnstile', 'aliexpress', 'cloudflare_browser', 'r2'])],
        ]);

        $ok = false;
        $message = 'Sin respuesta';
        $engines = [];

        try {
            switch ($data['provider']) {
                case 'miia':
                    $key = PlatformSetting::getValue('ai.miia.api_key', config('ai.providers.miia.api_key'));
                    $base = rtrim((string) (PlatformSetting::getValue('ai.miia.base_url') ?: config('ai.providers.miia.base_url') ?: 'https://ia.ceballosleon.com'), '/');
                    if (! $key) {
                        return $this->testJson($request, false, 'Guarda primero la API Key de MIIA.');
                    }
                    $res = \Illuminate\Support\Facades\Http::withToken($key)->timeout(12)->get($base.'/v1/models');
                    $ok = $res->successful();
                    $router = app(AiTaskRouter::class);
                    $engines = $ok ? $router->listEngines(true) : [];
                    $imageCount = count($router->enginesForKind('image', $engines));
                    $message = $ok
                        ? ('MIIA OK · '.count($engines).' motores ('.$imageCount.' imagen)')
                        : ('HTTP '.$res->status());
                    if ($ok) {
                        $imgProbe = \Illuminate\Support\Facades\Http::withToken($key)
                            ->timeout(15)
                            ->acceptJson()
                            ->post($base.'/v1/images/generations', [
                                'model' => 'gpt-image-1.5',
                                'prompt' => '',
                            ]);
                        $imgBody = $imgProbe->json();
                        $imgErr = is_array($imgBody)
                            ? (string) ($imgBody['error']['message'] ?? $imgBody['message'] ?? $imgBody['error'] ?? '')
                            : $imgProbe->body();
                        $explained = \App\Domain\AI\OpenAiComboImageService::explainImagePermissionError($imgErr);
                        if ($explained !== $imgErr) {
                            $message .= ' · ⚠ '.$explained;
                        } elseif ($imgProbe->status() === 422 || $imgProbe->successful()) {
                            $message .= ' · imágenes habilitadas en esta key';
                        }
                    }
                    break;
                case 'stripe':
                    $key = PlatformSetting::getValue('payments.stripe.secret', config('services.stripe.secret'));
                    if (! $key) {
                        return $this->testJson($request, false, 'Guarda primero el secret de Stripe.');
                    }
                    $res = \Illuminate\Support\Facades\Http::withBasicAuth($key, '')->timeout(12)->get('https://api.stripe.com/v1/balance');
                    $ok = $res->successful();
                    $message = $ok ? 'Stripe OK (balance)' : ('HTTP '.$res->status());
                    break;
                case 'paypal':
                    $id = PlatformSetting::getValue('payments.paypal.client_id');
                    $secret = PlatformSetting::getValue('payments.paypal.client_secret');
                    $mode = PlatformSetting::getValue('payments.paypal.mode', 'sandbox');
                    if (! $id || ! $secret) {
                        return $this->testJson($request, false, 'Guarda Client ID y Secret de PayPal.');
                    }
                    $simulate = $request->boolean('simulate')
                        || app()->environment('local')
                        || ! filter_var(config('app.url'), FILTER_VALIDATE_URL);
                    if ($simulate) {
                        $idLen = strlen((string) $id);
                        $secretLen = strlen((string) $secret);
                        $idLooksOk = $idLen >= 20;
                        $secretLooksOk = $secretLen >= 20;
                        $ok = $idLooksOk && $secretLooksOk && in_array($mode, ['sandbox', 'live'], true);
                        if ($ok) {
                            $message = 'PayPal simulado OK: tus llaves están configuradas correctamente (Client ID '.$idLen.' chars, Secret '.$secretLen.' chars, modo '.$mode.'). Nota: el cobro real con PayPal Orders API aún no está implementado en esta iteración.';
                        } else {
                            $message = 'PayPal simulado falló: revisa formato de llaves o modo (sandbox/live).';
                        }
                        break;
                    }
                    $host = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
                    $res = \Illuminate\Support\Facades\Http::asForm()
                        ->withBasicAuth($id, $secret)
                        ->timeout(12)
                        ->post($host.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
                    $ok = $res->successful() && $res->json('access_token');
                    $message = $ok ? 'PayPal OK (token)' : ('HTTP '.$res->status());
                    break;
                case 'mercadopago':
                    $token = PlatformSetting::getValue('payments.mercadopago.access_token');
                    if (! $token) {
                        return $this->testJson($request, false, 'Guarda el Access Token de Mercado Pago.');
                    }
                    $res = \Illuminate\Support\Facades\Http::withToken($token)->timeout(12)->get('https://api.mercadopago.com/users/me');
                    $ok = $res->successful();
                    $message = $ok ? 'Mercado Pago OK' : ('HTTP '.$res->status());
                    break;
                case 'resend':
                    $key = PlatformSetting::getValue('platform.mail.resend_api_key', config('services.resend.key'));
                    if (! $key) {
                        return $this->testJson($request, false, 'Guarda primero la API Key de Resend.');
                    }
                    $res = \Illuminate\Support\Facades\Http::withToken($key)->timeout(12)->get('https://api.resend.com/domains');
                    $ok = $res->successful();
                    $message = $ok ? 'Resend OK (domains)' : ('HTTP '.$res->status());
                    break;
                case 'turnstile':
                    $site = PlatformSetting::getValue('cloudflare.turnstile.site_key');
                    $secret = PlatformSetting::getValue('cloudflare.turnstile.secret_key');
                    $ok = (bool) $site && (bool) $secret;
                    $message = $ok ? 'Turnstile: site y secret guardados' : 'Falta site key o secret';
                    break;
                case 'aliexpress':
                    $this->applyAliExpressFromRequest($request);
                    $client = app(AliExpressAffiliateClient::class);
                    if (! $client->isConfigured()) {
                        return $this->testJson($request, false, 'Guarda primero App Key y App Secret de AliExpress Affiliate.');
                    }
                    $testId = trim((string) $request->input('aliexpress_test_product_id', ''));
                    if ($testId === '' || $testId === '********') {
                        $testId = (string) config('aliexpress.test_product_id');
                    }
                    $ae = $client->productDetail($testId);
                    $ok = (bool) ($ae['success'] ?? false);
                    $message = $ok
                        ? ('AliExpress Affiliate OK · producto '.$testId)
                        : ('AliExpress: '.($ae['error'] ?? 'falló'));
                    PlatformSetting::put('aliexpress.last_test_at', now()->toIso8601String(), 'aliexpress');
                    PlatformSetting::put('aliexpress.last_test_ok', $ok ? '1' : '0', 'aliexpress');
                    PlatformSetting::put('aliexpress.last_test_message', mb_substr($message, 0, 240), 'aliexpress');
                    break;
                case 'cloudflare_browser':
                    $this->applyCloudflareBrowserFromRequest($request);
                    $account = trim((string) config('cloudflare.account_id'));
                    $token = trim((string) config('cloudflare.api_token'));
                    if ($account === '' || $token === '') {
                        return $this->testJson($request, false, 'Guarda Account ID y API Token de Cloudflare.');
                    }
                    config(['cloudflare.enabled' => true]);
                    $renderer = app(CloudflareBrowserRenderer::class);
                    $testUrl = trim((string) $request->input('cf_browser_test_url', ''));
                    $probe = $renderer->test($testUrl !== '' ? $testUrl : null);
                    $ok = (bool) ($probe['success'] ?? false);
                    $message = (string) ($probe['message'] ?? ($ok ? 'OK' : 'Falló'));
                    PlatformSetting::put('cloudflare.browser_last_test_at', now()->toIso8601String(), 'cloudflare');
                    PlatformSetting::put('cloudflare.browser_last_test_ok', $ok ? '1' : '0', 'cloudflare');
                    PlatformSetting::put('cloudflare.browser_last_test_message', mb_substr($message, 0, 240), 'cloudflare');
                    break;
                case 'r2':
                    $r2 = app(R2StorageManager::class);
                    $r2->applyFromPlatformSettings();
                    $this->applyR2ConfigOverlay($request);
                    $probe = $r2->testConnection();
                    $ok = (bool) ($probe['success'] ?? false);
                    $message = (string) ($probe['message'] ?? ($ok ? 'OK' : 'Falló'));
                    PlatformSetting::put('storage.r2.last_test_at', now()->toIso8601String(), 'storage');
                    PlatformSetting::put('storage.r2.last_test_ok', $ok ? '1' : '0', 'storage');
                    PlatformSetting::put('storage.r2.last_test_message', mb_substr($message, 0, 240), 'storage');

                    return $this->testJson($request, $ok, $message);
            }
        } catch (\Throwable $e) {
            return $this->testJson($request, false, 'Prueba falló: '.$e->getMessage());
        }

        $extra = [];
        if (($data['provider'] ?? '') === 'miia' && ! empty($engines)) {
            $extra['engines'] = $engines;
        }

        return $this->testJson($request, $ok, $ok ? $message : 'Prueba falló: '.$message, $extra);
    }

    public function aiEngines(Request $request, AiTaskRouter $router)
    {
        $engines = $router->listEngines($request->boolean('fresh'));

        return response()->json([
            'success' => $engines !== [],
            'engines' => $engines,
            'engines_chat' => $router->enginesForKind('chat', $engines),
            'engines_image' => $router->enginesForKind('image', $engines),
        ]);
    }

    /**
     * @param  list<array{id: string, label: string, kind: string}>  $engines
     * @return list<array{key: string, label: string, hint: string, kind: string, engine: string}>
     */
    protected function aiTasksForView(AiTaskRouter $router, array $engines): array
    {
        $saved = $router->savedEngines();
        $old = old('ai_task_engines', []);
        $rows = [];
        foreach ($router->tasks() as $key => $meta) {
            $kind = (string) ($meta['kind'] ?? 'chat');
            $kindIds = array_values(array_filter(array_map(
                function ($row) use ($kind) {
                    if (! is_array($row) || empty($row['id'])) {
                        return '';
                    }
                    $id = (string) $row['id'];
                    $rowKind = (($row['kind'] ?? '') === 'image' || AiTaskRouter::looksLikeImageEngine($id)) ? 'image' : 'chat';

                    return $rowKind === $kind ? $id : '';
                },
                $engines
            )));
            $raw = is_array($old) && isset($old[$key]) && trim((string) $old[$key]) !== ''
                ? trim((string) $old[$key])
                : (string) ($saved[$key] ?? $router->defaultEngineFor($key, $kindIds));
            $rows[] = [
                'key' => $key,
                'label' => (string) ($meta['label'] ?? $key),
                'hint' => (string) ($meta['hint'] ?? ''),
                'kind' => $kind,
                'engine' => $router->sanitizeEngineForTask($key, $raw),
            ];
        }

        return $rows;
    }

    protected function testJson(Request $request, bool $ok, string $message, array $extra = [])
    {
        $payload = array_merge([
            'ok' => $ok,
            'success' => $ok,
            'message' => $message,
        ], $extra);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($payload);
        }

        return $ok
            ? back()->with('success', $message)
            : back()->with('error', $message);
    }

    protected function resolveCjApiKey(Request $request): ?string
    {
        $apiKey = $request->input('cj_api_key');
        if (is_string($apiKey) && $apiKey !== '' && $apiKey !== '********') {
            PlatformSetting::put('cj.api_key', $apiKey, 'cj', true);
            config(['cj.api_key' => $apiKey]);

            return $apiKey;
        }

        $stored = PlatformSetting::getValue('cj.api_key', config('cj.api_key'));
        config(['cj.api_key' => $stored]);

        return $stored ?: null;
    }

    protected function applyAliExpressFromRequest(Request $request): void
    {
        $key = $request->input('aliexpress_app_key');
        if (is_string($key) && $key !== '' && $key !== '********') {
            PlatformSetting::put('aliexpress.app_key', $key, 'aliexpress');
            config(['aliexpress.app_key' => $key]);
        }
        $secret = $request->input('aliexpress_app_secret');
        if (is_string($secret) && $secret !== '' && $secret !== '********') {
            PlatformSetting::put('aliexpress.app_secret', $secret, 'aliexpress', true);
            config(['aliexpress.app_secret' => $secret]);
        }
        $tracking = $request->input('aliexpress_tracking_id');
        if (is_string($tracking) && $tracking !== '') {
            PlatformSetting::put('aliexpress.tracking_id', $tracking, 'aliexpress');
            config(['aliexpress.tracking_id' => $tracking]);
        }
    }

    protected function applyCloudflareBrowserFromRequest(Request $request): void
    {
        $account = $request->input('cf_account_id');
        if (is_string($account) && $account !== '' && $account !== '********') {
            PlatformSetting::put('cloudflare.account_id', $account, 'cloudflare');
            config(['cloudflare.account_id' => $account]);
        }
        $token = $request->input('cf_api_token');
        if (is_string($token) && $token !== '' && $token !== '********') {
            PlatformSetting::put('cloudflare.api_token', $token, 'cloudflare', true);
            config(['cloudflare.api_token' => $token]);
        }
        if ($request->has('cf_browser_rendering')) {
            $on = $request->boolean('cf_browser_rendering');
            PlatformSetting::put('cloudflare.browser_rendering', $on ? '1' : '0', 'cloudflare');
            config(['cloudflare.enabled' => $on]);
        }
    }

    protected function applyR2ConfigOverlay(Request $request): void
    {
        if ($request->has('r2_enabled')) {
            config(['r2.enabled' => $request->boolean('r2_enabled')]);
        }

        $map = [
            'r2_account_id' => 'r2.account_id',
            'r2_access_key_id' => 'r2.access_key_id',
            'r2_bucket' => 'r2.bucket',
            'r2_endpoint' => 'r2.endpoint',
        ];
        foreach ($map as $input => $configKey) {
            $value = $request->input($input);
            if (is_string($value) && $value !== '' && $value !== '********') {
                config([$configKey => $value]);
            }
        }

        $secret = $request->input('r2_secret_access_key');
        if (is_string($secret) && $secret !== '' && $secret !== '********') {
            config(['r2.secret_access_key' => $secret]);
        }

        $r2 = app(R2StorageManager::class);
        $r2->ensureEndpoint();
        $r2->syncDiskConfig();
    }

    protected function applyR2FromRequest(Request $request): void
    {
        if ($request->has('r2_enabled')) {
            $on = $request->boolean('r2_enabled');
            PlatformSetting::put('storage.r2.enabled', $on ? '1' : '0', 'storage');
            config(['r2.enabled' => $on]);
        }
        $account = $request->input('r2_account_id');
        if (is_string($account) && $account !== '' && $account !== '********') {
            PlatformSetting::put('storage.r2.account_id', $account, 'storage');
            config(['r2.account_id' => $account]);
        }
        $accessKey = $request->input('r2_access_key_id');
        if (is_string($accessKey) && $accessKey !== '' && $accessKey !== '********') {
            PlatformSetting::put('storage.r2.access_key_id', $accessKey, 'storage');
            config(['r2.access_key_id' => $accessKey]);
        }
        $secret = $request->input('r2_secret_access_key');
        if (is_string($secret) && $secret !== '' && $secret !== '********') {
            PlatformSetting::put('storage.r2.secret_access_key', $secret, 'storage', true);
            config(['r2.secret_access_key' => $secret]);
        }
        $bucket = $request->input('r2_bucket');
        if (is_string($bucket) && $bucket !== '') {
            PlatformSetting::put('storage.r2.bucket', $bucket, 'storage');
            config(['r2.bucket' => $bucket]);
        }
        $endpoint = $request->input('r2_endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            PlatformSetting::put('storage.r2.endpoint', $endpoint, 'storage');
            config(['r2.endpoint' => $endpoint]);
        }

        app(R2StorageManager::class)->syncDiskConfig();
    }

    protected function syncCursorMcpQuietly(): void
    {
        try {
            Artisan::call('cj:sync-cursor-mcp');
        } catch (\Throwable) {
            // opcional: el admin puede correr el comando a mano
        }
    }

    protected function maskMcpUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parts = explode('/', $url);
        $token = array_pop($parts);
        if (! $token || strlen($token) < 12) {
            return $url;
        }

        $masked = substr($token, 0, 6).str_repeat('*', max(8, strlen($token) - 10)).substr($token, -4);

        return implode('/', $parts).'/'.$masked;
    }

    protected function putSecretIfPresent(string $key, ?string $value, string $group = 'payments'): void
    {
        if ($value === null || $value === '' || $value === '********') {
            return;
        }

        PlatformSetting::put($key, $value, $group, true);
    }
}
