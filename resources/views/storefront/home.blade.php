@extends('layouts.store')

@section('title', 'BAZA — Compra online con ofertas del día')

@section('content')
@php
    $cats = $products->map(function ($p) {
        $v = json_decode($p->verified_data ?? '{}', true) ?: [];
        return $v['category'] ?? 'all';
    })->unique()->values();

    $fallbackCopy = [
        ['kicker' => 'Oferta relámpago', 'title' => 'Hasta 40% en esenciales', 'text' => 'Iluminación, energía y hogar. Cupón activo por tiempo limitado.', 'cta_label' => 'Ver ofertas', 'cta_url' => '#shop', 'theme_class' => 's1', 'image_url' => null],
        ['kicker' => 'Energía portátil', 'title' => 'Power banks listos para todo', 'text' => 'Carga rápida y gran capacidad para no quedarte sin batería.', 'cta_label' => 'Explorar energía', 'cta_url' => '#shop', 'theme_class' => 's2', 'image_url' => null],
        ['kicker' => 'Hogar & clima', 'title' => 'Ventilación al instante', 'text' => 'Mini ventiladores USB y portátiles para el día a día.', 'cta_label' => 'Ver hogar', 'cta_url' => '#shop', 'theme_class' => 's3', 'image_url' => null],
        ['kicker' => 'Envío nacional', 'title' => 'Todo México cubierto', 'text' => 'Compra hoy y sigue tu pedido con tracking.', 'cta_label' => 'Empezar', 'cta_url' => '#shop', 'theme_class' => 's4', 'image_url' => null],
        ['kicker' => 'Kit completo', 'title' => 'Arma tu setup en un click', 'text' => 'Combina productos y aprovecha el cupón de bienvenida.', 'cta_label' => 'Ir al catálogo', 'cta_url' => '#shop', 'theme_class' => 's5', 'image_url' => null],
    ];

    if (isset($rouletteSlides) && $rouletteSlides->isNotEmpty()) {
        $promoCopy = $rouletteSlides->map(fn ($s) => [
            'kicker' => $s->kicker,
            'title' => $s->title,
            'text' => $s->text,
            'cta_label' => $s->cta_label ?: 'Ver',
            'cta_url' => $s->cta_url ?: '#shop',
            'theme_class' => $s->theme_class ?: 's1',
            'image_url' => $s->image_url,
        ])->all();
    } else {
        $promoCopy = $fallbackCopy;
    }

    $slides = $products->take(max(count($promoCopy), 1))->values();
@endphp

<div class="wrap roulette-wrap">
    <div class="roulette" id="promo-roulette" aria-roledescription="carrusel" aria-label="Promociones">
        <div class="roulette-track" id="roulette-track">
            @foreach($promoCopy as $i => $promo)
                @php
                    $p = $slides[$i] ?? $slides->first();
                    $img = $promo['image_url'] ?: ($p->image_url ?? null);
                    if ($img && str_starts_with((string) $img, '/media/')) {
                        $img = asset(ltrim($img, '/'));
                    }
                @endphp
                <article class="slide {{ $promo['theme_class'] }}">
                    <div class="slide-copy">
                        <div class="slide-kicker">{{ $promo['kicker'] }}</div>
                        <h2>{{ $promo['title'] }}</h2>
                        <p>{{ $promo['text'] }}</p>
                        <a class="slide-cta" href="{{ $promo['cta_url'] }}">{{ $promo['cta_label'] }}</a>
                    </div>
                    <div class="slide-media">
                        @if($img)
                            <img src="{{ $img }}" alt="{{ $promo['title'] }}" @if($i===0) fetchpriority="high" @else loading="lazy" @endif>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        <div class="roulette-dots" id="roulette-dots">
            @foreach($promoCopy as $i => $promo)
                <button type="button" class="{{ $i === 0 ? 'active' : '' }}" data-i="{{ $i }}" aria-label="Ir a promo {{ $i+1 }}"></button>
            @endforeach
        </div>
        <div class="roulette-nav">
            <button type="button" id="roulette-prev" aria-label="Anterior">‹</button>
            <button type="button" id="roulette-next" aria-label="Siguiente">›</button>
        </div>
    </div>

    <div class="trust">
        <div><strong>Pago seguro</strong> Stripe · PayPal · Mercado Pago</div>
        <div><strong>Envíos MX</strong> Tracking en cada pedido</div>
        <div><strong>Soporte</strong> Atención humana en Lab</div>
        <div><strong>Garantía</strong> Cambios según política</div>
    </div>
</div>

