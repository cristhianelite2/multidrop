@extends('layouts.admin')

@section('title', 'General')
@section('heading', 'General')
@section('subheading', 'APIs, contacto, Resend, pagos, CJ Dropshipping, AliExpress y Cloudflare Browser Rendering.')

@section('content')
    @php $pixels = $pixels ?? ['ga_measurement_id' => '', 'meta_pixel_id' => '']; @endphp
    <div class="admin-settings-layout lg:flex lg:items-start lg:gap-6">
        <nav class="admin-settings-toc mb-4 lg:mb-0 lg:w-52 lg:shrink-0 lg:sticky lg:top-20" aria-label="Secciones de General">
            <p class="mb-2 hidden text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55 lg:block">Secciones</p>
            <div class="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
                <a href="#sec-cj">CJ / MCP</a>
                <a href="#sec-aliexpress">AliExpress</a>
                <a href="#sec-cf-browser">Browser Rendering</a>
                <a href="#sec-r2">R2 almacenamiento</a>
                <a href="#sec-ai">Inteligencia artificial</a>
                <a href="#sec-payments">Pagos</a>
                <a href="#sec-security">Seguridad</a>
                <a href="#sec-currency">Monedas</a>
                <a href="#sec-pixels">Pixels</a>
                <a href="#sec-contact">Contacto</a>
                <a href="#sec-mail">Resend</a>
            </div>
        </nav>
        <div class="min-w-0 flex-1 space-y-6">
    @php
        $commerce = $commerce ?? ['sales_by_type' => collect(), 'stores' => collect(), 'ga_tracking_on' => false];
        $salesByType = $commerce['sales_by_type'] ?? collect();
        $storeRows = collect($commerce['stores'] ?? []);
    @endphp

    <div class="admin-card p-5 sm:p-6 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Ventas y operación por tienda</h2>
                <p class="mt-1 text-sm text-ink-soft/70">Resumen de ventas nuevas, reclamos y estado de visitas/ventas para mega y mini-tiendas.</p>
            </div>
            <span class="admin-badge {{ ($commerce['ga_tracking_on'] ?? false) ? 'bg-teal/10 text-teal' : 'bg-amber-100 text-amber-800' }}">
                {{ ($commerce['ga_tracking_on'] ?? false) ? 'Visitas habilitadas (GA4)' : 'Visitas sin tracking activo' }}
            </span>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach(['mini' => 'Mini-tiendas', 'mega' => 'Tiendas mega'] as $key => $label)
                @php $row = $salesByType[$key] ?? null; @endphp
                <div class="rounded-2xl border border-line bg-mist/25 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div class="text-sm font-semibold text-ink">{{ $label }}</div>
                        <span class="admin-badge bg-white text-ink-soft">{{ (int) ($row['stores'] ?? 0) }} tiendas</span>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-ink-soft/80">
                        <div class="rounded-xl border border-line bg-white px-3 py-2">
                            <div>Nuevas ventas (hoy)</div>
                            <div class="mt-1 text-base font-semibold text-ink">{{ (int) ($row['new_sales'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-xl border border-line bg-white px-3 py-2">
                            <div>Reclamos abiertos</div>
                            <div class="mt-1 text-base font-semibold text-ink">{{ (int) ($row['open_claims'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-xl border border-line bg-white px-3 py-2">
                            <div>Ventas (30d)</div>
                            <div class="mt-1 text-base font-semibold text-ink">{{ (int) ($row['paid_30'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-xl border border-line bg-white px-3 py-2">
                            <div>Ingresos (30d)</div>
                            <div class="mt-1 text-base font-semibold text-ink">${{ number_format((float) ($row['revenue_30'] ?? 0), 2) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                        <th class="py-2.5 pr-3 font-semibold">Tienda</th>
                        <th class="py-2.5 pr-3 font-semibold">Tipo</th>
                        <th class="py-2.5 pr-3 font-semibold">Nuevas ventas</th>
                        <th class="py-2.5 pr-3 font-semibold">Reclamos</th>
                        <th class="py-2.5 pr-3 font-semibold">Ventas (30d)</th>
                        <th class="py-2.5 pr-3 font-semibold">Visitas/Ventas</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($storeRows as $row)
                        <tr class="border-b border-line/70 last:border-0">
                            <td class="py-2.5 pr-3">
                                <div class="font-semibold text-ink">{{ $row['name'] }}</div>
                                <div class="text-xs text-ink-soft/60">{{ $row['market'] }}</div>
                            </td>
                            <td class="py-2.5 pr-3">
                                <span class="admin-badge {{ ($row['type'] ?? 'mini') === 'mega' ? 'bg-sky-100 text-sky-800' : 'bg-teal/10 text-teal' }}">
                                    {{ strtoupper((string) ($row['type'] ?? 'mini')) }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-3 text-ink">{{ (int) ($row['paid_today'] ?? 0) }}</td>
                            <td class="py-2.5 pr-3">
                                <span class="admin-badge {{ ((int) ($row['open_claims'] ?? 0)) > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700' }}">
                                    {{ (int) ($row['open_claims'] ?? 0) }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-3 text-ink">
                                {{ (int) ($row['paid_30'] ?? 0) }}
                                <span class="text-xs text-ink-soft/60">/ {{ (int) ($row['orders_30'] ?? 0) }}</span>
                            </td>
                            <td class="py-2.5 pr-3 text-ink-soft/75">
                                @if($commerce['ga_tracking_on'] ?? false)
                                    Pagadas: {{ number_format((float) ($row['conversion_paid_30'] ?? 0), 1) }}%
                                @else
                                    Tracking pendiente · Pagadas: {{ number_format((float) ($row['conversion_paid_30'] ?? 0), 1) }}%
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-ink-soft/60">Sin datos de tiendas para mostrar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-blocks" id="sec-cj">
        {{-- CJ Dropshipping --}}
        <div class="admin-card p-5 sm:p-6 space-y-6 h-full">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">CJ Dropshipping</h2>
                    <p class="mt-1 text-sm text-ink-soft/70">API Key / MCP Token para productos, pedidos y el servidor MCP oficial.</p>
                </div>
                <a href="https://developers.cjdropshipping.com/en/api/api2/mcp.html" target="_blank" rel="noopener" class="text-sm font-medium text-teal hover:underline">
                    Docs MCP ↗
                </a>
            </div>

            <form method="post" action="{{ route('admin.settings.general.cj.authorize') }}" class="space-y-4 rounded-2xl border border-line bg-mist/40 p-4" id="cj-api-form">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-ink">API Key &amp; MCP Token</h3>
                    @if($cj['has_access_token'])
                        <span class="admin-badge bg-teal/10 text-teal">Token activo{{ $cj['authorized_at'] ? ' · '. \Illuminate\Support\Carbon::parse($cj['authorized_at'])->diffForHumans() : '' }}</span>
                    @else
                        <span class="admin-badge bg-coral/10 text-coral">Sin autorizar</span>
                    @endif
                </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">API Key de CJ {{ $hasDb['cj_api_key'] ? '(guardada · deja ******** para no cambiar)' : '' }}</label>
                <input name="cj_api_key" id="cj_api_key" value="{{ old('cj_api_key', $cj['api_key']) }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="CJUserNum@api@…" form="cj-api-form">
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="admin-btn" form="cj-api-form">Agregar API Key</button>
                <button type="button" class="js-api-test admin-btn-secondary" data-provider="cj" data-url="{{ route('admin.settings.general.cj.test') }}">Probar API</button>
            </div>
            @include('admin.settings.partials.api-help', [
                'title' => 'Cómo obtener la API Key de CJ',
                'steps' => [
                    'Entra a <a class="text-teal hover:underline" href="https://www.cjdropshipping.com/" target="_blank" rel="noopener">CJ Dropshipping</a> e inicia sesión.',
                    'Ve a <strong>Authorization → API</strong> (o Developers).',
                    'Crea o copia tu <strong>API Key</strong> (formato tipo <code>CJUserNum@api@…</code>).',
                    'Pégala aquí, pulsa <strong>Agregar API Key</strong> y luego <strong>Probar API</strong> para autorizar el token MCP.',
                ],
            ])
                @if($hasDb['cj_api_key'] && $cj['last_test_at'])
                    <p class="js-api-test-last text-xs {{ $cj['last_test_ok'] ? 'text-teal' : 'text-coral' }}">
                        Última prueba: {{ \Illuminate\Support\Carbon::parse($cj['last_test_at'])->diffForHumans() }}
                        — {{ $cj['last_test_message'] }}
                    </p>
                @else
                    <p class="js-api-test-last text-xs text-ink-soft/55 hidden"></p>
                @endif
            </form>
        </div>

        {{-- MCP CJ --}}
        <div class="admin-card p-5 sm:p-6 space-y-4 h-full">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">MCP CJ Dropshipping</h2>
                    <p class="mt-1 text-sm text-ink-soft/70">
                        Servidor MCP oficial para Cursor / ChatGPT / Claude. Tras autorizar o probar la API se escribe
                        <code class="text-xs">.cursor/mcp.json</code> (también: <code class="text-xs">php artisan cj:sync-cursor-mcp</code>).
                    </p>
                </div>
                @if($cj['has_access_token'] && $cj['mcp_url_masked'])
                    <span class="admin-badge bg-teal/10 text-teal">Remoto (HTTP)</span>
                @else
                    <span class="admin-badge bg-coral/10 text-coral">Pendiente de token</span>
                @endif
            </div>

            @if($cj['has_access_token'] && $cj['mcp_url_masked'])
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">MCP Server URL</label>
                    <input type="text" readonly value="{{ $cj['mcp_url_masked'] }}" class="admin-input font-mono text-xs bg-white/60" id="cj-mcp-url-masked">
                    <input type="hidden" id="cj-mcp-url-full" value="{{ $cj['mcp_url'] }}">
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="admin-btn-secondary" id="cj-mcp-copy">Copiar URL MCP</button>
                    <a href="https://developers.cjdropshipping.com/en/api/api2/mcp.html" target="_blank" rel="noopener" class="admin-btn-secondary">Guía de integración ↗</a>
                </div>
                <p class="text-xs text-ink-soft/55">
                    Herramientas MCP: search_products, create_order, calculate_freight, get_order_list, list_shops, etc.
                </p>
            @else
                <div class="rounded-2xl border border-dashed border-line bg-mist/30 p-4 text-sm text-ink-soft/70">
                    Autoriza o prueba la API de CJ en el bloque de la izquierda para generar la URL MCP.
                </div>
            @endif
        </div>
    </div>

    <div class="admin-card p-5 sm:p-6 space-y-6" id="sec-aliexpress">
        @php $ae = $aliexpress ?? []; @endphp
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">AliExpress Affiliate</h2>
                <p class="mt-1 text-sm text-ink-soft/70">
                    Ficha de producto para Product Hunter (imágenes, precio, categoría). No coloca pedidos en AliExpress.
                    Orden de extracción: API Affiliate → <a href="#sec-cf-browser" class="text-teal hover:underline">Cloudflare Browser Rendering</a> → scrape HTTP.
                </p>
            </div>
            <a href="https://portals.aliexpress.com/" target="_blank" rel="noopener" class="text-sm font-medium text-teal hover:underline">
                Portals Affiliate ↗
            </a>
        </div>

        <form method="post" action="{{ route('admin.settings.general.aliexpress.save') }}" class="space-y-4 rounded-2xl border border-line bg-mist/40 p-4" id="ae-api-form">
            @csrf
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-ink">App Key, Secret y Tracking ID</h3>
                @if($ae['has_app_key'] ?? false)
                    <span class="admin-badge bg-teal/10 text-teal">App Key guardada</span>
                @else
                    <span class="admin-badge bg-coral/10 text-coral">Sin configurar</span>
                @endif
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">App Key {{ ($hasDb['aliexpress_app_key'] ?? false) ? '(guardada · deja ******** para no cambiar)' : '' }}</label>
                    <input name="aliexpress_app_key" id="aliexpress_app_key" value="{{ old('aliexpress_app_key', $ae['app_key'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="tu App Key">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">App Secret {{ ($hasDb['aliexpress_app_secret'] ?? false) ? '(guardado · deja ********)' : '' }}</label>
                    <input name="aliexpress_app_secret" id="aliexpress_app_secret" type="password" value="{{ old('aliexpress_app_secret', $ae['app_secret'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Tracking ID (PID)</label>
                    <input name="aliexpress_tracking_id" id="aliexpress_tracking_id" value="{{ old('aliexpress_tracking_id', $ae['tracking_id'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="tu tracking id">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">País de envío (ISO-2)</label>
                    <input name="aliexpress_ship_to" id="aliexpress_ship_to" value="{{ old('aliexpress_ship_to', $ae['ship_to'] ?? 'MX') }}" class="admin-input font-mono text-sm uppercase" maxlength="2">
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">ID de producto para la prueba (opcional)</label>
                <input id="aliexpress_test_product_id" value="{{ $ae['test_product_id'] ?? '' }}" class="admin-input font-mono text-sm" placeholder="1005005993442954">
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="admin-btn">Guardar AliExpress</button>
                <button type="button" class="js-api-test admin-btn-secondary" data-provider="aliexpress" data-url="{{ route('admin.settings.general.api.test') }}">Probar API</button>
            </div>
            @include('admin.settings.partials.api-help', [
                'title' => 'Cómo obtener la API de AliExpress Affiliate',
                'steps' => [
                    'Entra a <a class="text-teal hover:underline" href="https://portals.aliexpress.com/" target="_blank" rel="noopener">AliExpress Affiliate (Portals)</a> e inicia sesión (o crea cuenta de afiliado, es gratis).',
                    'Ve a <strong>Account → API</strong> o <strong>App management</strong> y crea una app. Copia <strong>App Key</strong> y <strong>App Secret</strong>.',
                    'En el mismo portal copia tu <strong>Tracking ID</strong> (a veces llamado PID).',
                    'Pega los tres datos aquí, pulsa <strong>Guardar AliExpress</strong> y luego <strong>Probar API</strong>. La prueba llama <code>aliexpress.affiliate.productdetail.get</code>.',
                    'Si la prueba falla, Product Hunter usa Cloudflare Browser Rendering (si está activo) y luego scrape HTTP. La API mejora título, precio e imágenes oficiales.',
                ],
            ])
            @php $cfb = $cfBrowser ?? []; @endphp
            <p class="text-xs text-ink-soft/70">
                Crawler Cloudflare:
                @if(!empty($cfb['ready']))
                    <span class="font-medium text-teal">activo</span>
                @else
                    <span class="font-medium text-coral">apagado</span> — configúralo en
                    <a href="#sec-cf-browser" class="text-teal hover:underline">Browser Rendering</a>.
                @endif
            </p>
            @if(($ae['last_test_at'] ?? null))
                <p class="js-api-test-last text-xs {{ ($ae['last_test_ok'] ?? false) ? 'text-teal' : 'text-coral' }}">
                    Última prueba: {{ \Illuminate\Support\Carbon::parse($ae['last_test_at'])->diffForHumans() }}
                    — {{ $ae['last_test_message'] ?? '' }}
                </p>
            @else
                <p class="js-api-test-last text-xs text-ink-soft/55 hidden"></p>
            @endif
        </form>
    </div>

    <div class="admin-card p-5 sm:p-6 space-y-6" id="sec-cf-browser">
        @php $cfb = $cfBrowser ?? []; @endphp
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Cloudflare Browser Rendering</h2>
                <p class="mt-1 text-sm text-ink-soft/70">
                    Chromium remoto de Cloudflare (producto <strong>Browser Run</strong>, API sigue llamándose Browser Rendering).
                    Renderiza el JavaScript de AliExpress sin pegarle al droplet. Product Hunter lo usa si está activo.
                </p>
            </div>
            <a href="{{ $cfb['docs'] ?? 'https://developers.cloudflare.com/browser-run/quick-actions/content-endpoint/' }}" target="_blank" rel="noopener" class="text-sm font-medium text-teal hover:underline">
                Docs Cloudflare ↗
            </a>
        </div>

        <form method="post" action="{{ route('admin.settings.general.cloudflare-browser.save') }}" class="space-y-4 rounded-2xl border border-line bg-mist/40 p-4" id="cf-browser-form">
            @csrf
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-ink">Account ID y API Token</h3>
                @if(!empty($cfb['ready']))
                    <span class="admin-badge bg-teal/10 text-teal">Listo para crawlear</span>
                @elseif(!empty($cfb['has_token']) && !empty($cfb['has_account']))
                    <span class="admin-badge bg-amber-100 text-amber-800">Credenciales guardadas · apagado</span>
                @else
                    <span class="admin-badge bg-coral/10 text-coral">Sin configurar</span>
                @endif
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Account ID {{ !empty($cfb['has_account']) ? '(deja el valor para no cambiar)' : '' }}</label>
                    <input name="cf_account_id" id="cf_account_id" value="{{ old('cf_account_id', $cfb['account_id'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="32 caracteres hex">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">API Token {{ !empty($cfb['has_token']) ? '(guardado · deja ********)' : '' }}</label>
                    <input name="cf_api_token" id="cf_api_token" type="password" value="{{ old('cf_api_token', $cfb['api_token'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="token custom Account → Browser Rendering → Edit">
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">URL de prueba (opcional)</label>
                <input id="cf_browser_test_url" value="https://example.com/" class="admin-input font-mono text-sm" placeholder="https://example.com/">
                <p class="mt-1 text-[11px] text-ink-soft/55">Por defecto example.com (no gasta cuota en AliExpress). Puedes pegar un item AE para una prueba real.</p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                <input type="hidden" name="cf_browser_rendering" value="0">
                <input type="checkbox" name="cf_browser_rendering" id="cf_browser_rendering" value="1" @checked(old('cf_browser_rendering', $cfb['enabled'] ?? false))>
                Activar Browser Rendering en Product Hunter
            </label>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="admin-btn">Guardar Cloudflare</button>
                <button type="button" class="js-api-test admin-btn-secondary" data-provider="cloudflare_browser" data-url="{{ route('admin.settings.general.api.test') }}">Probar API</button>
            </div>
            @include('admin.settings.partials.api-help', [
                'title' => 'Cómo crear el token (no hay plantilla)',
                'steps' => [
                    '<strong>Account ID:</strong> en el dashboard pulsa buscar (<kbd>Ctrl+K</kbd>) y elige <strong>Copy account ID</strong>. También está en <a class="text-teal hover:underline" href="https://dash.cloudflare.com/?to=/:account/workers-and-pages" target="_blank" rel="noopener">Compute → Workers &amp; Pages</a> → Account details.',
                    'Abre <a class="text-teal hover:underline" href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">My Profile → API Tokens</a> y pulsa <strong>Create Token</strong>.',
                    'En esa pantalla <strong>no uses ninguna plantilla</strong> (Edit zone DNS, Edit Cloudflare Workers, Workers AI, etc. no sirven). Arriba, en <strong>Custom token</strong>, pulsa <strong>Get started</strong>.',
                    'Nombre el token (ej. <code>multidrop-browser</code>). En <strong>Permissions</strong> añade <em>una</em> fila con los tres desplegables: 1) <strong>Account</strong> · 2) <strong>Browser Rendering</strong> (si no sale, busca <strong>Browser Run</strong>) · 3) <strong>Edit</strong>. Queda: Account / Browser Rendering / Edit.',
                    'En <strong>Account Resources</strong> deja <strong>Include → All accounts</strong>, o solo la cuenta de ese Account ID. <strong>Continue to summary → Create Token</strong>. Copia el secreto (solo se muestra una vez; los nuevos empiezan por <code>cfut_</code>).',
                    'Pega Account ID y token aquí, marca <strong>Activar</strong>, <strong>Guardar Cloudflare</strong> y <strong>Probar API</strong> (example.com). Si Probar API responde 401, el token no tiene ese permiso o el Account ID no es de esa cuenta.',
                    'Plan: el Free incluye ~10 min/día de navegador; Paid ~10 h/mes y luego 0,09 USD/h. No hace falta un Worker desplegado: usamos la REST <code>/browser-rendering/content</code>.',
                ],
            ])
            @if(($cfb['last_test_at'] ?? null))
                <p class="js-api-test-last text-xs {{ ($cfb['last_test_ok'] ?? false) ? 'text-teal' : 'text-coral' }}">
                    Última prueba: {{ \Illuminate\Support\Carbon::parse($cfb['last_test_at'])->diffForHumans() }}
                    — {{ $cfb['last_test_message'] ?? '' }}
                </p>
            @else
                <p class="js-api-test-last text-xs text-ink-soft/55 hidden"></p>
            @endif
        </form>
    </div>

    <div class="admin-card p-5 sm:p-6 space-y-6" id="sec-r2">
        @php $r2 = $r2Storage ?? []; @endphp
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Cloudflare R2 — almacenamiento de media</h2>
                <p class="mt-1 text-sm text-ink-soft/70">
                    Copia imágenes y videos de productos (CJ, AliExpress o manual) a R2 y sirve URLs enmascaradas
                    <code class="text-xs">/{{ $r2['public_prefix'] ?? 'f' }}/stores/…</code> en tu dominio.
                </p>
            </div>
            <a href="{{ $r2['docs']['r2'] ?? 'https://developers.cloudflare.com/r2/' }}" target="_blank" rel="noopener" class="text-sm font-medium text-teal hover:underline">
                Docs R2 ↗
            </a>
        </div>

        <form method="post" action="{{ route('admin.settings.general.r2.save') }}" class="space-y-4 rounded-2xl border border-line bg-mist/40 p-4" id="r2-form">
            @csrf
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-ink">Credenciales S3 de R2</h3>
                @if(!empty($r2['ready']))
                    <span class="admin-badge bg-teal/10 text-teal">R2 activo</span>
                @elseif(!empty($r2['configured']))
                    <span class="admin-badge bg-amber-100 text-amber-800">Configurado · apagado</span>
                @else
                    <span class="admin-badge bg-coral/10 text-coral">Sin configurar</span>
                @endif
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Account ID</label>
                    <input name="r2_account_id" id="r2_account_id" value="{{ old('r2_account_id', $r2['account_id'] ?? '') }}" class="admin-input font-mono text-sm" placeholder="32 caracteres hex">
                    <p class="mt-1 text-[11px] text-ink-soft/55">Puede ser el mismo de Browser Rendering.</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Bucket</label>
                    <input name="r2_bucket" id="r2_bucket" value="{{ old('r2_bucket', $r2['bucket'] ?? '') }}" class="admin-input font-mono text-sm" placeholder="multidrop-media">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Access Key ID {{ !empty($r2['has_access_key']) ? '(guardado · deja ********)' : '' }}</label>
                    <input name="r2_access_key_id" id="r2_access_key_id" type="password" value="{{ old('r2_access_key_id', $r2['access_key_id'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Secret Access Key {{ !empty($r2['has_secret']) ? '(guardado · deja ********)' : '' }}</label>
                    <input name="r2_secret_access_key" id="r2_secret_access_key" type="password" value="{{ old('r2_secret_access_key', $r2['secret_access_key'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off">
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Endpoint S3 (opcional)</label>
                    <input name="r2_endpoint" id="r2_endpoint" value="{{ old('r2_endpoint', $r2['endpoint'] ?? '') }}" class="admin-input font-mono text-sm" placeholder="https://&lt;account_id&gt;.r2.cloudflarestorage.com">
                </div>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                <input type="hidden" name="r2_enabled" value="0">
                <input type="checkbox" name="r2_enabled" id="r2_enabled" value="1" @checked(old('r2_enabled', $r2['enabled'] ?? false))>
                Activar R2 (copiar media al importar y al guardar producto)
            </label>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="admin-btn">Guardar R2</button>
                <button type="button" class="js-api-test admin-btn-secondary" data-provider="r2" data-url="{{ route('admin.settings.general.api.test') }}">Probar conexión</button>
            </div>
            @php
                $r2PublicPrefix = (string) ($r2['public_prefix'] ?? 'f');
                $r2UrlHelp = 'Las URLs públicas quedan en <code>https://tu-dominio/'.$r2PublicPrefix.'/stores/{tienda}/products/{id}/…</code> (proxy Laravel → R2).';
            @endphp
            @include('admin.settings.partials.api-help', [
                'title' => 'Cómo crear el bucket y las API keys',
                'steps' => [
                    'En <a class="text-teal hover:underline" href="https://dash.cloudflare.com/?to=/:account/r2/overview" target="_blank" rel="noopener">R2 → Overview</a> crea un bucket (ej. <code>multidrop-media</code>).',
                    'En el bucket → <strong>Settings</strong> copia el nombre exacto aquí.',
                    'Ve a <a class="text-teal hover:underline" href="https://dash.cloudflare.com/?to=/:account/r2/api-tokens" target="_blank" rel="noopener">R2 → Manage API tokens</a> → <strong>Create API token</strong>.',
                    'Permisos: <strong>Object Read &amp; Write</strong> sobre ese bucket (o toda la cuenta si prefieres).',
                    'Copia <strong>Access Key ID</strong> y <strong>Secret Access Key</strong> (solo se muestran una vez).',
                    'Pega Account ID, bucket y llaves aquí, activa R2, guarda y pulsa <strong>Probar conexión</strong>.',
                    $r2UrlHelp,
                ],
            ])
            @if(($r2['last_test_at'] ?? null))
                <p class="js-api-test-last text-xs {{ ($r2['last_test_ok'] ?? false) ? 'text-teal' : 'text-coral' }}">
                    Última prueba: {{ \Illuminate\Support\Carbon::parse($r2['last_test_at'])->diffForHumans() }}
                    — {{ $r2['last_test_message'] ?? '' }}
                </p>
            @else
                <p class="js-api-test-last text-xs text-ink-soft/55 hidden"></p>
            @endif
        </form>

        <div class="space-y-3 rounded-2xl border border-line bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Uso de almacenamiento por tienda</h3>
                    <p class="mt-1 text-xs text-ink-soft/60">Bytes en R2 bajo <code>stores/{id}/</code>. Se actualiza al importar o subir media.</p>
                </div>
                <form method="post" action="{{ route('admin.settings.general.r2.refresh-stats') }}">
                    @csrf
                    <button type="submit" class="admin-btn-secondary text-xs" @disabled(empty($r2['ready']))>Recalcular desde R2</button>
                </form>
            </div>
            @php
                $r2Rows = collect($commerce['stores'] ?? []);
                $r2TotalBytes = (int) $r2Rows->sum('r2_bytes');
                $r2TotalFiles = (int) $r2Rows->sum('r2_files');
            @endphp
            <div class="grid gap-2 sm:grid-cols-3">
                <div class="rounded-xl border border-line bg-mist/30 px-3 py-2 text-sm">
                    <span class="text-ink-soft/60">Total plataforma</span>
                    <div class="font-semibold text-ink">{{ app(\App\Services\Storage\R2StorageManager::class)->formatBytes($r2TotalBytes) }}</div>
                </div>
                <div class="rounded-xl border border-line bg-mist/30 px-3 py-2 text-sm">
                    <span class="text-ink-soft/60">Archivos</span>
                    <div class="font-semibold text-ink">{{ number_format($r2TotalFiles) }}</div>
                </div>
                <div class="rounded-xl border border-line bg-mist/30 px-3 py-2 text-sm">
                    <span class="text-ink-soft/60">Free tier R2</span>
                    <div class="font-semibold text-ink">10 GB/mes incluidos</div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="admin-table w-full min-w-[640px] text-sm">
                    <thead>
                        <tr>
                            <th class="text-left">Tienda</th>
                            <th class="text-left">Tipo</th>
                            <th class="text-right">Imágenes</th>
                            <th class="text-right">Videos</th>
                            <th class="text-right">Total</th>
                            <th class="text-left">Última sync</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($r2Rows as $row)
                            <tr>
                                <td class="font-medium text-ink">{{ $row['name'] }}</td>
                                <td class="text-ink-soft/70">{{ $row['type'] }}</td>
                                <td class="text-right tabular-nums">{{ (int) ($row['r2_images'] ?? 0) }}</td>
                                <td class="text-right tabular-nums">{{ (int) ($row['r2_videos'] ?? 0) }}</td>
                                <td class="text-right tabular-nums font-medium">{{ $row['r2_human'] ?? '0 B' }}</td>
                                <td class="text-xs text-ink-soft/60">
                                    @if(!empty($row['r2_synced_at']))
                                        {{ \Illuminate\Support\Carbon::parse($row['r2_synced_at'])->diffForHumans() }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-ink-soft/60">No hay tiendas activas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form method="post" action="{{ route('admin.settings.general.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('PUT')

        <div class="admin-blocks" id="sec-ai" data-engines-url="{{ $ai['engines_url'] }}">
            <div class="admin-card p-5 sm:p-6 space-y-4 h-full">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Inteligencia artificial</h2>
                    <p class="mt-1 text-sm text-ink-soft/70">Solo MIIA (ia.ceballosleon.com). Cada petición usa el motor que elijas: <strong>free</strong> para texto y un motor de imagen para combos.</p>
                </div>

                <div class="rounded-2xl border border-line bg-mist/40 p-4 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-ink">MIIA — ia.ceballosleon.com</h3>
                        @include('admin.settings.partials.api-test-btn', ['provider' => 'miia'])
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">API Key {{ $hasDb['miia_api_key'] ? '(guardada · deja ******** para no cambiar)' : '' }}</label>
                        <input name="miia_api_key" value="{{ old('miia_api_key', $ai['miia_api_key']) }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="mia_…">
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Base URL</label>
                            <input name="miia_base_url" value="{{ old('miia_base_url', $ai['miia_base_url']) }}" class="admin-input" placeholder="https://ia.ceballosleon.com">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Modelo fallback</label>
                            <input name="miia_model" value="{{ old('miia_model', $ai['miia_model']) }}" class="admin-input" placeholder="auto">
                        </div>
                    </div>
                    @include('admin.settings.partials.api-help', [
                        'title' => 'Cómo obtener la API Key de MIIA',
                        'steps' => [
                            'Entra a <a class="text-teal hover:underline" href="https://ia.ceballosleon.com" target="_blank" rel="noopener">ia.ceballosleon.com</a> e inicia sesión.',
                            'En el panel, abre <strong>API Keys</strong> y crea o edita una clave (<code>mia_</code>).',
                            'Activa en esa clave la opción <strong>Generación de imágenes</strong> (si no, el copy funciona y las fotos de combos fallan).',
                            'Pégala aquí, guarda y pulsa <strong>Probar API</strong>. El aviso debe decir que las imágenes están habilitadas.',
                        ],
                    ])
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-ink">Peticiones → motor</h3>
                    <p class="mt-1 text-xs text-ink-soft/65">Cada petición usa solo motores de su tipo. Texto → <strong>free</strong>. Imágenes → <strong>gpt-image-1.5</strong> (size 1024×1536, quality high, PNG).</p>
                    <div class="mt-3 overflow-x-auto rounded-xl border border-line">
                        <table class="w-full text-sm">
                            <thead class="bg-mist/50 text-left text-xs uppercase tracking-wide text-ink-soft/70">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Petición</th>
                                    <th class="px-3 py-2 font-medium w-24">Tipo</th>
                                    <th class="px-3 py-2 font-medium">Motor</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(($ai['tasks'] ?? []) as $task)
                                    <tr class="border-t border-line">
                                        <td class="px-3 py-2 align-top">
                                            <span class="font-medium text-ink">{{ $task['label'] }}</span>
                                            @if(!empty($task['hint']))
                                                <span class="block text-xs text-ink-soft/60">{{ $task['hint'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            @if(($task['kind'] ?? '') === 'image')
                                                <span class="inline-block rounded-full bg-teal/10 px-2 py-0.5 text-[11px] font-medium text-teal">Imagen</span>
                                            @else
                                                <span class="inline-block rounded-full bg-mist px-2 py-0.5 text-[11px] font-medium text-ink-soft">Texto</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <select name="ai_task_engines[{{ $task['key'] }}]"
                                                    class="admin-input js-ai-engine text-sm"
                                                    data-kind="{{ $task['kind'] }}"
                                                    data-current="{{ $task['engine'] }}">
                                                @php
                                                    $opts = (($task['kind'] ?? '') === 'image')
                                                        ? ($ai['engines_image'] ?? [])
                                                        : ($ai['engines_chat'] ?? []);
                                                    $ids = collect($opts)->pluck('id')->all();
                                                    if ($task['engine'] && ! in_array($task['engine'], $ids, true)) {
                                                        $opts = array_merge([['id' => $task['engine'], 'label' => $task['engine'], 'kind' => $task['kind']]], $opts);
                                                    }
                                                @endphp
                                                @foreach($opts as $eng)
                                                    <option value="{{ $eng['id'] }}" @selected($task['engine'] === $eng['id'])>
                                                        {{ $eng['id'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4 h-full" id="sec-payments">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="font-display text-lg font-bold text-ink">Mercado Pago — API</h2>
                        <p class="text-sm text-ink-soft/70">Credenciales de pasarela para toda la plataforma.</p>
                    </div>
                    @include('admin.settings.partials.api-test-btn', ['provider' => 'mercadopago'])
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Public Key</label>
                    <input name="mp_public_key" value="{{ old('mp_public_key', $payments['mp_public_key']) }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Access Token {{ $hasDb['mp_access_token'] ? '(guardado · deja ******** para no cambiar)' : '' }}</label>
                    <input name="mp_access_token" value="{{ old('mp_access_token', $payments['mp_access_token']) }}" class="admin-input" autocomplete="off">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Webhook secret</label>
                    <input name="mp_webhook_secret" value="{{ old('mp_webhook_secret', $payments['mp_webhook_secret']) }}" class="admin-input" autocomplete="off">
                </div>
                @include('admin.settings.partials.api-help', [
                    'title' => 'Cómo obtener las llaves de Mercado Pago',
                    'steps' => [
                        'Entra a <a class="text-teal hover:underline" href="https://www.mercadopago.com/developers/panel" target="_blank" rel="noopener">Tus integraciones</a>.',
                        'Crea o abre una aplicación y copia <strong>Public Key</strong> y <strong>Access Token</strong> (producción o prueba).',
                        'El webhook secret es opcional: configúralo si usas notificaciones IPN/webhooks.',
                        'Guarda y pulsa <strong>Probar API</strong> (consulta <code>/users/me</code>).',
                    ],
                ])
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4 h-full">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="font-display text-lg font-bold text-ink">Stripe — API</h2>
                    @include('admin.settings.partials.api-test-btn', ['provider' => 'stripe'])
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Publishable key</label>
                    <input name="stripe_key" value="{{ old('stripe_key', $payments['stripe_key']) }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Secret key</label>
                    <input name="stripe_secret" value="{{ old('stripe_secret', $payments['stripe_secret']) }}" class="admin-input" autocomplete="off">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Webhook secret</label>
                    <input name="stripe_webhook_secret" value="{{ old('stripe_webhook_secret', $payments['stripe_webhook_secret']) }}" class="admin-input" autocomplete="off">
                </div>
                @include('admin.settings.partials.api-help', [
                    'title' => 'Cómo obtener las llaves de Stripe',
                    'steps' => [
                        'Entra a <a class="text-teal hover:underline" href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/apikeys</a>.',
                        'Copia <strong>Publishable key</strong> (<code>pk_</code>) y <strong>Secret key</strong> (<code>sk_</code>). Usa test keys mientras pruebas.',
                        'Webhooks: Developers → Webhooks → signing secret (<code>whsec_</code>).',
                        'Guarda y pulsa <strong>Probar API</strong> (consulta el balance).',
                    ],
                ])
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4 h-full">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="font-display text-lg font-bold text-ink">PayPal — API</h2>
                    @include('admin.settings.partials.api-test-btn', ['provider' => 'paypal'])
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Client ID</label>
                        <input name="paypal_client_id" value="{{ old('paypal_client_id', $payments['paypal_client_id']) }}" class="admin-input">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Mode</label>
                        <select name="paypal_mode" class="admin-input">
                            <option value="sandbox" @selected(old('paypal_mode', $payments['paypal_mode']) === 'sandbox')>Sandbox</option>
                            <option value="live" @selected(old('paypal_mode', $payments['paypal_mode']) === 'live')>Live</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Client Secret</label>
                    <input name="paypal_client_secret" value="{{ old('paypal_client_secret', $payments['paypal_client_secret']) }}" class="admin-input" autocomplete="off">
                </div>
                @include('admin.settings.partials.api-help', [
                    'title' => 'Cómo obtener las llaves de PayPal',
                    'steps' => [
                        'Entra a <a class="text-teal hover:underline" href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener">developer.paypal.com</a>.',
                        'Crea o abre una app y copia <strong>Client ID</strong> y <strong>Secret</strong> (Sandbox o Live).',
                        'Elige el mismo Mode aquí (sandbox / live). Guarda y pulsa <strong>Probar API</strong> (pide un token OAuth).',
                    ],
                ])
            </div>
        </div>

        <div class="rounded-2xl border border-line bg-mist/30 px-4 py-3 text-sm text-ink-soft">
            Credenciales de pasarelas para toda la plataforma. En el <strong>General</strong> de cada tienda solo se habilita el pago y se elige el tipo de pasarela.
            Los secretos se guardan cifrados y sobrescriben el <code>.env</code> en runtime. Deja <code>********</code> para conservar el valor actual.
        </div>

        {{-- Seguridad / Cloudflare --}}
        @php $sec = $security ?? []; $docs = $sec['docs'] ?? []; @endphp
        <div class="admin-card p-5 sm:p-6 space-y-5 admin-card-span-2" id="sec-security">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Seguridad y Cloudflare</h2>
                    <p class="mt-1 text-sm text-ink-soft/70">
                        Turnstile en checkout/login comprador, guía anti-bot / anti-crawler IA, y antifraude ligero en la app.
                        La capa fuerte es la zona Cloudflare; aquí guardas claves y checklist.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if(!empty($sec['turnstile_ready']))
                        <span class="admin-badge bg-teal/10 text-teal">Turnstile listo</span>
                    @else
                        <span class="admin-badge bg-coral/10 text-coral">Turnstile pendiente</span>
                    @endif
                    @if(!empty($sec['access_enabled']))
                        <span class="admin-badge bg-teal/10 text-teal">Access ON</span>
                    @else
                        <span class="admin-badge bg-mist text-ink-soft">Access OFF</span>
                    @endif
                    @if(!empty($sec['browser_ready']))
                        <span class="admin-badge bg-teal/10 text-teal">Browser Rendering ON</span>
                    @else
                        <a href="#sec-cf-browser" class="admin-badge bg-mist text-ink-soft hover:bg-mist/80">Browser Rendering OFF</a>
                    @endif
                    @include('admin.settings.partials.api-test-btn', ['provider' => 'turnstile'])
                </div>
            </div>
            @include('admin.settings.partials.api-help', [
                'title' => 'Cómo obtener las llaves de Turnstile',
                'steps' => [
                    'En Cloudflare → <a class="text-teal hover:underline" href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">Turnstile</a> crea un widget para tu dominio.',
                    'Copia <strong>Site Key</strong> y <strong>Secret Key</strong> y pégalas arriba.',
                    'Guarda y pulsa <strong>Probar API</strong> (verifica que ambas estén guardadas).',
                ],
            ])

            <ol class="list-decimal space-y-2 pl-5 text-sm text-ink-soft/80">
                <li>Crea o apunta el dominio a Cloudflare (DNS proxied / naranja).</li>
                <li>
                    Activa <strong>Bot Fight Mode</strong>
                    @if(!empty($docs['bot_fight']))
                        — <a class="text-teal hover:underline" href="{{ $docs['bot_fight'] }}" target="_blank" rel="noopener">docs ↗</a>
                    @endif
                </li>
                <li>
                    Configura <strong>AI Crawl Control</strong> / bloquea GPTBot, ClaudeBot, Bytespider, etc.
                    @if(!empty($docs['ai_crawl']))
                        — <a class="text-teal hover:underline" href="{{ $docs['ai_crawl'] }}" target="_blank" rel="noopener">docs ↗</a>
                    @endif
                    (también hay Disallow en <code>public/robots.txt</code>).
                </li>
                <li>
                    Crea un widget <strong>Turnstile</strong> y pega site/secret abajo
                    @if(!empty($docs['turnstile']))
                        — <a class="text-teal hover:underline" href="{{ $docs['turnstile'] }}" target="_blank" rel="noopener">docs ↗</a>
                    @endif
                </li>
                <li>
                    Crawler AliExpress: <a href="#sec-cf-browser" class="text-teal hover:underline">Cloudflare Browser Rendering</a>
                    (token <strong>custom</strong>: Account → Browser Rendering → Edit; no hay plantilla).
                </li>
                <li>
                    (Opcional) Cloudflare Access para <code>/admin</code> vía
                    <code>CLOUDFLARE_ACCESS_*</code>
                    @if(!empty($docs['access']))
                        — <a class="text-teal hover:underline" href="{{ $docs['access'] }}" target="_blank" rel="noopener">docs ↗</a>
                    @endif
                </li>
            </ol>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Turnstile site key</label>
                    <input name="turnstile_site_key" value="{{ old('turnstile_site_key', $sec['turnstile_site_key'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Turnstile secret {{ !empty($hasDb['turnstile_secret']) ? '(guardado · deja ********)' : '' }}</label>
                    <input name="turnstile_secret_key" value="{{ old('turnstile_secret_key', $sec['turnstile_secret_key'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="********">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Máx. pedidos / email / hora (antifraude)</label>
                    <input type="number" min="1" max="100" name="fraud_max_orders_per_hour" value="{{ old('fraud_max_orders_per_hour', $sec['max_orders_per_hour'] ?? 8) }}" class="admin-input">
                </div>
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="bot_fight_ack" value="1" @checked(old('bot_fight_ack', $sec['bot_fight_ack'] ?? false))>
                    Confirmé Bot Fight Mode en Cloudflare
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="ai_crawl_ack" value="1" @checked(old('ai_crawl_ack', $sec['ai_crawl_ack'] ?? false))>
                    Confirmé AI Crawl Control / bloqueo de bots IA
                </label>
            </div>

            <div class="rounded-2xl border border-dashed border-line bg-mist/30 p-4 text-sm text-ink-soft/75">
                <strong class="text-ink">Listo cuando…</strong>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Turnstile configurado: {{ !empty($sec['turnstile_ready']) ? 'sí' : 'no' }}</li>
                    <li>Access enabled (env): {{ !empty($sec['access_enabled']) ? 'sí' : 'no' }}</li>
                    <li>Bot Fight checklist: {{ !empty($sec['bot_fight_ack']) ? 'sí' : 'no' }}</li>
                    <li>AI crawl checklist: {{ !empty($sec['ai_crawl_ack']) ? 'sí' : 'no' }}</li>
                </ul>
            </div>
        </div>

        {{-- Monedas / FX --}}
        <div class="admin-card p-5 sm:p-6 space-y-4 admin-card-span-2" id="currency-fx-panel">
            <div class="flex flex-wrap items-start justify-between gap-3" id="sec-currency">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Monedas y conversión</h2>
                    <p class="mt-1 text-sm text-ink-soft/70">
                        Moneda base y tasas (1 <span class="font-mono" id="fx-base-label">{{ $currency['base'] }}</span> = X).
                        Usadas al cambiar moneda en productos, Product Hunter e importaciones.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="admin-btn-secondary" id="fx-fetch-btn">Actualizar desde API pública</button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Moneda base</label>
                    <select name="currency_base" id="currency_base" class="admin-input">
                        @foreach($currency['catalog'] as $row)
                            <option value="{{ $row['code'] }}" @selected(old('currency_base', $currency['base']) === $row['code'])>
                                {{ $row['code'] }} — {{ $row['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2 flex items-end">
                    <p class="text-xs text-ink-soft/60" id="fx-status">
                        @if($currency['updated_at'])
                            Última actualización: {{ \Illuminate\Support\Carbon::parse($currency['updated_at'])->diffForHumans() }}
                        @else
                            Aún no se han guardado tasas personalizadas (se usan defaults).
                        @endif
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-line">
                <table class="min-w-full text-sm">
                    <thead class="bg-mist/50 text-left text-ink-soft">
                        <tr>
                            <th class="px-3 py-2 font-medium">Moneda</th>
                            <th class="px-3 py-2 font-medium">Tasa (1 base →)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($currency['catalog'] as $row)
                            <tr class="border-t border-line/70" data-fx-row="{{ $row['code'] }}">
                                <td class="px-3 py-2">
                                    <span class="font-mono font-semibold text-ink">{{ $row['code'] }}</span>
                                    <span class="text-ink-soft/60"> · {{ $row['label'] }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <input
                                        type="number"
                                        step="any"
                                        min="0"
                                        name="fx_rates[{{ $row['code'] }}]"
                                        value="{{ old('fx_rates.'.$row['code'], $row['rate']) }}"
                                        class="admin-input font-mono text-sm fx-rate-input"
                                        data-code="{{ $row['code'] }}"
                                        @if($row['code'] === $currency['base']) readonly @endif
                                    >
                                    <input type="hidden" name="fx_rounding[{{ $row['code'] }}]" value="{{ old('fx_rounding.'.$row['code'], $row['rounding']) }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-ink-soft/55">
                API: <code>frankfurter.app</code> (BCE) con respaldo <code>open.er-api.com</code>. Tras traer tasas, revisa y guarda.
                Al cambiar la moneda de un producto se convierten precio y compare con estas tasas.
            </p>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-4 admin-card-span-2" id="sec-pixels">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Pixels de marketing</h2>
                <p class="mt-1 text-sm text-ink-soft/70">Google Analytics 4 y Meta Pixel se inyectan en todas las tiendas y mini-tiendas. Las IDs no se muestran en el frontend más allá del snippet oficial.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Google Analytics (Measurement ID)</label>
                    <input name="ga_measurement_id" value="{{ old('ga_measurement_id', $pixels['ga_measurement_id'] ?? '') }}" class="admin-input font-mono text-sm" placeholder="G-XXXXXXXXXX" autocomplete="off">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Meta Pixel ID</label>
                    <input name="meta_pixel_id" value="{{ old('meta_pixel_id', $pixels['meta_pixel_id'] ?? '') }}" class="admin-input font-mono text-sm" placeholder="123456789012345" autocomplete="off">
                </div>
            </div>
            @include('admin.settings.partials.api-help', [
                'title' => 'Cómo obtener GA4 y Meta Pixel',
                'steps' => [
                    'GA4: <a class="text-teal hover:underline" href="https://analytics.google.com/" target="_blank" rel="noopener">analytics.google.com</a> → Admin → Data streams → copia el <strong>Measurement ID</strong> (<code>G-</code>).',
                    'Meta: <a class="text-teal hover:underline" href="https://business.facebook.com/events_manager" target="_blank" rel="noopener">Events Manager</a> → Data sources → Pixel → copia el <strong>Pixel ID</strong> (solo números).',
                    'Guarda esta página. El snippet se carga en el <code>&lt;head&gt;</code> de cada storefront.',
                ],
            ])
        </div>

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-4 h-full" id="sec-contact">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-display text-lg font-bold text-ink">Contacto de la plataforma</h2>
                        <p class="mt-1 text-sm text-ink-soft/70">Se muestra en el agradecimiento del pedido y en reclamos del portal comprador.</p>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Email de contacto</label>
                    <input type="email" name="contact_email" value="{{ old('contact_email', $contact['email'] ?? '') }}" class="admin-input" placeholder="soporte@tudominio.com">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Teléfono</label>
                    <input name="contact_phone" value="{{ old('contact_phone', $contact['phone'] ?? '') }}" class="admin-input" placeholder="+52 55 1234 5678">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">WhatsApp (solo dígitos con código país)</label>
                    <input name="contact_whatsapp" value="{{ old('contact_whatsapp', $contact['whatsapp'] ?? '') }}" class="admin-input" placeholder="5215512345678">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Horario</label>
                    <input name="contact_hours" value="{{ old('contact_hours', $contact['hours'] ?? '') }}" class="admin-input" placeholder="Lun–Vie 9:00–18:00 (CDMX)">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nota / mensaje</label>
                    <textarea name="contact_note" rows="3" class="admin-input" placeholder="Te respondemos en menos de 24 h hábiles.">{{ old('contact_note', $contact['note'] ?? '') }}</textarea>
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4 h-full" id="sec-mail">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-display text-lg font-bold text-ink">Correo — Resend</h2>
                        <p class="mt-1 text-sm text-ink-soft/70">
                            Transaccional vía <a href="https://resend.com/" target="_blank" rel="noopener" class="text-teal hover:underline">Resend</a>
                            (confirmaciones de pedido, newsletter, etc.).
                        </p>
                    </div>
                    @if(($mail['driver'] ?? '') === 'resend' && ($mail['ready'] ?? false))
                        <span class="admin-badge bg-teal/10 text-teal">Resend listo</span>
                    @elseif(($mail['driver'] ?? '') === 'resend')
                        <span class="admin-badge bg-coral/10 text-coral">Falta API key o From</span>
                    @else
                        <span class="admin-badge bg-mist text-ink-soft">{{ strtoupper($mail['driver'] ?? 'log') }}</span>
                    @endif
                    @include('admin.settings.partials.api-test-btn', ['provider' => 'resend'])
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Mailer</label>
                    <select name="mail_driver" class="admin-input">
                        <option value="resend" @selected(old('mail_driver', $mail['driver'] ?? '') === 'resend')>Resend (recomendado)</option>
                        <option value="log" @selected(old('mail_driver', $mail['driver'] ?? '') === 'log')>Log (desarrollo)</option>
                        <option value="smtp" @selected(old('mail_driver', $mail['driver'] ?? '') === 'smtp')>SMTP (.env)</option>
                        <option value="array" @selected(old('mail_driver', $mail['driver'] ?? '') === 'array')>Array (tests)</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">
                        API Key de Resend {{ !empty($hasDb['resend_api_key']) ? '(guardada · deja ******** para no cambiar)' : '' }}
                    </label>
                    <input name="resend_api_key" value="{{ old('resend_api_key', $mail['resend_api_key'] ?? '') }}" class="admin-input font-mono text-sm" autocomplete="off" placeholder="re_…">
                    <p class="mt-1 text-xs text-ink-soft/55">Créala en el dashboard de Resend → API Keys. Verifica tu dominio (DKIM/SPF) para mejor entrega.</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">From (email)</label>
                    <input type="email" name="mail_from_address" value="{{ old('mail_from_address', $mail['from_address'] ?? '') }}" class="admin-input" placeholder="pedidos@tudominio.com">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">From (nombre)</label>
                    <input name="mail_from_name" value="{{ old('mail_from_name', $mail['from_name'] ?? '') }}" class="admin-input" placeholder="Multidrop">
                </div>
                @include('admin.settings.partials.api-help', [
                    'title' => 'Cómo obtener la API Key de Resend',
                    'steps' => [
                        'Entra a <a class="text-teal hover:underline" href="https://resend.com/api-keys" target="_blank" rel="noopener">resend.com/api-keys</a> y crea una key (<code>re_</code>).',
                        'Verifica tu dominio (DKIM/SPF) y usa un From de ese dominio.',
                        'Guarda y pulsa <strong>Probar API</strong> (lista dominios).',
                    ],
                ])
            </div>
        </div>

        <button class="admin-btn">Guardar configuración</button>
    </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function ($) {
  var csrf = @json(csrf_token());

  function toast(ok, message) {
    if (window.AdminToast) {
      if (ok) window.AdminToast.success(message);
      else window.AdminToast.error(message);
      return;
    }
    alert(message);
  }

  function looksLikeImageEngine(id) {
    return /image|dall-?e|flux|imagen|seedream|ideogram|recraft|midjourney|nano-banana/i.test(String(id || ''));
  }

  function engineKind(eng) {
    var id = String((eng && eng.id) || eng || '');
    if ((eng && eng.kind) === 'image' || looksLikeImageEngine(id)) return 'image';
    return 'chat';
  }

  function fillAiEngineSelects(engines) {
    if (!engines || !engines.length) return;
    $('.js-ai-engine').each(function () {
      var $sel = $(this);
      var kind = String($sel.data('kind') || 'chat') === 'image' ? 'image' : 'chat';
      var current = String($sel.data('current') || $sel.val() || '');
      if (current && ((looksLikeImageEngine(current) ? 'image' : 'chat') !== kind)) {
        current = kind === 'image' ? 'gpt-image-1.5' : 'free';
        $sel.data('current', current);
      }
      var preferred = kind === 'image'
        ? ['gpt-image-1.5', 'gpt-image-1', 'dall-e-3', 'dall-e-2']
        : ['free', 'auto', 'groq', 'openai'];
      var byId = {};
      engines.forEach(function (eng) {
        var id = String((eng && eng.id) || eng || '');
        if (!id || engineKind(eng) !== kind) return;
        byId[id] = id;
      });
      preferred.forEach(function (id) {
        if (!byId[id]) byId[id] = id;
      });
      var ids = preferred.filter(function (id) { return !!byId[id]; });
      Object.keys(byId).forEach(function (id) {
        if (ids.indexOf(id) === -1) ids.push(id);
      });
      var html = '';
      var seen = {};
      ids.forEach(function (id) {
        if (!id || seen[id]) return;
        seen[id] = true;
        html += '<option value="' + $('<div>').text(id).html() + '">' + $('<div>').text(id).html() + '</option>';
      });
      if (current && !seen[current]) {
        html = '<option value="' + $('<div>').text(current).html() + '">' + $('<div>').text(current).html() + '</option>' + html;
      }
      $sel.html(html);
      if (current) $sel.val(current);
    });
  }

  $(function () {
    var enginesUrl = String($('#sec-ai').data('engines-url') || '');
    if (!enginesUrl) return;
    $.getJSON(enginesUrl).done(function (res) {
      if (res && res.engines) fillAiEngineSelects(res.engines);
    });
  });

  function statusEl($btn) {
    var $s = $btn.siblings('.js-api-test-status');
    if (! $s.length) {
      $s = $('<span class="js-api-test-status text-xs"></span>');
      $btn.after($s);
    }
    return $s;
  }

  $(document).on('click', '.js-api-test', function () {
    var $btn = $(this);
    var provider = String($btn.data('provider') || '');
    var url = String($btn.data('url') || '');
    if (!url || $btn.prop('disabled')) return;

    var label = $btn.text();
    var $form = $btn.closest('form');
    var $last = $form.find('.js-api-test-last');
    var $st = statusEl($btn);
    $btn.prop('disabled', true).text('Probando…');
    if ($last.length) {
      $st.addClass('hidden');
      $last.removeClass('hidden text-teal text-coral')
        .addClass('text-ink-soft/60')
        .text('Consultando…');
    } else {
      $st.removeClass('text-teal text-coral hidden').addClass('text-ink-soft/60').text('Consultando…');
    }

    var payload = { _token: csrf };
    if (provider === 'cj') {
      payload.cj_api_key = $('#cj_api_key').val() || '';
    } else if (provider === 'aliexpress') {
      payload.provider = 'aliexpress';
      payload.aliexpress_app_key = $('#aliexpress_app_key').val() || '';
      payload.aliexpress_app_secret = $('#aliexpress_app_secret').val() || '';
      payload.aliexpress_tracking_id = $('#aliexpress_tracking_id').val() || '';
      payload.aliexpress_test_product_id = $('#aliexpress_test_product_id').val() || '';
    } else if (provider === 'cloudflare_browser') {
      payload.provider = 'cloudflare_browser';
      payload.cf_account_id = $('#cf_account_id').val() || '';
      payload.cf_api_token = $('#cf_api_token').val() || '';
      payload.cf_browser_rendering = $('#cf_browser_rendering').is(':checked') ? '1' : '0';
      payload.cf_browser_test_url = $('#cf_browser_test_url').val() || '';
    } else if (provider === 'r2') {
      payload.provider = 'r2';
      payload.r2_enabled = $('#r2_enabled').is(':checked') ? '1' : '0';
      payload.r2_account_id = $('#r2_account_id').val() || '';
      payload.r2_access_key_id = $('#r2_access_key_id').val() || '';
      payload.r2_secret_access_key = $('#r2_secret_access_key').val() || '';
      payload.r2_bucket = $('#r2_bucket').val() || '';
      payload.r2_endpoint = $('#r2_endpoint').val() || '';
    } else {
      payload.provider = provider;
    }

    $.ajax({
      url: url,
      method: 'POST',
      data: payload,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).done(function (res) {
      var ok = !!(res && (res.ok || res.success));
      var msg = (res && res.message) || (ok ? 'API OK' : 'La prueba falló');
      if ($last.length) {
        $st.addClass('hidden');
        $last.removeClass('hidden text-teal text-coral text-ink-soft/60')
          .addClass(ok ? 'text-teal' : 'text-coral')
          .text('Última prueba: ahora — ' + msg);
      } else {
        $st.toggleClass('text-teal', ok).toggleClass('text-coral', !ok).removeClass('text-ink-soft/60 hidden').text(msg);
        toast(ok, msg);
      }
      if (provider === 'miia' && res && Array.isArray(res.engines)) {
        fillAiEngineSelects(res.engines);
      }
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error))
        || 'No se pudo probar la API';
      if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
        var first = Object.values(xhr.responseJSON.errors)[0];
        if (Array.isArray(first) && first[0]) msg = first[0];
      }
      if ($last.length) {
        $st.addClass('hidden');
        $last.removeClass('hidden text-teal text-ink-soft/60').addClass('text-coral').text('Última prueba: ahora — ' + msg);
      } else {
        $st.removeClass('text-teal text-ink-soft/60 hidden').addClass('text-coral').text(msg);
        toast(false, msg);
      }
    }).always(function () {
      $btn.prop('disabled', false).text(label);
    });
  });

  $('#cj-mcp-copy').on('click', function () {
    var url = $('#cj-mcp-url-full').val();
    if (!url) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        alert('URL MCP copiada');
      });
    } else {
      prompt('Copia la URL MCP:', url);
    }
  });

  function setBaseReadonly(base) {
    $('#fx-base-label').text(base);
    $('.fx-rate-input').each(function () {
      var code = $(this).data('code');
      var isBase = code === base;
      $(this).prop('readonly', isBase);
      if (isBase) $(this).val('1');
    });
  }

  $('#currency_base').on('change', function () {
    setBaseReadonly(String($(this).val() || 'USD').toUpperCase());
    if (confirm('¿Actualizar tasas desde la API para la nueva moneda base?')) {
      $('#fx-fetch-btn').trigger('click');
    }
  });

  $('#fx-fetch-btn').on('click', function () {
    var $btn = $(this);
    var base = String($('#currency_base').val() || 'USD').toUpperCase();
    $btn.prop('disabled', true).text('Consultando…');
    $('#fx-status').text('Obteniendo tasas para base ' + base + '…');

    $.ajax({
      url: @json($currency['fetch_url']),
      method: 'POST',
      data: {
        _token: @json(csrf_token()),
        base: base,
        persist: 0
      }
    }).done(function (res) {
      if (!res || !res.success || !res.rates) {
        $('#fx-status').text((res && res.error) || 'No se pudieron obtener tasas');
        return;
      }
      Object.keys(res.rates).forEach(function (code) {
        var $input = $('.fx-rate-input[data-code="' + code + '"]');
        if ($input.length) $input.val(res.rates[code]);
      });
      setBaseReadonly(res.base || base);
      $('#fx-status').text(res.message || 'Tasas cargadas. Revisa y guarda.');
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Error al consultar la API';
      $('#fx-status').text(msg);
    }).always(function () {
      $btn.prop('disabled', false).text('Actualizar desde API pública');
    });
  });
})(jQuery);
</script>
@endpush
