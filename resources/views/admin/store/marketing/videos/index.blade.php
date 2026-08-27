@extends('layouts.admin')

@section('title', 'Videos — '.$store->name)
@section('heading', 'Videos de publicidad')
@section('subheading', 'Upload o Creatify · metadata limpia · '.$store->name)

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'videos'])

    @unless($ffmpeg)
        <p class="mb-4 text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
            ffmpeg no está en el PATH. Los videos se guardan, pero no se puede borrar la metadata de software/IA. Instala ffmpeg o define <code>FFMPEG_PATH</code> en <code>.env</code>.
        </p>
    @endunless

    <div class="admin-card p-5 sm:p-6 space-y-4 max-w-2xl mb-6">
        <h3 class="font-semibold text-ink">Subir video</h3>
        <form method="post" action="{{ route('admin.store.marketing.videos.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Campaña</label>
                <input type="hidden" name="from" value="library">
                <select name="campaign_id" class="admin-input" required>
                    <option value="">—</option>
                    @foreach($campaigns as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Prompt (opcional)</label>
                <select name="prompt_id" class="admin-input">
                    <option value="">—</option>
                    @foreach($prompts as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Archivo (mp4 / webm / mov, máx {{ $maxMb }} MB)</label>
                <input type="file" name="file" accept="video/mp4,video/webm,video/quicktime" class="admin-input" required>
            </div>
            <p class="text-xs text-ink-soft/55">Al guardar, ffmpeg borra título, encoder, software y comentarios (Creatify, Lavf, “AI generated”, etc.). El archivo queda como un MP4 normal. La descarga se llama <code>clip.mp4</code>.</p>
            <button class="admin-btn" @disabled($campaigns->isEmpty())>Subir y limpiar</button>
        </form>
    </div>

    <div class="admin-card p-5 sm:p-6 space-y-4 max-w-2xl mb-6" id="md-creatify-box">
        <h3 class="font-semibold text-ink">Generar con Creatify</h3>
        <p class="text-sm text-ink-soft/70">Usa la URL de landing de la campaña + el prompt. El MP4 entra al mismo pipeline de limpieza.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Campaña</label>
                <select id="md-cf-campaign" class="admin-input">
                    <option value="">—</option>
                    @foreach($campaigns as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Prompt</label>
                <select id="md-cf-prompt" class="admin-input">
                    <option value="">—</option>
                    @foreach($prompts as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <button type="button" class="admin-btn-secondary" id="md-cf-go">Generar video</button>
        <p class="text-sm text-ink-soft/70" id="md-cf-msg"></p>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Video</th>
                    <th class="px-4 py-3 font-semibold">Campaña</th>
                    <th class="px-4 py-3 font-semibold">Origen</th>
                    <th class="px-4 py-3 font-semibold">Metadata</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($videos as $v)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-ink truncate max-w-[14rem]">{{ $v->original_name ?: basename($v->path) }}</div>
                            <video src="{{ $v->publicUrl() }}" controls preload="metadata" class="mt-2 h-20 rounded-lg border border-line bg-black"></video>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if($v->campaign)
                                <a class="hover:text-teal" href="{{ route('admin.store.marketing.campaigns.edit', ['campaign' => $v->campaign, 'tab' => 'ads']) }}">{{ $v->campaign->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $v->source === 'creatify' ? 'generado' : 'subido' }}</td>
                        <td class="px-4 py-3">
                            <span class="admin-badge {{ $v->stripped_at ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                {{ $v->stripped_at ? 'sin huellas' : 'sin limpiar' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.videos.download', $v) }}">Descargar</a>
                                <form method="post" action="{{ route('admin.store.marketing.videos.destroy', $v) }}" onsubmit="return confirm('¿Eliminar este video?')">
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="from" value="library">
                                    <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-ink-soft/60">Aún no hay videos.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
  var go = document.getElementById('md-cf-go');
  var msg = document.getElementById('md-cf-msg');
  if (!go) return;
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var token = csrf ? csrf.getAttribute('content') : '';
  function say(t) { if (msg) msg.textContent = t; }
  function poll(jobId, campaignId, promptId) {
    fetch(@json(route('admin.store.marketing.creatify.poll')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ job_id: jobId, campaign_id: campaignId, prompt_id: promptId })
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { say(res.message || 'Error'); go.disabled = false; return; }
      if (res.status === 'done') {
        say('Video listo' + (res.stripped ? ' (metadata limpia)' : '') + '. Recargando…');
        window.location.reload();
        return;
      }
      say('Generando… ' + (res.progress || 0) + '% (' + (res.status || 'pending') + ')');
      setTimeout(function () { poll(jobId, campaignId, promptId); }, 4000);
    }).catch(function () { say('Error de red al consultar el job'); go.disabled = false; });
  }
  go.addEventListener('click', function () {
    var campaignId = document.getElementById('md-cf-campaign').value;
    var promptId = document.getElementById('md-cf-prompt').value;
    if (!campaignId || !promptId) { say('Elige campaña y prompt.'); return; }
    go.disabled = true;
    say('Enviando a Creatify…');
    fetch(@json(route('admin.store.marketing.creatify.generate')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ campaign_id: campaignId, prompt_id: promptId })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (pack) {
      if (!pack.j.ok) { say(pack.j.message || 'No se pudo generar'); go.disabled = false; return; }
      say('Job ' + pack.j.job_id + '…');
      poll(pack.j.job_id, campaignId, promptId);
    }).catch(function () { say('Error de red'); go.disabled = false; });
  });
})();
</script>
@endpush
