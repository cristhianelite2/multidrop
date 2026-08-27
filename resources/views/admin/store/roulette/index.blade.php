@extends('layouts.admin')

@section('title', 'Ruleta — '.$store->name)
@section('heading', 'Ruleta')
@section('subheading', 'Premios con probabilidad + carrusel · '.$store->name)

@section('content')
@php $wheelCfg = $wheelCfg ?? ['prizes' => []]; @endphp
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <a href="{{ route('admin.store.roulette.create') }}" class="admin-btn-secondary">Nuevo slide carrusel</a>
    </div>

    @unless($store->pluginEnabled('roulette'))
        <p class="mb-4 text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
            La ruleta está apagada en el sitio (PC y móvil). Puedes configurarla aquí y activarla en
            <a href="{{ route('admin.store.general.edit') }}" class="font-semibold underline">General → Plugins</a>.
        </p>
    @endunless

    <form method="post" action="{{ route('admin.store.roulette.wheel.update') }}" class="mb-8 space-y-4" id="wheel-form" data-no-fixed-actions>
        @csrf
        @method('PUT')
        <div class="admin-card p-5 sm:p-6 space-y-4">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Ruleta de premios (pantalla completa)</h2>
                <p class="mt-1 text-sm text-ink-soft/70">
                    Overlay llamativo encima del sitio. El <strong>peso</strong> define la probabilidad relativa
                    (ej. peso 30 vs 10 = 3× más chances). Vacío en un premio = se ignora.
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-soft">Título</label>
                    <input name="headline" class="admin-input" value="{{ old('headline', $wheelCfg['headline'] ?? '') }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-soft">Subtítulo</label>
                    <input name="subtitle" class="admin-input" value="{{ old('subtitle', $wheelCfg['subtitle'] ?? '') }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-soft">Duración del giro (ms)</label>
                    <input type="number" min="2500" max="12000" name="spin_ms" class="admin-input" value="{{ old('spin_ms', $wheelCfg['spin_ms'] ?? 4800) }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink-soft">Auto-abrir tras (ms)</label>
                    <input type="number" min="500" max="30000" name="auto_open_delay_ms" class="admin-input" value="{{ old('auto_open_delay_ms', $wheelCfg['auto_open_delay_ms'] ?? 1800) }}">
                </div>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                <input type="checkbox" name="auto_open" value="1" class="rounded border-line text-teal" @checked(old('auto_open', $wheelCfg['auto_open'] ?? true))>
                Abrir automáticamente al entrar a la tienda
            </label>

            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-full text-sm" id="wheel-prizes-table">
                    <thead class="bg-mist/50 text-left text-xs uppercase text-ink-soft/50">
                    <tr>
                        <th class="px-3 py-2">Premio</th>
                        <th class="px-3 py-2">Color</th>
                        <th class="px-3 py-2">Peso</th>
                        <th class="px-3 py-2">Prob. %</th>
                        <th class="px-3 py-2">Cupón (opcional)</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach(($wheelCfg['prizes'] ?? []) as $i => $prize)
                        <tr class="border-t border-line/70" data-prize-row>
                            <td class="px-3 py-2"><input name="prizes[{{ $i }}][label]" value="{{ $prize['label'] }}" class="admin-input" required></td>
                            <td class="px-3 py-2"><input type="color" name="prizes[{{ $i }}][color]" value="{{ $prize['color'] }}" class="h-10 w-14 rounded-lg border border-line bg-white p-1"></td>
                            <td class="px-3 py-2"><input type="number" min="1" max="1000" name="prizes[{{ $i }}][weight]" value="{{ $prize['weight'] }}" class="admin-input w-24 prize-weight"></td>
                            <td class="px-3 py-2 font-mono text-xs prize-chance">{{ $prize['chance'] ?? '—' }}%</td>
                            <td class="px-3 py-2"><input name="prizes[{{ $i }}][code]" value="{{ $prize['code'] ?? '' }}" class="admin-input" placeholder="DEMO10"></td>
                            <td class="px-3 py-2"><button type="button" class="admin-btn-danger !py-1 !px-2 text-xs" data-remove-prize>Quitar</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="admin-btn-secondary" id="add-prize-row">+ Premio</button>
                <button class="admin-btn" type="submit">Guardar ruleta</button>
            </div>
        </div>
    </form>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-display text-base font-bold text-ink">Carrusel / slides (legado)</h2>
    </div>
    <div class="admin-blocks">
        @forelse($slides as $slide)
            <div class="admin-card p-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-ink-soft/50">{{ $slide->kicker ?: 'Slide' }} · {{ $slide->theme_class }} · #{{ $slide->sort_order }}</div>
                    <div class="mt-1 font-semibold text-ink">{{ $slide->title }}</div>
                    <div class="mt-1 text-sm text-ink-soft/65">{{ $slide->text }}</div>
                    <div class="mt-2 flex gap-2">
                        <span class="admin-badge {{ $slide->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $slide->is_active ? 'activo' : 'off' }}</span>
                        @if($slide->cta_label)<span class="admin-badge bg-mist text-ink-soft">{{ $slide->cta_label }}</span>@endif
                    </div>
                </div>
                <div class="flex gap-2">
                    <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.roulette.edit', $slide) }}">Editar</a>
                    <form method="post" action="{{ route('admin.store.roulette.destroy', $slide) }}" onsubmit="return confirm('¿Eliminar slide?')">
                        @csrf @method('DELETE')
                        <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="admin-card p-8 text-center text-ink-soft/60">Sin slides de carrusel (opcional).</div>
        @endforelse
    </div>