<div class="wrap section" id="shop">
    <div class="deal" id="deal">
        <div>
            <h3>Cupón de bienvenida</h3>
            <p>Ahorra en tu primera compra. Timer real en la barra superior.</p>
        </div>
        <div>
            <div class="deal-row">
                <input type="text" id="coupon-code" value="{{ $coupon->code ?? 'BAZA10' }}">
                <button type="button" class="btn" id="coupon-apply">Aplicar</button>
            </div>
            <div id="coupon-msg"></div>
        </div>
    </div>

    <div class="section-head">
        <div>
            <h2>Productos destacados</h2>
            <p>Selección actualizada desde AliExpress · precios MXN</p>
        </div>
        <div class="icon-btn">{{ $products->count() }} items</div>
    </div>

    <div class="filters" id="filters">
        <button type="button" class="chip active" data-cat="all">Todo</button>
        @foreach($cats as $cat)
            <button type="button" class="chip" data-cat="{{ $cat }}">{{ $cat }}</button>
        @endforeach
    </div>

    <div class="grid" id="product-grid">
        @foreach($products as $product)
            @php
                $v = json_decode($product->verified_data ?? '{}', true) ?: [];
                $cat = $v['category'] ?? 'all';
                $img = $product->image_url;
                if (str_starts_with((string) $img, '/media/')) {
                    $img = asset(ltrim($img, '/'));
                }
            @endphp
            <a class="card" data-cat="{{ $cat }}" href="{{ route('store.product', $product->slug) }}">
                <div class="shot">
                    <img src="{{ $img }}" alt="{{ $product->name }}" loading="lazy" onerror="this.style.opacity=.25">
                    @if($product->badge)
                        <span class="badge">{{ $product->badge }}</span>
                    @endif
                </div>
                <div class="meta">
                    <h3>{{ $product->name }}</h3>
                    <div class="price">
                        ${{ number_format($product->price, 0) }}
                        <span class="text-sm font-medium opacity-70">{{ $product->currency ?? 'MXN' }}</span>
                        @if($product->compare_at_price)
                            <s>${{ number_format($product->compare_at_price, 0) }}</s>
                        @endif
                    </div>
                    <div class="stock {{ ($product->stock ?? 99) <= 25 ? 'low' : '' }}">
                        @if(($product->stock ?? 0) <= 25)
                            Quedan {{ $product->stock }}
                        @else
                            Disponible
                        @endif
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</div>
@endsection

@push('scripts')
<script>
(function(){
  var $track = $('#roulette-track');
  var total = $track.children().length;
  var i = 0;
  var timer = null;

  function go(n){
    i = (n + total) % total;
    $track.css('transform', 'translateX(' + (-i * 100) + '%)');
    $('#roulette-dots button').removeClass('active').eq(i).addClass('active');
  }
  function next(){ go(i + 1); }
  function prev(){ go(i - 1); }
  function play(){ stop(); timer = setInterval(next, 4500); }
  function stop(){ if(timer) clearInterval(timer); timer = null; }

  $('#roulette-next').on('click', function(){ next(); play(); });
  $('#roulette-prev').on('click', function(){ prev(); play(); });
  $('#roulette-dots').on('click', 'button', function(){ go(+$(this).data('i')); play(); });
  $('#promo-roulette').on('mouseenter', stop).on('mouseleave', play);
  play();
})();

$('#coupon-apply').on('click', function () {
  $.post('{{ route('store.coupon') }}', {
    _token: '{{ csrf_token() }}',
    code: $('#coupon-code').val(),
    subtotal: 250
  }).done(function (res) {
    $('#coupon-msg').text(res.message || '').css('color', res.ok ? 'var(--brand)' : 'var(--danger)');
  });
});

$('#filters').on('click', '.chip', function () {
  var cat = $(this).data('cat');
  $('.chip').removeClass('active'); $(this).addClass('active');
  $('#product-grid .card').each(function () {
    $(this).toggle(cat === 'all' || $(this).data('cat') === cat);
  });
});

$('.cats-nav [data-jump]').on('click', function(e){
  e.preventDefault();
  var cat = $(this).data('jump');
  $('.chip[data-cat="'+cat+'"]').trigger('click');
  $('html,body').animate({scrollTop: $('#shop').offset().top - 80}, 300);
});

$('#search-form').on('submit', function(e){
  e.preventDefault();
  var q = ($('#search-q').val() || '').toLowerCase();
  $('#product-grid .card').each(function(){
    var t = $(this).find('h3').text().toLowerCase();
    $(this).toggle(!q || t.indexOf(q) !== -1);
  });
  $('html,body').animate({scrollTop: $('#shop').offset().top - 80}, 300);
});
</script>
@endpush
