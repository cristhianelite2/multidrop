@extends('layouts.store')

@section('title', $product->name.' — BAZA')
@section('meta', \Illuminate\Support\Str::limit($product->description, 140))

@section('content')
@php
    $img = $product->image_url;
    if (str_starts_with((string) $img, '/media/')) {
        $img = asset(ltrim($img, '/'));
    }
@endphp
<div class="wrap">
<article class="product-page">
    <div class="gallery">
        <img src="{{ $img }}" alt="{{ $product->name }}">
    </div>
    <div>
        @if($product->badge)
            <span class="badge" style="position:static;display:inline-block;">{{ $product->badge }}</span>
        @endif
        <h1>{{ $product->name }}</h1>
        <div class="price" style="font-size:1.6rem;">
            ${{ number_format($product->price, 0) }} {{ $product->currency ?? 'MXN' }}
            @if($product->compare_at_price)
                <s>${{ number_format($product->compare_at_price, 0) }}</s>
            @endif
        </div>
        <p class="stock {{ ($product->stock ?? 99) <= 25 ? 'low' : '' }}" style="margin:8px 0 14px;">
            @if(($product->stock ?? 0) <= 25)
                Quedan {{ $product->stock }}
            @else
                Disponible · envío a México
            @endif
        </p>
        <p style="color:var(--muted);line-height:1.6;white-space:pre-line;">{{ $product->description }}</p>
        @php
            $sfReviews = is_array($verified['reviews'] ?? null) ? $verified['reviews'] : [];
            $sfRating = $verified['rating_avg'] ?? null;
            $sfReviewCount = (int) ($verified['review_count'] ?? count($sfReviews));
        @endphp
        @if($sfRating !== null || $sfReviews !== [])
            <div style="margin-top:14px;padding:12px 14px;border:1px solid var(--line,#e2e8f0);border-radius:12px;background:#fafafa;">
                <div style="font-weight:700;margin-bottom:4px;">
                    @if($sfRating !== null)
                        ★ {{ number_format((float) $sfRating, 1) }} / 5
                    @endif
                    @if($sfReviewCount > 0)
                        <span style="color:var(--muted);font-weight:500;font-size:13px;"> · {{ $sfReviewCount }} reseñas</span>
                    @endif
                </div>
            </div>
        @endif
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
            <button type="button" class="btn" id="buy-btn">{{ $creative['cta'] ?? 'Agregar al carrito' }}</button>
            <a class="icon-btn" href="{{ route('store.home') }}">Seguir comprando</a>
        </div>
        <p id="buy-msg" style="color:var(--muted);font-size:13px;margin-top:10px;"></p>

        @if($upsell)
            <div class="upsell">
                <strong>También te puede interesar:</strong>
                <a href="{{ route('store.product', $upsell->slug) }}">{{ $upsell->name }}</a>
                · {{ (float) $upsell->discount_percent }}% off
            </div>
        @endif

        @if(!empty($verified['aliexpress_url']))
            <p style="margin-top:18px;font-size:12px;color:var(--muted);">
                Referencia:
                <a href="{{ $verified['aliexpress_url'] }}" target="_blank" rel="noopener">AliExpress #{{ $verified['aliexpress_product_id'] ?? '' }}</a>
            </p>
        @endif
    </div>
</article>

@php
    $sfReviewsList = array_values(array_filter(
        is_array($verified['reviews'] ?? null) ? $verified['reviews'] : [],
        fn ($r) => is_array($r)
    ));
@endphp
@if($sfReviewsList !== [])
    <section class="section-head" style="margin-top:8px;">
        <h2>Opiniones de compradores</h2>
    </section>
    <div style="display:grid;gap:12px;padding-bottom:28px;">
        @foreach(array_slice($sfReviewsList, 0, 12) as $rev)
            <article style="border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:14px 16px;background:#fff;">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:13px;margin-bottom:6px;">
                    <strong>{{ $rev['author'] ?? 'Comprador' }}</strong>
                    @if(!empty($rev['country']))
                        <span style="color:var(--muted);">{{ $rev['country'] }}</span>
                    @endif
                    @php $st = (int) ($rev['score'] ?? 0); @endphp
                    <span style="color:#d97706;">{{ str_repeat('★', max(0, min(5, $st))).str_repeat('☆', max(0, 5 - min(5, $st))) }}</span>
                </div>
                @if(!empty($rev['comment']))
                    <p style="color:var(--muted);line-height:1.55;white-space:pre-wrap;margin:0;">{{ $rev['comment'] }}</p>
                @endif
                @if(!empty($rev['images']) && is_array($rev['images']))
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                        @foreach(array_slice($rev['images'], 0, 4) as $rimg)
                            <img src="{{ $rimg }}" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;" loading="lazy">
                        @endforeach
                    </div>
                @endif
            </article>
        @endforeach
    </div>
@endif

@if($related->isNotEmpty())
    <div class="section-head"><h2>Más en BAZA</h2></div>
    <div class="grid" style="padding-bottom:40px;">
        @foreach($related as $r)
            @php
                $rimg = $r->image_url;
                if (str_starts_with((string) $rimg, '/media/')) {
                    $rimg = asset(ltrim($rimg, '/'));
                }
            @endphp
            <a class="card" href="{{ route('store.product', $r->slug) }}">
                <div class="shot"><img src="{{ $rimg }}" alt="{{ $r->name }}"></div>
                <div class="meta">
                    <h3>{{ $r->name }}</h3>
                    <div class="price">${{ number_format($r->price, 0) }}</div>
                </div>
            </a>
        @endforeach
    </div>
@endif
</div>
@endsection

@push('scripts')
<script>
$('#buy-btn').on('click', function () {
  $('#buy-msg').text('Agregado (simulado). Próximo: checkout Mercado Pago.');
});
</script>
@endpush