@endsection

@push('scripts')
<script>
(function ($) {
  function reindex() {
    $('#wheel-prizes-table tbody tr').each(function (i) {
      $(this).find('[name]').each(function () {
        var n = $(this).attr('name');
        if (!n) return;
        $(this).attr('name', n.replace(/prizes\[\d+]/, 'prizes[' + i + ']'));
      });
    });
    recalc();
  }
  function recalc() {
    var total = 0;
    $('.prize-weight').each(function () { total += Math.max(1, parseInt($(this).val(), 10) || 1); });
    if (!total) total = 1;
    $('#wheel-prizes-table tbody tr').each(function () {
      var w = Math.max(1, parseInt($(this).find('.prize-weight').val(), 10) || 1);
      $(this).find('.prize-chance').text(((w / total) * 100).toFixed(1) + '%');
    });
  }
  $('#add-prize-row').on('click', function () {
    var i = $('#wheel-prizes-table tbody tr').length;
    var colors = ['#14b8a6','#f59e0b','#8b5cf6','#ef4444','#0ea5e9','#22c55e'];
    var c = colors[i % colors.length];
    var row = '<tr class="border-t border-line/70" data-prize-row>' +
      '<td class="px-3 py-2"><input name="prizes['+i+'][label]" class="admin-input" required placeholder="Premio"></td>' +
      '<td class="px-3 py-2"><input type="color" name="prizes['+i+'][color]" value="'+c+'" class="h-10 w-14 rounded-lg border border-line bg-white p-1"></td>' +
      '<td class="px-3 py-2"><input type="number" min="1" max="1000" name="prizes['+i+'][weight]" value="10" class="admin-input w-24 prize-weight"></td>' +
      '<td class="px-3 py-2 font-mono text-xs prize-chance">—</td>' +
      '<td class="px-3 py-2"><input name="prizes['+i+'][code]" class="admin-input" placeholder="Cupón"></td>' +
      '<td class="px-3 py-2"><button type="button" class="admin-btn-danger !py-1 !px-2 text-xs" data-remove-prize>Quitar</button></td>' +
      '</tr>';
    $('#wheel-prizes-table tbody').append(row);
    reindex();
  });
  $(document).on('click', '[data-remove-prize]', function () {
    if ($('#wheel-prizes-table tbody tr').length <= 2) {
      alert('Deja al menos 2 premios.');
      return;
    }
    $(this).closest('tr').remove();
    reindex();
  });
  $(document).on('input', '.prize-weight', recalc);
  recalc();
})(jQuery);
</script>
@endpush
