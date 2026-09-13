@php
    $isCombo = (bool) data_get($product->creative_data, 'is_combo', false);
    $productVideos = $productVideos ?? collect();
    $productPrompts = $productPrompts ?? collect();
    $openUrl = route('admin.store.marketing.campaigns.edit', ['campaign' => $campaign, 'tab' => 'productos', 'product' => $product->id]);
@endphp
<div class="rounded-xl border border-line overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line bg-mist/30 px-4 py-3">
        <a href="{{ $openUrl }}" class="min-w-0 flex items-start gap-3 group">
            @if($product->image_url)
                <img src="{{ $product->image_url }}" alt="" class="h-12 w-12 rounded-lg border border-line object-cover shrink-0">
            @endif
            <div class="min-w-0">
                <div class="font-semibold text-ink group-hover:text-teal">
                    {{ $product->localizedName() }}
                    @if($isCombo)
                        <span class="admin-badge !text-[10px] !px-1.5 !py-0 ml-1 bg-sky-100 text-sky-800">combo</span>
                    @endif
                </div>
                <div class="mt-0.5 text-xs text-ink-soft/55">
                    @if($product->sku){{ $product->sku }} · @endif
                    {{ $productVideos->count() }} {{ $productVideos->count() === 1 ? 'video' : 'videos' }}
                    · {{ $productPrompts->count() }} {{ $productPrompts->count() === 1 ? 'prompt' : 'prompts' }}
                </div>
            </div>
        </a>
        <div class="flex flex-wrap items-center gap-2">
            <a class="admin-btn !px-3 !py-1.5 text-xs" href="{{ $openUrl }}">Abrir</a>
            <form method="post" action="{{ route('admin.store.marketing.campaigns.products.detach', [$campaign, $product]) }}" onsubmit="return confirm('¿Quitar este producto de la campaña? Los videos se conservan.')">
                @csrf @method('DELETE')
                <button class="admin-btn-secondary !px-3 !py-1.5 text-xs">Quitar</button>
            </form>
        </div>
    </div>
    <div class="p-4">
        @if($productVideos->isNotEmpty())
            <div class="flex gap-2 overflow-x-auto pb-1">
                @foreach($productVideos as $v)
                    <a href="{{ $openUrl }}" class="md-product-list-thumb group shrink-0" title="{{ $v->ad_headline ?: ($v->original_name ?: 'Video') }}">
                        <video src="{{ $v->publicUrl() }}" muted playsinline preload="metadata"></video>
                    </a>
                @endforeach
            </div>
        @else
            <a href="{{ $openUrl }}" class="block rounded-lg border border-dashed border-line px-3 py-6 text-center text-sm text-ink-soft/55 hover:border-teal hover:text-teal">
                Sin videos · abrir para generar o subir
            </a>
        @endif
    </div>
</div>
