@extends('layouts.admin')

@section('title', 'General')
@section('heading', 'General')
@section('subheading', 'APIs, contacto, Resend, pagos y CJ Dropshipping de toda la plataforma. Cada tienda configura lo suyo en su propio General.')

@section('content')
    @php $pixels = $pixels ?? ['ga_measurement_id' => '', 'meta_pixel_id' => '']; @endphp
    <div class="admin-settings-layout lg:flex lg:items-start lg:gap-6">
        <nav class="admin-settings-toc mb-4 lg:mb-0 lg:w-52 lg:shrink-0 lg:sticky lg:top-20" aria-label="Secciones de General">
            <p class="mb-2 hidden text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55 lg:block">Secciones</p>
            <div class="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
                <a href="#sec-cj">CJ / MCP</a>
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
                        Usadas al cambiar moneda en productos, CJ Search e importaciones.
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
    var $st = statusEl($btn);
    $btn.prop('disabled', true).text('Probando…');
    $st.removeClass('text-teal text-coral hidden').addClass('text-ink-soft/60').text('Consultando…');

    var payload = { _token: csrf };
    if (provider === 'cj') {
      payload.cj_api_key = $('#cj_api_key').val() || '';
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
      $st.toggleClass('text-teal', ok).toggleClass('text-coral', !ok).removeClass('text-ink-soft/60').text(msg);
      toast(ok, msg);
      if (provider === 'miia' && res && Array.isArray(res.engines)) {
        fillAiEngineSelects(res.engines);
      }
      if (provider === 'cj') {
        var $last = $btn.closest('form').find('.js-api-test-last');
        if ($last.length) {
          $last.removeClass('hidden text-teal text-coral')
            .addClass(ok ? 'text-teal' : 'text-coral')
            .text('Última prueba: ahora — ' + msg);
        }
      }
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error))
        || 'No se pudo probar la API';
      if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
        var first = Object.values(xhr.responseJSON.errors)[0];
        if (Array.isArray(first) && first[0]) msg = first[0];
      }
      $st.removeClass('text-teal text-ink-soft/60').addClass('text-coral').text(msg);
      toast(false, msg);
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
