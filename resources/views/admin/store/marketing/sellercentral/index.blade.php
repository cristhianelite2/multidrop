@extends('layouts.admin')

@php
    $networks = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'twitter' => 'Twitter / X',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'gmail' => 'Gmail',
    ];
    $availableNetworks = ($connection['ok'] ?? false) && ! empty($connection['enabled_networks'])
        ? array_values(array_filter($connection['enabled_networks'], fn ($n) => isset($networks[$n])))
        : array_keys($networks);
    $connectedNetworks = ($connection['ok'] ?? false) ? ($connection['connected_networks'] ?? []) : [];
    $badge = [
        'draft' => 'bg-slate-100 text-slate-700',
        'approved' => 'bg-indigo-100 text-indigo-800',
        'scheduled' => 'bg-sky-100 text-sky-800',
        'published' => 'bg-emerald-100 text-emerald-800',
        'error' => 'bg-rose-100 text-rose-800',
        'cancelled' => 'bg-amber-100 text-amber-800',
        'sent' => 'bg-emerald-100 text-emerald-800',
        'partial' => 'bg-amber-100 text-amber-800',
    ];
    $statusLabel = [
        'draft' => 'Borrador', 'approved' => 'Aprobada', 'scheduled' => 'Programada',
        'published' => 'Publicada', 'error' => 'Error', 'cancelled' => 'Cancelada',
        'sent' => 'Enviado', 'partial' => 'Parcial',
    ];
    $defaultStart = \Carbon\Carbon::tomorrow()->format('Y-m-d');
    $hasConfig = ! empty($state['has_api_key']);
@endphp

