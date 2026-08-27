@extends('layouts.admin')

@php
    $tab = in_array($tab ?? '', ['list', 'config'], true) ? $tab : 'list';
    $status = $status ?? 'confirmed';
    $statusLabels = [
        'confirmed' => 'Confirmado',
        'pending' => 'Pendiente',
        'unsubscribed' => 'Baja',
    ];
    $sourceLabels = [
        'popup' => 'Popup',
        'checkout' => 'Checkout',
    ];
    $filterLabels = [
        'confirmed' => 'Confirmados',
        'pending' => 'Pendientes',
        'unsubscribed' => 'Bajas',
        'all' => 'Todos',
    ];
    $tz = config('app.timezone');
@endphp

@section('title', 'Newsletter — '.$store->name)
@section('heading', 'Newsletter')
@section('subheading', $store->name.' · '.$confirmedCount.' confirmados')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <a href="{{ route('admin.store.newsletter.export', array_filter(['status' => $status, 'q' => $q ?: null])) }}" class="admin-btn">
            Exportar CSV
        </a>
    </div>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('admin.store.newsletter.edit', ['status' => 'confirmed', 'tab' => 'list']) }}"
           class="admin-card p-4 block hover:border-teal/40 {{ $status === 'confirmed' && $tab === 'list' ? 'ring-1 ring-teal/40' : '' }}">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Confirmados</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $confirmedCount }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">Listos para campañas</p>
        </a>
        <a href="{{ route('admin.store.newsletter.edit', ['status' => 'pending', 'tab' => 'list']) }}"
           class="admin-card p-4 block hover:border-teal/40 {{ $status === 'pending' && $tab === 'list' ? 'ring-1 ring-teal/40' : '' }}">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Pendientes</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $pendingCount }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">Sin confirmar el correo</p>
        </a>
        <a href="{{ route('admin.store.newsletter.edit', ['status' => 'all', 'tab' => 'list']) }}"
           class="admin-card p-4 block hover:border-teal/40 {{ $status === 'all' && $tab === 'list' ? 'ring-1 ring-teal/40' : '' }}">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Total</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $totalCount }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">{{ $unsubscribedCount }} bajas</p>
        </a>
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Cupón de bienvenida</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $cfg['coupon_hint'] }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">{{ $cfg['coupon_days'] }} días · 1 uso</p>
        </div>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="flex flex-wrap gap-1 border-b border-line bg-mist/40 px-2 pt-2" data-nl-tabs>
            <button type="button" data-tab="list" class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $tab === 'list' ? 'border-teal text-teal' : 'border-transparent text-ink-soft/65 hover:text-ink' }}">Suscriptores</button>
            <button type="button" data-tab="config" class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $tab === 'config' ? 'border-teal text-teal' : 'border-transparent text-ink-soft/65 hover:text-ink' }}">Popup y cupón</button>
        </div>

        <div class="{{ $tab === 'list' ? '' : 'hidden' }}" data-tab-panel="list">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <nav class="flex flex-wrap gap-1" aria-label="Filtro de suscriptores">
                    @foreach($filterLabels as $key => $label)
                        @if($key === 'unsubscribed' && $unsubscribedCount < 1)
                            @continue
                        @endif
                        <a href="{{ route('admin.store.newsletter.edit', array_filter(['status' => $key, 'tab' => 'list', 'q' => $q ?: null])) }}"
                           class="-mb-px border-b-2 px-3 py-1.5 text-sm {{ $status === $key ? 'border-teal text-teal font-medium' : 'border-transparent text-ink-soft/65 hover:text-ink' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>
                <form method="get" action="{{ route('admin.store.newsletter.edit') }}" class="flex gap-2">
                    <input type="hidden" name="status" value="{{ $status }}">
                    <input type="hidden" name="tab" value="list">
                    <input type="search" name="q" value="{{ $q }}" class="admin-input !py-1.5 !px-3 text-sm min-w-[12rem]" placeholder="Buscar email…">
                    <button class="admin-btn-secondary !py-1.5 !px-3 text-sm">Buscar</button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                        <th class="px-4 py-3 font-semibold">Email</th>
                        <th class="px-4 py-3 font-semibold">Estado</th>
                        <th class="px-4 py-3 font-semibold">Origen</th>
                        <th class="px-4 py-3 font-semibold">Cupón</th>
                        <th class="px-4 py-3 font-semibold">Confirmado</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($subscribers as $s)
                        <tr class="border-b border-line/70 last:border-0">
                            <td class="px-4 py-3 font-semibold text-ink">{{ $s->email }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $badge = match($s->status) {
                                        'confirmed' => 'bg-emerald-100 text-emerald-800',
                                        'pending' => 'bg-amber-100 text-amber-800',
                                        default => 'bg-slate-100 text-slate-700',
                                    };
                                @endphp
                                <span class="admin-badge {{ $badge }}">{{ $statusLabels[$s->status] ?? $s->status }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-soft">{{ $sourceLabels[$s->source] ?? $s->source }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $s->coupon_code ?: '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-ink-soft/80">
                                {{ $s->confirmed_at ? $s->confirmed_at->timezone($tz)->format('d/m/Y H:i') : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-ink-soft/60">
                                @if($q !== '')
                                    Ningún email coincide con «{{ $q }}».
                                @elseif($status === 'confirmed')
                                    Aún no hay confirmados.
                                    @if($pendingCount > 0)
                                        Hay {{ $pendingCount }} pendientes esperando el correo.
                                        <a class="text-teal hover:underline" href="{{ route('admin.store.newsletter.edit', ['status' => 'pending', 'tab' => 'list']) }}">Ver pendientes</a>
                                    @endif
                                @elseif($status === 'pending')
                                    Nadie está pendiente de confirmar.
                                @else
                                    Todavía no hay suscriptores.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($subscribers->total() > 0)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-3 text-sm text-ink-soft/60">
                    <span>{{ $subscribers->total() }} en esta vista · página {{ $subscribers->currentPage() }}</span>
                    <div>{{ $subscribers->links() }}</div>
                </div>
            @endif
        </div>

        <div class="p-4 sm:p-6 space-y-5 {{ $tab === 'config' ? '' : 'hidden' }}" data-tab-panel="config">
            <form method="post" action="{{ route('admin.store.newsletter.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="space-y-4">
                    <p class="text-sm text-ink-soft/70">
                        Popup en la tienda + checkbox en checkout. El visitante confirma el correo y recibe un cupón único.
                        En checkout, al marcar la casilla se confirma al instante con el email del pedido.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Título popup</label>
                            <input type="text" name="headline" value="{{ old('headline', $cfg['headline']) }}" class="admin-input" required maxlength="80">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Subtítulo</label>
                            <input type="text" name="subtitle" value="{{ old('subtitle', $cfg['subtitle']) }}" class="admin-input" maxlength="200">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto del botón</label>
                            <input type="text" name="cta" value="{{ old('cta', $cfg['cta']) }}" class="admin-input" required maxlength="40">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Posición FAB / popup</label>
                            <select name="position" class="admin-input">
                                <option value="bottom-right" @selected(old('position', $cfg['position']) === 'bottom-right')>Abajo derecha</option>
                                <option value="bottom-left" @selected(old('position', $cfg['position']) === 'bottom-left')>Abajo izquierda</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Mensaje tras registrar</label>
                            <input type="text" name="success_message" value="{{ old('success_message', $cfg['success_message']) }}" class="admin-input" maxlength="240">
                        </div>
                    </div>
                </div>

                <div class="border-t border-line pt-5 space-y-4">
                    <h3 class="font-semibold text-ink">Cupón de bienvenida</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Tipo</label>
                            <select name="coupon_type" class="admin-input">
                                <option value="percent" @selected(old('coupon_type', $cfg['coupon_type']) === 'percent')>Porcentaje</option>
                                <option value="fixed" @selected(old('coupon_type', $cfg['coupon_type']) === 'fixed')>Monto fijo</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Valor</label>
                            <input type="number" step="0.01" min="1" name="coupon_value" value="{{ old('coupon_value', $cfg['coupon_value']) }}" class="admin-input" required>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Válido (días)</label>
                            <input type="number" min="1" max="365" name="coupon_days" value="{{ old('coupon_days', $cfg['coupon_days']) }}" class="admin-input" required>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Prefijo del código</label>
                            <input type="text" name="coupon_prefix" value="{{ old('coupon_prefix', $cfg['coupon_prefix']) }}" class="admin-input" maxlength="8" placeholder="NL">
                        </div>
                    </div>
                    <p class="text-xs text-ink-soft/60">Ejemplo: <strong>{{ $cfg['coupon_hint'] }}</strong> · códigos tipo <code>{{ $cfg['coupon_prefix'] }}-XXXXXX</code> (1 uso).</p>
                </div>

                <div class="border-t border-line pt-5 space-y-4">
                    <h3 class="font-semibold text-ink">Checkout</h3>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="checkout_enabled" value="1" @checked(old('checkout_enabled', $cfg['checkout_enabled']))>
                        Mostrar checkbox en checkout
                    </label>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto del checkbox (usa {value})</label>
                        <input type="text" name="checkout_label" value="{{ old('checkout_label', $cfg['checkout_label']) }}" class="admin-input" maxlength="220">
                        <p class="mt-1 text-xs text-ink-soft/60">Vista previa: {{ $cfg['checkout_label_display'] }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="auto_open" value="1" @checked(old('auto_open', $cfg['auto_open']))>
                        Abrir popup automáticamente
                    </label>
                    <div class="max-w-xs">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Delay auto-open (ms)</label>
                        <input type="number" min="800" max="30000" name="auto_open_delay_ms" value="{{ old('auto_open_delay_ms', $cfg['auto_open_delay_ms']) }}" class="admin-input">
                    </div>
                </div>

                <button class="admin-btn">Guardar</button>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function ($) {
  var initial = @json($tab);
  function activate(tab) {
    if (!$('[data-nl-tabs] [data-tab="'+tab+'"]').length) tab = 'list';
    $('[data-nl-tabs] [data-tab]').removeClass('border-teal text-teal').addClass('border-transparent text-ink-soft/65');
    $('[data-nl-tabs] [data-tab="'+tab+'"]').addClass('border-teal text-teal').removeClass('border-transparent text-ink-soft/65');
    $('[data-tab-panel]').addClass('hidden');
    $('[data-tab-panel="'+tab+'"]').removeClass('hidden');
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState({}, '', url.toString());
  }
  $('[data-nl-tabs] [data-tab]').on('click', function () {
    activate($(this).data('tab'));
  });
  activate(initial);
})(jQuery);
</script>
@endpush
