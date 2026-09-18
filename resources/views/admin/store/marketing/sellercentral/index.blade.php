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
})();
</script>
@endpush
