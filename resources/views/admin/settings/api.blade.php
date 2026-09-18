@extends('layouts.admin')

@section('title', 'API pública')
@section('heading', 'API pública')
@section('subheading', 'Token de acceso, estado y documentación de endpoints REST (api/v1).')

@section('content')
    <div class="mx-auto max-w-5xl space-y-6">

        @if($new_token)
            <div class="rounded-2xl border border-teal/50 bg-teal/5 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-display text-sm font-bold text-teal">Nuevo token generado</h3>
                        <p class="mt-0.5 text-xs text-ink-soft/70">Cópialo ahora; no volverá a mostrarse en el panel.</p>
                    </div>
                    <button type="button" id="api-copy-new-btn" data-copy="{{ $new_token }}" class="admin-btn !px-3 !py-1.5 text-xs">Copiar token</button>
                </div>
                <code class="mt-3 block break-all rounded-xl border border-line bg-white px-3 py-2 text-xs font-mono text-ink" id="api-new-token">{{ $new_token }}</code>
            </div>
        @endif

        {{-- Estado --}}
        <div class="admin-card p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Estado</h2>
                    <p class="mt-1 text-sm text-ink-soft/70">La API resta expone tiendas, productos, campañas y multimedia bajo el prefijo <code class="rounded bg-mist/60 px-1 py-0.5 font-mono text-[0.85em]">/api/v1</code>.</p>
                </div>
                <span class="admin-badge {{ $masked_token ? 'bg-teal/10 text-teal' : 'bg-coral/10 text-coral' }}">
                    {{ $masked_token ? 'API activa' : 'API sin token' }}
                </span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl border border-line bg-mist/25 p-4">
                    <div class="text-xs text-ink-soft/60">URL base</div>
                    <code class="mt-1 block break-all font-mono text-sm text-ink">{{ $base_url }}</code>
                </div>
                <div class="rounded-2xl border border-line bg-mist/25 p-4">
                    <div class="text-xs text-ink-soft/60">Autenticación</div>
                    <div class="mt-1 text-sm text-ink">Bearer token</div>
                    <div class="text-xs text-ink-soft/60">header <code class="font-mono">Authorization: Bearer &lt;token&gt;</code> o <code class="font-mono">X-Multidrop-Token</code></div>
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-mist/25 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="text-xs text-ink-soft/60">Token vigente</div>
                        <code class="mt-1 block break-all font-mono text-sm text-ink">{{ $masked_token ?: '—' }}</code>
                    </div>
                    @if($has_db_token)
                        <span class="admin-badge bg-teal/10 text-teal">Gestionado en el panel</span>
                    @elseif($has_env_token)
                        <span class="admin-badge bg-amber-100 text-amber-800">Desde .env (MULTIDROP_API_TOKEN)</span>
                    @else
                        <span class="admin-badge bg-slate-100 text-slate-700">No configurado</span>
                    @endif
                </div>
                <p class="mt-2 text-xs text-ink-soft/60">
                    @if($token_source === 'env')
                        El token se lee de <code class="font-mono">MULTIDROP_API_TOKEN</code> en <code class="font-mono">.env</code>. Si guardas uno desde este panel, prevalecerá sobre el de <code class="font-mono">.env</code>.
                    @elseif($token_source === 'panel')
                        El token se guarda cifrado en <em>PlatformSettings</em> (clave <code class="font-mono">api.public_token</code>).
                    @else
                        Genera un token o guarda uno propio para activar la API.
                    @endif
                </p>
            </div>
        </div>

        {{-- Gestión de token --}}
        <div class="admin-card p-5 sm:p-6 space-y-4">
            <h2 class="font-display text-lg font-bold text-ink">Token de acceso</h2>

            <div class="flex flex-wrap gap-2">
                <form action="{{ route('admin.settings.api.regenerate') }}" method="post">
                    @csrf
                    <button type="submit" class="admin-btn !py-2 text-sm">Generar nuevo token</button>
                </form>
                <button type="button" class="js-api-test admin-btn-secondary" data-url="{{ $test_url }}">Probar API</button>
                @if($has_db_token)
                    <form action="{{ route('admin.settings.api.disable') }}" method="post">
                        @csrf
                        <button type="submit" class="admin-btn-danger !py-2 text-sm">Desactivar API</button>
                    </form>
                @endif
            </div>

            <form action="{{ route('admin.settings.api.update') }}" method="post" class="rounded-2xl border border-line bg-mist/40 p-4">
                @csrf
                @method('PUT')
                <label class="text-sm font-semibold text-ink" for="api_token">Guardar un token propio</label>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <input type="text" id="api_token" name="api_token" minlength="16" maxlength="255" autocomplete="off"
                           placeholder="Token personalizado (mín. 16 caracteres)"
                           class="admin-input min-w-0 flex-1 !py-2 text-xs" autofocus>
                    <button type="submit" class="admin-btn !py-2 text-xs">Guardar token</button>
                </div>
                <p class="mt-2 text-xs text-ink-soft/60">Los tokens de 16+ caracteres de largo y de alta entropía son los más seguros para usarse como Bearer.</p>
            </form>

            <details class="rounded-2xl border border-line bg-white/60 px-3 py-2 text-sm">
                <summary class="cursor-pointer select-none font-medium text-ink">¿Cómo llamar a la API?</summary>
                <div class="mt-2 space-y-2 text-xs leading-relaxed text-ink-soft/80">
                    <p><code class="rounded bg-mist/60 px-1 py-0.5 font-mono">GET {{ $base_url }}/stores?q=ejemplo</code> — listar tiendas (filtros <code class="font-mono">q</code>, <code class="font-mono">status</code>, <code class="font-mono">per_page</code>).</p>
                    <p><code class="rounded bg-mist/60 px-1 py-0.5 font-mono">GET {{ $base_url }}/stores/{store}/products?q=cargador</code> — buscar productos por tienda.</p>
                    <p><code class="rounded bg-mist/60 px-1 py-0.5 font-mono">POST {{ $base_url }}/campaigns/{campaign}/products/{product}/media</code> — subir multimedia (multipart <code class="font-mono">file</code> o <code class="font-mono">url</code>).</p>
                </div>
            </details>
        </div>

        {{-- Export GetMan / Postman --}}
        <div class="admin-card p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Exportar para GetMan / Postman</h2>
                    <p class="mt-1 text-sm text-ink-soft/70">
                        Descarga la colección en formato <strong>Postman Collection v2.1</strong> para importarla en
                        <a href="{{ $getman_help_url }}" target="_blank" rel="noopener" class="text-teal hover:underline">GetMan</a>
                        u otras plataformas compatibles.
                    </p>
                </div>
            </div>

            <ol class="list-decimal space-y-1 pl-5 text-sm text-ink-soft/80">
                <li>Descarga la colección (y opcionalmente el entorno).</li>
                <li>En GetMan: workspace → <em>Importar colección</em> → sube el JSON.</li>
                <li>Pega tu Bearer token en la variable <code class="rounded bg-mist/60 px-1 py-0.5 font-mono text-[0.85em]">api_token</code>.</li>
            </ol>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.settings.api.export.collection') }}" class="admin-btn !py-2 text-sm">Descargar colección JSON</a>
                <a href="{{ route('admin.settings.api.export.environment') }}" class="admin-btn-secondary !py-2 text-sm">Descargar entorno</a>
                @if($masked_token)
                    <a href="{{ route('admin.settings.api.export.collection', ['include_token' => 1]) }}" class="admin-btn-secondary !py-2 text-sm" title="Incluye el token vigente en el archivo">Colección + token</a>
                    <a href="{{ route('admin.settings.api.export.environment', ['include_token' => 1]) }}" class="admin-btn-secondary !py-2 text-sm" title="Incluye el token vigente en el entorno">Entorno + token</a>
                @endif
            </div>

            <p class="text-xs text-ink-soft/55">
                Sin token: el JSON trae <code class="font-mono">@{{api_token}}</code> vacío (recomendado para compartir).
                Con token: útil para uso local; no compartas ese archivo.
                Guía de importación:
                <a href="{{ $getman_help_url }}" target="_blank" rel="noopener" class="text-teal hover:underline">mock.ceballosleon.com/help/import</a>
            </p>
        </div>

        {{-- Endpoints --}}
        <div class="admin-card p-5 sm:p-6 space-y-4">
            <h2 class="font-display text-lg font-bold text-ink">Endpoints</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                            <th class="py-2.5 pr-3 font-semibold">Método</th>
                            <th class="py-2.5 pr-3 font-semibold">Ruta</th>
                            <th class="py-2.5 pr-3 font-semibold">Descripción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($endpoints as $ep)
                            <tr class="border-b border-line/70 last:border-0">
                                <td class="whitespace-nowrap py-2.5 pr-3">
                                    <span class="admin-badge {{ $ep['color'] }}">{{ $ep['method'] }}</span>
                                </td>
                                <td class="whitespace-nowrap py-2.5 pr-3 font-mono text-xs text-ink/80">{{ $ep['path'] }}</td>
                                <td class="py-2.5 pr-3 text-ink-soft/80">{{ $ep['desc'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    function toast(ok, msg) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.className = 'fixed bottom-4 right-4 z-50 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg ' + (ok ? 'bg-teal' : 'bg-coral');
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 5000);
    }

    function statusEl($btn) {
        var $s = $btn.siblings('.js-api-test-status');
        if (!$s.length) {
            $s = $('<span class="js-api-test-status text-xs"></span>');
            $btn.after($s);
        }
        return $s;
    }

    $(document).on('click', '.js-api-test', function () {
        var $btn = $(this);
        var url = String($btn.data('url') || '');
        if (!url || $btn.prop('disabled')) return;

        var label = $btn.text();
        var $st = statusEl($btn);
        $btn.prop('disabled', true).text('Probando…');
        $st.removeClass('text-teal text-coral hidden').addClass('text-ink-soft/60').text('Consultando…');

        $.ajax({
            url: url,
            method: 'POST',
            data: { _token: csrf },
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (res) {
            var ok = !!(res && (res.ok || res.success));
            var msg = (res && res.message) || (ok ? 'API OK' : 'La prueba falló');
            $st.toggleClass('text-teal', ok).toggleClass('text-coral', !ok).removeClass('text-ink-soft/60 hidden').text(msg);
            toast(ok, msg);
        }).fail(function (xhr) {
            $st.removeClass('hidden text-teal text-ink-soft/60').addClass('text-coral').text('Error de conexión (' + xhr.status + ')');
            toast(false, 'Error de conexión (' + xhr.status + ')');
        }).always(function () {
            $btn.prop('disabled', false).text(label);
        });
    });

    var copyBtn = document.getElementById('api-copy-new-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var val = copyBtn.getAttribute('data-copy') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(val).then(function () {
                    copyBtn.textContent = '¡Copiado!';
                    setTimeout(function () { copyBtn.textContent = 'Copiar token'; }, 2000);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = val;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
                copyBtn.textContent = '¡Copiado!';
                setTimeout(function () { copyBtn.textContent = 'Copiar token'; }, 2000);
            }
        });
    }
})();
</script>
@endpush