@section('title', 'Publicaciones — '.$store->name)
@section('heading', 'Publicaciones')
@section('subheading', 'Seller Central · programa y publica en tus redes')

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'sellercentral'])

    <div class="space-y-6">

        {{-- Conexión --}}
        <div class="admin-card overflow-hidden">
            <div class="border-b border-line px-4 py-3">
                <h3 class="font-semibold text-ink">Conexión con Seller Central</h3>
                <p class="mt-0.5 text-xs text-ink-soft/55">
                    Tienda = proyecto. Necesitas la <strong>API key del proyecto</strong> (Panel de Seller Central → Proyecto → Llave API).
                </p>
            </div>
            <form method="post" action="{{ route('admin.store.marketing.sellercentral.settings') }}" class="space-y-4 p-4 sm:p-6">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Base URL</label>
                        <input type="url" name="base_url" value="{{ old('base_url', $state['base_url']) }}" class="admin-input" maxlength="500" placeholder="https://sellercentral.ceballosleon.com">
                        <p class="mt-1 text-xs text-ink-soft/55">Vacío usa la URL global.</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">API key del proyecto</label>
                        <input type="password" name="api_key" value="" class="admin-input" maxlength="200" placeholder="{{ $state['has_api_key'] ? 'Guardada: '.$state['api_key_masked'] : 'Pega aquí la llave API…' }}" autocomplete="off">
                        <p class="mt-1 text-xs text-ink-soft/55">Dejalo vacío para conservar la actual.</p>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">URL del embed (panel visual)</label>
                    <input type="url" name="embed_url" value="{{ old('embed_url', $embedUrl) }}" class="admin-input" maxlength="500" placeholder="https://sellercentral.ceballosleon.com/embed/…">
                    <p class="mt-1 text-xs text-ink-soft/55">Se usa en la pestaña «Publicaciones» de las campañas.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button class="admin-btn">Guardar conexión</button>
                    <button type="button" class="admin-btn-secondary" data-sc-test>Probar conexión</button>
                    <span class="hidden text-xs font-medium" data-sc-test-status></span>
                </div>
            </form>

            @if($connection['ok'])
                <div class="flex flex-wrap items-center gap-2 border-t border-line bg-emerald-50/60 px-4 py-3 text-sm">
                    <span class="admin-badge bg-emerald-100 text-emerald-800">Conectado</span>
                    <span class="text-ink-soft/80">{{ $connection['project_name'] }}</span>
                    @foreach($connectedNetworks as $n)
                        <span class="admin-badge bg-teal/10 text-teal">{{ $networks[$n] ?? $n }}</span>
                    @endforeach
                </div>
            @else
                <div class="border-t border-line bg-amber-50/60 px-4 py-3 text-sm text-amber-800">
                    {{ $connectionErrors[0] ?? 'Sin conectar. Guarda la API key y prueba la conexión.' }}
                </div>
            @endif
        </div>

        {{-- Generar plan --}}
        <div class="admin-card overflow-hidden">
            <div class="border-b border-line px-4 py-3">
                <h3 class="font-semibold text-ink">Generar plan con IA</h3>
                <p class="mt-0.5 text-xs text-ink-soft/55">
                    Le dices cuántos días y cuántas publicaciones por día. MIIA redacta cada publicación desde el producto y tú las validas antes de programar.
                </p>
            </div>
            <form method="post" action="{{ route('admin.store.marketing.sellercentral.generate') }}" class="space-y-4 p-4 sm:p-6" data-sc-plan-form>
                @csrf
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Días</label>
                        <input type="number" name="days" value="{{ old('days', 7) }}" min="1" max="{{ config('multidrop.marketing.sellercentral.max_days', 60) }}" class="admin-input" required>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Publicaciones por día</label>
                        <input type="number" name="per_day" value="{{ old('per_day', 2) }}" min="1" max="{{ config('multidrop.marketing.sellercentral.max_per_day', 10) }}" class="admin-input" required>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Inicio</label>
                        <input type="date" name="start_date" value="{{ old('start_date', $defaultStart) }}" class="admin-input">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Formato</label>
                        <select name="format" class="admin-input">
                            <option value="image" @selected(old('format', 'image') === 'image')>Imagen del producto</option>
                            <option value="text" @selected(old('format') === 'text')>Solo texto</option>
                        </select>
                    </div>
                </div>

                <div>
                    <span class="mb-1.5 block text-sm font-medium text-ink-soft">Canales</span>
                    <div class="flex flex-wrap gap-3">
                        @foreach($networks as $key => $label)
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" name="channels[]" value="{{ $key }}" class="h-4 w-4 rounded border-line accent-teal"
                                       @checked(in_array($key, \Illuminate\Support\Arr::wrap(old('channels', $availableNetworks)), true))>
                                {{ $label }}
                                @if(in_array($key, $connectedNetworks, true))
                                    <span class="text-[10px] font-medium text-emerald-700">conectado</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                    @if(! $connection['ok'])
                        <p class="mt-1 text-xs text-amber-700">Sin API key aún puedes generar borradores locales. Para programarlos en Seller Central, guarda la llave del proyecto.</p>
                    @endif
                </div>

                <div>
                    <span class="mb-1.5 block text-sm font-medium text-ink-soft">Productos fuente <span class="text-xs font-normal text-ink-soft/50">(vacío = catálogo de la tienda)</span></span>
                    <select name="products[]" multiple size="6" class="admin-input w-full sm:w-2/3 lg:w-1/2">
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(in_array((string) $p->id, old('products', []), true))>
                                {{ \Illuminate\Support\Str::limit($p->name, 90) }} · {{ $p->status }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <button class="admin-btn" data-sc-generate-btn>Generar publicaciones con MIIA</button>
                    <span class="ml-2 hidden text-xs text-ink-soft/60" data-sc-generate-status></span>
                </div>
            </form>
        </div>

        {{-- Planes (borradores) --}}
        @forelse($plans as $plan)
            <div class="admin-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="admin-badge {{ $badge[$plan->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabel[$plan->status] ?? $plan->status }}</span>
                        <span class="text-sm font-semibold text-ink">Plan #{{ $plan->id }}</span>
                        <span class="text-xs text-ink-soft/55">
                            {{ $plan->days }} día(s) × {{ $plan->per_day }}/día · desde {{ $plan->start_date->format('d/m/Y') }}
                            · {{ $plan->publications->count() }} publicaciones
                        </span>
                        <span class="text-xs text-ink-soft/45">{{ $plan->generated_at?->format('d/m H:i') }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button"
                                class="admin-btn !px-3 !py-1.5 text-xs"
                                data-sc-send-plan
                                data-url="{{ route('admin.store.marketing.sellercentral.plans.send', $plan) }}"
                                data-pending="{{ $plan->pendingCount() }}"
                                @if($plan->pendingCount() === 0 || ! ($connection['ok'] ?? false)) disabled @endif>
                            Programar todo
                        </button>
                        <form method="post" action="{{ route('admin.store.marketing.sellercentral.plans.delete', $plan) }}" onsubmit="return confirm('¿Eliminar este plan y sus publicaciones?')">
                            @csrf
                            <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar plan</button>
                        </form>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-mist/40 text-left text-xs uppercase tracking-wide text-ink-soft/50">
                            <tr>
                                <th class="px-4 py-2">Fecha / Hora</th>
                                <th class="px-4 py-2">Red</th>
                                <th class="px-4 py-2">Producto</th>
                                <th class="px-4 py-2">Estado</th>
                                <th class="px-4 py-2">Contenido</th>
                                <th class="px-4 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach($plan->publications->sortBy('scheduled_at') as $pub)
                                <tr data-sc-pub-row="{{ $pub->id }}">
                                    <td class="whitespace-nowrap px-4 py-2.5 text-ink-soft">
                                        @if($pub->scheduled_at)
                                            <span class="font-medium text-ink">{{ $pub->scheduled_at->format('d/m') }}</span>
                                            <span class="text-ink-soft/60">{{ $pub->scheduled_at->format('H:i') }}</span>
                                        @else
                                            <span class="text-ink-soft/40">—</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <span class="font-medium text-ink">{{ $networks[$pub->network] ?? $pub->network }}</span>
                                        @if(! empty($pub->media_urls))
                                            <span class="ml-1 text-xs text-ink-soft/45">{{ count($pub->media_urls) }} media</span>
                                        @endif
                                    </td>
                                    <td class="max-w-[160px] truncate px-4 py-2.5 text-ink-soft">{{ $pub->product?->name ? \Illuminate\Support\Str::limit($pub->product->name, 50) : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <span class="admin-badge {{ $badge[$pub->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabel[$pub->status] ?? $pub->status }}</span>
                                        @if($pub->error_message)
                                            <p class="mt-1 max-w-[220px] truncate text-xs text-rose-700" title="{{ $pub->error_message }}">{{ $pub->error_message }}</p>
                                        @endif
                                    </td>
                                    <td class="max-w-[320px] px-4 py-2.5">
                                        <p class="line-clamp-2 text-ink-soft" title="{{ $pub->topic }}">{{ $pub->content }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                        <div class="flex flex-wrap justify-end gap-1.5">
                                            <button type="button" class="admin-btn-secondary !px-2 !py-1 text-xs" data-sc-toggle-edit="{{ $pub->id }}">Editar</button>
                                            @if(in_array($pub->status, ['draft', 'error', 'approved'], true))
                                                <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.approve', $pub) }}">
                                                    @csrf
                                                    <button class="admin-btn !px-2 !py-1 text-xs">Programar</button>
                                                </form>
                                            @endif
                                            @if(in_array($pub->status, ['scheduled'], true) && $pub->sellercentral_post_id)
                                                <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.cancel', $pub) }}">
                                                    @csrf
                                                    <button class="admin-btn-secondary !px-2 !py-1 text-xs" onclick="return confirm('¿Cancelar esta publicación?')">Cancelar</button>
                                                </form>
                                            @endif
                                            <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.delete', $pub) }}" onsubmit="return confirm('¿Eliminar esta publicación?')">
                                                @csrf
                                                <button class="admin-btn-danger !px-2 !py-1 text-xs">Borrar</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="hidden" data-sc-edit-row="{{ $pub->id }}">
                                    <td colspan="6" class="bg-mist/20 px-4 py-3">
                                        <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.update', $pub) }}" class="grid gap-3 lg:grid-cols-12">
                                            @csrf
                                            <div class="lg:col-span-2">
                                                <label class="mb-1 block text-xs font-medium text-ink-soft">Programada para</label>
                                                @php
                                                    $dt = $pub->scheduled_at ? $pub->scheduled_at->format('Y-m-d\TH:i') : '';
                                                @endphp
                                                <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $dt) }}" class="admin-input">
                                            </div>
                                            <div class="lg:col-span-2">
                                                <label class="mb-1 block text-xs font-medium text-ink-soft">Red</label>
                                                <select name="network" class="admin-input">
                                                    @foreach($networks as $key => $label)
                                                        <option value="{{ $key }}" @selected($pub->network === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="lg:col-span-3">
                                                <label class="mb-1 block text-xs font-medium text-ink-soft">Tema</label>
                                                <input type="text" name="topic" value="{{ old('topic', $pub->topic) }}" maxlength="255" class="admin-input">
                                            </div>
                                            <div class="lg:col-span-5">
                                                <label class="mb-1 block text-xs font-medium text-ink-soft">Contenido</label>
                                                <textarea name="content" rows="2" class="admin-input" required>{{ old('content', $pub->content) }}</textarea>
                                            </div>
                                            <div class="lg:col-span-2">
                                                <label class="mb-1 block text-xs font-medium text-ink-soft">Formato</label>
                                                <select name="format" class="admin-input">
                                                    @foreach(['text', 'image', 'carousel', 'video'] as $f)
                                                        <option value="{{ $f }}" @selected($pub->format === $f)>{{ $f }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="lg:col-span-10 flex flex-wrap gap-2">
                                                <label class="mb-1 block flex-none text-xs font-medium text-ink-soft">Media:</label>
                                                @forelse($pub->media_urls ?? [] as $m)
                                                    <a href="{{ $m['url'] ?? '#' }}" target="_blank" rel="noopener" class="rounded border border-line px-2 py-0.5 text-xs text-teal hover:underline">{{ basename(parse_url($m['url'] ?? '', PHP_URL_PATH) ?: $m['url'] ?? '') ?: 'media' }}</a>
                                                @empty
                                                    <span class="text-xs text-ink-soft/45">sin media</span>
                                                @endforelse
                                            </div>
                                            <div class="lg:col-span-12 flex gap-2">
                                                <button class="admin-btn !px-3 !py-1.5 text-xs">Guardar</button>
                                                <button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-sc-toggle-edit="{{ $pub->id }}">Cerrar</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="admin-card p-8 text-center">
                <p class="text-sm text-ink-soft/60">Aún no hay planes. Configura la conexión y usa el formulario «Generar plan con IA».</p>
            </div>
        @endforelse

        {{-- Calendario remoto --}}
        @if(($connection['ok'] ?? false) || $calendar !== [])
            <div class="admin-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                    <div>
                        <h3 class="font-semibold text-ink">Calendario (Seller Central)</h3>
                        <p class="mt-0.5 text-xs text-ink-soft/55">Posts del mes actual en Seller Central. Sincroniza el estado local.</p>
                    </div>
                    <form method="post" action="{{ route('admin.store.marketing.sellercentral.sync') }}">
                        @csrf
                        <button class="admin-btn-secondary !px-3 !py-1.5 text-xs">Sincronizar estado</button>
                    </form>
                </div>
                @if($calendar !== [])
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-line text-sm">
                            <thead class="bg-mist/40 text-left text-xs uppercase tracking-wide text-ink-soft/50">
                                <tr>
                                    <th class="px-4 py-2">Fecha</th>
                                    <th class="px-4 py-2">Red</th>
                                    <th class="px-4 py-2">Estado</th>
                                    <th class="px-4 py-2">Contenido</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach(array_slice($calendar, 0, 60) as $post)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-ink-soft">
                                            {{ ! empty($post['scheduled_at']) ? \Illuminate\Support\Str::limit($post['scheduled_at'], 16, '') : '—' }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 font-medium text-ink">{{ $networks[$post['network'] ?? ''] ?? ($post['network'] ?? '—') }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5">
                                            <span class="admin-badge {{ $badge[$post['status'] ?? ''] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabel[$post['status'] ?? ''] ?? ($post['status'] ?? '—') }}</span>
                                        </td>
                                        <td class="max-w-[360px] px-4 py-2.5 text-ink-soft">
                                            <p class="line-clamp-2">{{ $post['content'] ?? '' }}</p>
                                            @if(! empty($post['error_message']))
                                                <p class="mt-0.5 truncate text-xs text-rose-700" title="{{ $post['error_message'] }}">{{ $post['error_message'] }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-4 py-6 text-sm text-ink-soft/55">Sin posts este mes.</p>
                @endif
            </div>
        @endif

        @if($embedUrl !== '')
            <div class="admin-card p-4 text-sm text-ink-soft/70">
                Panel visual de Seller Central disponible en cada campaña, pestaña
                <a href="{{ route('admin.store.marketing.campaigns.index') }}" class="text-teal hover:underline">Publicaciones</a>.
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
(function () {
  function btn(el, on) {
    if (!el) return;
    el.disabled = on;
    el.classList.toggle('opacity-50', on);
  }

  // Toggle fila de edición
  document.querySelectorAll('[data-sc-toggle-edit]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-sc-toggle-edit');
      document.querySelectorAll('[data-sc-edit-row="' + id + '"]').forEach(function (row) {
        row.classList.toggle('hidden');
      });
    });
  });

  // Programar plan completo
  document.querySelectorAll('[data-sc-send-plan]').forEach(function (b) {
    b.addEventListener('click', function () {
      var pending = parseInt(b.getAttribute('data-pending') || '0', 10);
      var msg = pending === 1
        ? 'Programar esta publicación en Seller Central?'
        : 'Programar ' + pending + ' publicaciones en Seller Central?';
      if (!confirm(msg)) return;
      btn(b, true);
      fetch(b.getAttribute('data-url'), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') } })
        .then(function () { window.location.reload(); })
        .catch(function () { btn(b, false); window.AdminToast.error('No se pudo enviar el plan.'); });
    });
  });

  // Probar conexión
  var testBtn = document.querySelector('[data-sc-test]');
  if (testBtn) {
    testBtn.addEventListener('click', function () {
      var form = testBtn.closest('form');
      var status = document.querySelector('[data-sc-test-status]');
      status.classList.remove('hidden');
      status.textContent = 'Probando…';
      status.className = 'inline-block text-xs font-medium text-ink-soft/60';
      btn(testBtn, true);
      var fd = new FormData(form);
      fd.delete('_token');
      fd.delete('embed_url');
      fetch('{{ route('admin.store.marketing.sellercentral.test') }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') },
        body: fd,
      })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        var el = status;
        document.querySelector('[data-sc-test-status]').setAttribute('data-done', '1');
        if (res.ok && res.d.ok) {
          el.textContent = 'Conectado: ' + (res.d.project || 'proyecto') + ' · ' + (res.d.networks || []).join(', ') || 'Conectado';
          el.className = 'inline-block text-xs font-medium text-emerald-700';
          window.AdminToast.success('Conexión OK con Seller Central.');
        } else {
          el.textContent = res.d.error || 'Error al probar.';
          el.className = 'inline-block text-xs font-medium text-rose-700';
          window.AdminToast.error(res.d.error || 'No se pudo conectar.');
        }
        btn(testBtn, false);
      })
      .catch(function () {
        document.querySelector('[data-sc-test-status]').textContent = 'Error de red.';
        document.querySelector('[data-sc-test-status]').className = 'inline-block text-xs font-medium text-rose-700';
        btn(testBtn, false);
      });
    });
  }

  // Generar plan: estado de espera
  var planForm = document.querySelector('[data-sc-plan-form]');
  if (planForm) {
    planForm.addEventListener('submit', function () {
      var gen = planForm.querySelector('[data-sc-generate-btn]');
      var st = planForm.querySelector('[data-sc-generate-status]');
      btn(gen, true);
      st.classList.remove('hidden');
      st.textContent = 'MIIA está redactando las publicaciones… puede tardar un momento.';
    });
  }
})();
</script>
@endpush