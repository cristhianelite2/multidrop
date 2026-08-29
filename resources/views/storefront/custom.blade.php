<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $htmlLang ?? 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $seo['title'] ?? (($page['title'] ?? null) ? ($page['title'].' — ') : '').($store->name ?? 'Tienda') }}</title>
    @if(!empty($seo['description']))
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if(!empty($seo['canonical']))
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    <meta property="og:title" content="{{ $seo['title'] ?? $store->name }}">
    @if(!empty($seo['description']))
        <meta property="og:description" content="{{ $seo['description'] }}">
    @endif
    @if(!empty($seo['og_image']))
        <meta property="og:image" content="{{ $seo['og_image'] }}">
    @endif
    <meta property="og:type" content="{{ (($page['type'] ?? '') === 'product') ? 'product' : 'website' }}">
    @php
        $gaId = $pixels['ga'] ?? null;
        $metaPixel = $pixels['meta'] ?? null;
        $deferPixels = !empty($deferPixels);
    @endphp
    @if($gaId && ! $deferPixels)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());
          gtag('config', @json($gaId));
        </script>
    @endif
    @if($metaPixel && ! $deferPixels)
        <script>
          !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
          n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
          n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
          t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window, document,'script',
          'https://connect.facebook.net/en_US/fbevents.js');
          fbq('init', @json($metaPixel));
          fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ $metaPixel }}&ev=PageView&noscript=1" alt=""></noscript>
    @endif
    <style>
        :root {
            --md-checkout-primary: {{ $checkout['primary'] ?? '#0f766e' }};
            --md-checkout-accent: {{ $checkout['accent'] ?? '#f59e0b' }};
            --md-checkout-button: {{ $checkout['button'] ?? '#0f766e' }};
            --md-checkout-bg: {{ $checkout['bg'] ?? '#ffffff' }};
            --md-checkout-text: {{ $checkout['text'] ?? '#0f172a' }};
        }
        .md-hide { display: none !important; }
        .md-cart-summary__row--discount .md-price,
        [data-md-cart-combo-discount],
        [data-md-cart-discount],
        [data-md-cart-magic-discount] {
            color: var(--md-checkout-primary, #0f766e);
            font-weight: 700;
        }
        @keyframes md-cart-pulse {
            0% { transform: scale(1); }
            40% { transform: scale(1.35); }
            100% { transform: scale(1); }
        }
        [data-md-cart-count].md-cart-bump {
            animation: md-cart-pulse .45s ease;
            display: inline-block;
        }
        .md-locale-currency {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .md-locale-currency[hidden],
        .md-locale-currency.is-empty { display: none !important; }
        .md-locale-currency__item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            min-width: 0;
        }
        .md-locale-currency__flag {
            flex: none;
            width: 1.35rem;
            height: 1.05rem;
            border-radius: 2px;
            box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.12);
            overflow: hidden;
            background-size: cover;
            user-select: none;
        }
        .md-locale-currency__flag.fi {
            font-size: 0.78rem;
            line-height: 1;
        }
        select[data-md-locale-select],
        select[data-md-currency-select] {
            max-width: 8.5rem;
            font-size: 12px;
            line-height: 1.2;
            padding: 0.4rem 0.5rem;
            border-radius: 6px;
            border: 1px solid rgba(15, 23, 42, 0.18);
            background: #fff;
            color: inherit;
            cursor: pointer;
        }
        .md-atc-modal {
            position: fixed;
            inset: 0;
            z-index: 2147483000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }
        .md-atc-modal[hidden],
        .md-atc-modal.md-hide { display: none !important; }
        .md-atc-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(2px);
        }
        .md-atc-modal__card {
            position: relative;
            z-index: 1;
            width: min(420px, 100%);
            background: #ffffff;
            color: #0f172a;
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
            padding: 22px 20px 18px;
            animation: md-atc-in .22s ease;
        }
        @keyframes md-atc-in {
            from { opacity: 0; transform: translateY(10px) scale(.98); }
            to { opacity: 1; transform: none; }
        }
        .md-atc-modal__close {
            position: absolute;
            top: 10px;
            right: 12px;
            border: 0;
            background: transparent;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            color: #64748b;
            padding: 4px 8px;
        }
        .md-atc-modal__mark {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: color-mix(in srgb, var(--md-checkout-primary, #0f766e) 18%, #fff);
            color: var(--md-checkout-primary, #0f766e);
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 12px;
        }
        .md-atc-modal__title {
            margin: 0 0 6px;
            font-size: 1.2rem;
            font-weight: 800;
            line-height: 1.25;
        }
        .md-atc-modal__sub {
            margin: 0 0 16px;
            font-size: 14px;
            color: #64748b;
            line-height: 1.4;
        }
        .md-atc-modal__product {
            display: grid !important;
            grid-template-columns: 64px minmax(0, 1fr) auto !important;
            gap: 12px;
            align-items: center;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            margin-bottom: 16px;
            background: #f8fafc;
        }
        .md-atc-modal__media {
            width: 64px;
            height: 64px;
            border-radius: 10px;
            overflow: hidden;
            background: #e2e8f0;
            flex: none;
            position: relative;
        }
        .md-atc-modal__product img,
        .md-atc-modal__ph,
        #md-atc-img {
            width: 64px !important;
            height: 64px !important;
            max-width: none !important;
            max-height: none !important;
            border-radius: 10px;
            object-fit: cover !important;
            background: #e2e8f0;
            display: block !important;
            position: static !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        .md-atc-modal__ph[hidden],
        #md-atc-img[hidden] {
            display: none !important;
        }
        .md-atc-modal__name {
            font-weight: 700;
            font-size: 14px;
            line-height: 1.3;
        }
        .md-atc-modal__meta {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .md-atc-modal__price {
            font-weight: 800;
            font-size: 14px;
            white-space: nowrap;
        }
        .md-atc-modal__actions {
            display: grid;
            gap: 8px;
        }
        .md-atc-modal__actions .md-btn {
            display: block;
            width: 100%;
            text-align: center;
            box-sizing: border-box;
            border-radius: 12px;
            padding: 12px 16px;
            font-weight: 800;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            border: 0;
            font-family: inherit;
        }
        .md-atc-modal__actions .md-btn--primary {
            background: var(--md-checkout-button, var(--md-checkout-primary, #0f766e));
            color: #fff;
        }
        .md-atc-modal__actions .md-btn--ghost {
            background: color-mix(in srgb, var(--md-checkout-text, #0f172a) 10%, var(--md-checkout-bg, #fff));
            color: var(--md-checkout-text, #0f172a);
            border: 1px solid color-mix(in srgb, var(--md-checkout-text, #0f172a) 28%, transparent);
        }
        /* Qty en carrito / checkout / PDP (contraste en temas oscuros) */
        .md-qty,
        .md-cart-line .md-qty,
        [data-md-cart] .md-qty,
        [data-md-checkout-lines] .md-qty,
        .md-checkout-summary .md-qty {
            display: inline-flex;
            align-items: stretch;
            gap: 0;
            width: fit-content;
            max-width: 100%;
            flex: none;
            flex-wrap: nowrap;
            margin-top: .55rem;
            border: 1px solid var(--md-line, var(--md-border-strong, #cbd5e1));
            border-radius: 10px;
            overflow: hidden;
            background: var(--md-sand, #fff);
            color: var(--md-checkout-text, #0f172a);
        }
        .md-pdp__row .md-qty { margin-top: 0; border-radius: 999px; }
        .md-checkout-summary .md-qty,
        .md-checkout-summary__qty {
            margin-top: .35rem;
            width: fit-content;
            max-width: 100%;
            flex: none;
            flex-wrap: nowrap;
            align-self: flex-start;
            height: 32px;
            border-radius: 10px;
        }
        .md-qty button,
        .md-cart-line .md-qty button,
        [data-md-cart] .md-qty button,
        [data-md-checkout-lines] .md-qty button,
        .md-checkout-summary .md-qty button {
            width: 32px;
            min-width: 32px;
            height: 32px;
            flex: 0 0 32px;
            border: 0;
            border-radius: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font: inherit;
            line-height: 1;
            padding: 0;
        }
        .md-qty input,
        .md-cart-line .md-qty input,
        [data-md-cart] .md-qty input,
        [data-md-checkout-lines] .md-qty input,
        .md-checkout-summary .md-qty input {
            width: 40px;
            min-width: 40px;
            flex: 0 0 40px;
            height: 32px;
            text-align: center;
            border: 0;
            border-left: 1px solid var(--md-line, var(--md-border-strong, #cbd5e1));
            border-right: 1px solid var(--md-line, var(--md-border-strong, #cbd5e1));
            border-radius: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            padding: 0 2px;
            -moz-appearance: textfield;
        }
        .md-qty input::-webkit-outer-spin-button,
        .md-qty input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .md-cart-line__link,
        .md-cart-row__link {
            color: inherit;
            text-decoration: none;
        }
        .md-cart-line__link:hover,
        .md-cart-row__link:hover {
            color: var(--md-amber, var(--md-checkout-primary, #0f766e));
            text-decoration: underline;
        }
        a.md-cart-line__media,
        a.md-cart-row__media {
            display: block;
            flex: 0 0 auto;
            color: inherit;
            text-decoration: none;
        }
        a.md-cart-line__media img,
        a.md-cart-row__media img,
        .md-cart-line__media img {
            display: block;
            width: 76px;
            height: 76px;
            object-fit: cover;
        }
        /* Ruleta: etiqueta en la bisectriz (mitad de la rebanada, hacia el hub) */
        .md-mod-roulette-seg-label {
            position: absolute;
            width: max-content;
            max-width: 36%;
            margin: 0;
            padding: 0 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            box-sizing: border-box;
            transform-origin: center center;
            z-index: 1;
        }
        .md-mod-roulette-seg-label span {
            display: block;
            width: 100%;
            text-align: center;
            font-size: clamp(.55rem, calc(2.6vw - .08rem * var(--md-roulette-n, 6)), .82rem);
            font-weight: 800;
            line-height: 1.12;
            color: #fff;
            text-shadow: 0 1px 3px rgba(0,0,0,.55);
            overflow: hidden;
            word-break: break-word;
        }
        .md-mod-social .md-sp-place {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .md-mod-social .md-sp-flag {
            width: 18px;
            height: 13px;
            object-fit: cover;
            border-radius: 2px;
            box-shadow: 0 0 0 1px rgba(15, 23, 42, .12);
            flex-shrink: 0;
            vertical-align: middle;
        }
        .md-mod-social .md-sp-thumb {
            position: relative;
            flex: 0 0 auto;
            width: 48px;
            height: 48px;
            border-radius: 10px;
            overflow: hidden;
            background: rgba(15, 23, 42, .06);
            text-decoration: none;
            display: block;
        }
        .md-mod-social .md-sp-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .md-mod-social .md-sp-product {
            font-weight: 600;
            color: var(--md-mod-primary, #0f766e);
            text-decoration: none;
        }
        .md-mod-social .md-sp-product:hover { text-decoration: underline; }
        .md-mod-social .md-sp-body { flex: 1; min-width: 0; }
        .md-mod-social .md-sp-product {
            display: inline;
            max-width: 100%;
        }
        .md-mod-roulette-fab-wrap {
            position: fixed;
            left: 16px;
            bottom: 16px;
            z-index: 99991;
            max-width: min(240px, calc(100vw - 32px));
            transition: bottom .25s ease;
        }
        .md-mod-roulette-won {
            background: linear-gradient(145deg, var(--md-checkout-primary, #0f766e), var(--md-checkout-accent, #f59e0b));
            color: #fff;
            border-radius: 12px;
            padding: 7px 9px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .28);
            font: 600 .7rem/1.25 inherit;
        }
        .md-mod-roulette-won-kicker {
            opacity: .8;
            font-size: .58rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            margin-bottom: 0;
            line-height: 1.2;
        }
        .md-mod-roulette-won strong {
            display: block;
            font-size: .8rem;
            font-weight: 800;
            margin-bottom: 4px;
            line-height: 1.15;
        }
        .md-mod-roulette-won-code-row {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 4px;
            flex-wrap: nowrap;
        }
        .md-mod-roulette-won-code-row code {
            flex: 1;
            min-width: 0;
            background: rgba(0,0,0,.22);
            border: 1px dashed rgba(255,255,255,.4);
            border-radius: 7px;
            padding: 4px 7px;
            font: 800 .72rem/1.1 ui-monospace, monospace;
            letter-spacing: .03em;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #fff;
        }
        .md-mod-roulette-copy {
            border: 0;
            cursor: pointer;
            border-radius: 999px;
            padding: 4px 9px;
            font: 800 .65rem/1 inherit;
            color: #0f172a;
            background: #fff;
            white-space: nowrap;
        }
        .md-mod-roulette-copy.is-copied {
            background: #bbf7d0;
            color: #14532d;
        }
        .md-mod-roulette-won-timer {
            opacity: .9;
            font-size: .62rem;
            line-height: 1.2;
        }
        .md-mod-roulette-won-timer b {
            font-variant-numeric: tabular-nums;
        }
        .md-mod-roulette-miss {
            background: linear-gradient(145deg, #475569, #334155);
            color: #fff;
            border-radius: 12px;
            padding: 7px 9px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .32);
            font: 600 .7rem/1.25 inherit;
            text-align: center;
        }
        .md-mod-roulette-miss-face {
            font-size: 1.15rem;
            line-height: 1;
            margin-bottom: 2px;
        }
        .md-mod-roulette-miss strong {
            display: block;
            font-size: .78rem;
            font-weight: 800;
            margin-bottom: 6px;
            line-height: 1.2;
        }
        .md-mod-roulette-retry {
            border: 0;
            cursor: pointer;
            border-radius: 999px;
            padding: 5px 10px;
            font: 800 .65rem/1 inherit;
            color: #0f172a;
            background: #fff;
        }
        .md-checkout-summary__coupon {
            margin: 14px 0 8px;
            padding: 12px;
            border: 1px dashed color-mix(in srgb, var(--md-checkout-primary, #0f766e) 35%, #cbd5e1);
            border-radius: 12px;
            background: color-mix(in srgb, var(--md-checkout-accent, #f59e0b) 10%, transparent);
        }
        .md-checkout-summary__lines,
        [data-md-checkout-lines],
        [data-md-checkout-items] {
            display: grid;
            gap: 12px;
            margin: 0 0 14px;
        }
        .md-checkout-summary__line {
            display: grid;
            grid-template-columns: 56px 1fr auto;
            gap: 10px;
            align-items: start;
        }
        .md-checkout-summary__line img,
        .md-checkout-summary__line-ph {
            width: 56px;
            height: 56px;
            border-radius: 10px;
            object-fit: cover;
            background: #f1f5f9;
            display: block;
        }
        .md-checkout-summary__line-main {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
            align-items: flex-start;
            position: relative;
            z-index: 1;
        }
        .md-checkout-summary__line-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
        }
        .md-checkout-summary__remove,
        .md-checkout-summary__line .md-cart-row__remove {
            border: 0;
            background: transparent;
            color: #b91c1c;
            font-size: 12px;
            font-weight: 600;
            padding: 2px 0;
            cursor: pointer;
            text-decoration: underline;
            line-height: 1.2;
        }
        .md-checkout-summary__remove:hover,
        .md-checkout-summary__line .md-cart-row__remove:hover {
            color: #991b1b;
        }
        .md-checkout-summary__line > .md-price {
            position: relative;
            z-index: 0;
        }
        .md-checkout-summary__line-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--md-checkout-text, #0f172a);
            line-height: 1.3;
        }
        .md-checkout-summary__line-qty {
            font-size: 12px;
            color: #64748b;
        }
        .md-checkout-summary__row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 6px 0;
            font-size: 13px;
            color: var(--md-checkout-text, #0f172a);
        }
        .md-checkout-summary__row--total {
            margin-top: 6px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-weight: 700;
            font-size: 15px;
        }
        .md-checkout-summary__row--total.is-pending [data-md-checkout-total],
        [data-md-checkout-total].is-pending,
        .md-summary__line--total.is-pending .md-price,
        .md-summary__row--total.is-pending .md-price {
            font-weight: 650;
            font-size: 0.92em;
            letter-spacing: 0.01em;
            color: var(--md-checkout-primary, #0f766e);
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dashed;
            text-underline-offset: 3px;
            text-decoration-thickness: 1px;
        }
        .md-country-picker.is-awaiting-country {
            border-radius: 10px;
            box-shadow: 0 0 0 1px color-mix(in srgb, var(--md-checkout-accent, #f59e0b) 40%, transparent);
        }
        .md-country-picker.is-needed {
            border-radius: 10px;
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--md-checkout-accent, #f59e0b) 55%, transparent);
            animation: md-country-nudge 1.1s ease 2;
        }
        @keyframes md-country-nudge {
            0%, 100% { transform: translateY(0); }
            35% { transform: translateY(-2px); }
            70% { transform: translateY(1px); }
        }
        .md-checkout-summary__shipping {
            margin: 14px 0 8px;
            padding: 12px;
            border: 1px solid color-mix(in srgb, var(--md-checkout-primary, #0f766e) 25%, #cbd5e1);
            border-radius: 12px;
            background: #fff;
        }
        .md-checkout-summary__shipping input[data-md-shipping-country] {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 12px;
            font: inherit;
            box-sizing: border-box;
        }
        .md-checkout-summary__coupon-label {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--md-checkout-text, #0f172a);
            display: block;
        }
        .md-checkout-summary__coupon-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .md-checkout-summary__coupon-row input {
            flex: 1;
            min-width: 120px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 12px;
            font: inherit;
            background: #fff;
            color: var(--md-checkout-text, #0f172a);
        }
        .md-checkout-summary__coupon-row .md-btn,
        .md-checkout-summary__coupon-row button[data-md-coupon-apply] {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            background: var(--md-checkout-button, var(--md-checkout-primary, #0f766e));
            color: #fff;
        }
        .md-checkout-summary__coupon-msg {
            margin: 8px 0 0;
            font-size: 12px;
            color: var(--md-checkout-primary, #0f766e);
            min-height: 1em;
        }
        .md-checkout-summary__coupon-msg.is-error { color: #b91c1c; }
        .md-checkout-summary__coupon-applied {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            font-size: 13px;
        }
        .md-checkout-summary__coupon-applied button {
            border: 0;
            background: transparent;
            color: #64748b;
            text-decoration: underline;
            cursor: pointer;
            font: inherit;
            font-size: 12px;
        }
        .md-checkout-summary__row--discount {
            color: var(--md-checkout-primary, #0f766e);
            font-weight: 600;
        }
        .md-checkout-summary__row--magic {
            color: var(--md-checkout-primary, #0f766e);
            font-weight: 600;
        }
        .md-gallery, .md-media-carousel__thumbs, .md-video-carousel__thumbs {
            display:flex; flex-wrap:nowrap; gap:8px; margin-top:10px;
            overflow-x:auto; padding-bottom:4px; scrollbar-width:thin; -webkit-overflow-scrolling:touch;
        }
        .md-gallery button, .md-media-carousel__thumbs button, .md-video-carousel__thumbs button {
            position:relative; flex:0 0 68px; width:68px; height:68px; padding:0;
            border:1px solid var(--md-line, #2B3236); border-radius:4px; overflow:hidden;
            background: var(--md-panel, #1D2226); cursor:pointer;
        }
        .md-gallery button.is-active, .md-media-carousel__thumbs button.is-active,
        .md-video-carousel__thumbs button.is-active {
            outline:2px solid var(--md-amber, var(--md-checkout-primary, #0f766e)); outline-offset:1px;
        }
        .md-gallery img, .md-media-carousel__thumbs img, .md-video-carousel__thumbs img {
            width:100%; height:100%; object-fit:cover; display:block;
        }
        .md-media-carousel__stage { position:relative; overflow:hidden; aspect-ratio:1/1; min-height:220px; }
        .md-media-carousel__stage > img { width:100%; height:100%; object-fit:cover; display:block; }
        .md-media-carousel__stage > video[data-md-media-video] { display:none !important; }
        .md-media-carousel__nav, .md-video-carousel__nav {
            position:absolute; top:50%; transform:translateY(-50%); z-index:2;
            width:36px; height:36px; border:0; border-radius:50%;
            background: color-mix(in srgb, var(--md-graphite, #14181A) 70%, transparent);
            color: var(--md-paper, #fff); font-size:22px; line-height:1; cursor:pointer;
        }
        .md-media-carousel__prev, .md-video-carousel__prev { left:10px; }
        .md-media-carousel__next, .md-video-carousel__next { right:10px; }
        .md-media-carousel__count, .md-video-carousel__count {
            position:absolute; right:10px; bottom:10px; z-index:2;
            font:600 11px/1 var(--md-font-mono, ui-monospace, monospace);
            letter-spacing:.06em; color: var(--md-paper, #fff);
            background: color-mix(in srgb, var(--md-graphite, #000) 62%, transparent);
            padding:4px 8px; border-radius:999px;
        }
        .md-product__gallery { display:flex; flex-direction:column; min-width:0; }
        .md-video-carousel[hidden] { display:none !important; }
        .md-video-carousel__head {
            display:flex; align-items:center; justify-content:space-between; gap:8px;
            margin:0 0 8px; font:700 12px/1.2 var(--md-font-mono, ui-monospace, monospace);
            letter-spacing:.08em; text-transform:uppercase; color: var(--md-amber, #F2A93B);
        }
        .md-video-carousel__stage {
            position:relative; overflow:hidden; background:#000; aspect-ratio:16/9; min-height:180px;
        }
        .md-video-carousel__stage video {
            width:100%; height:100%; object-fit:contain; display:block; background:#000;
        }
        .md-media-carousel__play {
            position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
            background:rgba(0,0,0,.45); color:#fff; font-size:12px; pointer-events:none;
        }
        .md-media-carousel__vid-ph { display:block; width:100%; height:100%; background:#0b0d0e; }
        .md-variants { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0; }
        .md-variant {
            display:flex; align-items:center; gap:8px;
            border:1px solid var(--md-line, #e2e8f0);
            background: var(--md-panel, #fff);
            color: inherit;
            border-radius: var(--md-radius, 12px);
            padding:6px 10px 6px 6px;
            cursor:pointer;
            font: inherit;
        }
        .md-variant.is-selected {
            border-color: var(--md-amber, var(--md-checkout-primary, #0f766e));
            box-shadow:0 0 0 1px var(--md-amber, var(--md-checkout-primary, #0f766e));
        }
        .md-variant img { width:36px; height:36px; object-fit:cover; border-radius: calc(var(--md-radius, 12px) - 1px); }
        .md-product__variants { display:flex; flex-direction:column; gap:10px; width:100%; }
        .md-product__variants-label {
            font-family: var(--md-font-mono, ui-monospace, monospace);
            font-size:11px; letter-spacing:.06em; text-transform:uppercase;
            color: var(--md-muted-2, #64748b);
        }
        .md-product__variants-label strong { color: var(--md-paper, inherit); font-weight:700; }
        .md-product__buy [data-md-variants] { display:none !important; }
        .md-reviews, .md-comments { display:grid; gap:12px; }
        .md-reviews .md-empty, .md-comments .md-empty {
            text-align:center;
            padding:2rem 1rem;
            margin:0;
            width:100%;
            grid-column:1 / -1;
        }
        .md-review, .md-comment {
            background: var(--md-panel, color-mix(in srgb, currentColor 8%, transparent));
            color: inherit;
            border: 1px solid var(--md-line, color-mix(in srgb, currentColor 14%, transparent));
            border-radius: var(--md-radius, 14px);
            padding: 14px 16px;
        }
        .md-review p, .md-comment p { margin:0; color: inherit; }
        .md-review strong, .md-comment strong { color: inherit; }
        .md-review__meta, .md-comment__meta { display:flex; flex-wrap:wrap; gap:8px; font-size:.85rem; color: var(--md-muted, #8D9797); margin-bottom:6px; }
        .md-review__stars { color: var(--md-amber, #f59e0b); letter-spacing:1px; }
        .md-review__flag { display:inline-flex; align-items:center; gap:6px; }
        .md-review__flag .fi { font-size:.85rem; line-height:1; border-radius:2px; box-shadow:0 0 0 1px rgba(15,23,42,.12); }
        .md-combo-prices { display:none; }
        .md-price-row { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px 10px; }
        .md-price-was { text-decoration:line-through; opacity:.55; font-weight:600; font-size:.78em; }
        .md-price-save { display:inline-flex; align-items:center; font-size:.72em; font-weight:800; color:#fff; background:#dc2626; border-radius:999px; padding:2px 8px; }
        .md-catalog-pager { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin:24px 0 8px; }
        .md-catalog-pager button { min-width:2.25rem; padding:6px 10px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; }
        .md-catalog-pager button.is-active { background: var(--md-checkout-primary, #0f766e); color:#fff; border-color:transparent; }
        .md-ship-eta { display:inline-flex; align-items:center; margin-left:6px; cursor:help; color:#64748b; position:relative; }
        .md-ship-eta:hover::after, .md-ship-eta:focus::after {
            content: attr(data-tip);
            position:absolute; left:50%; bottom:125%; transform:translateX(-50%);
            background:#0f172a; color:#fff; font-size:11px; line-height:1.3;
            padding:6px 8px; border-radius:8px; white-space:nowrap; z-index:5;
        }
        .md-card { position: relative; display: flex; flex-direction: column; }
        .md-card__cta { position: relative; z-index: 2; margin-top: auto; padding: 0 0 4px; }
        .md-card__cta [data-md-add-to-cart], .md-card [data-cart-add] {
            position: relative; z-index: 2; pointer-events: auto; width: 100%;
        }
        [data-md-products] { display: grid; gap: 16px; }
        @media (min-width: 640px) {
            [data-md-products] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 1024px) {
            [data-md-products] { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .md-mod-urgency,
        [data-md-module="urgency"].md-mod-bar {
            justify-content: center;
            text-align: center;
        }
        [data-md-module="urgency"] [data-md-urgency-copy] {
            display: block;
            width: 100%;
            text-align: center;
        }
        /* El theme a veces deja un hueco extra; solo se muestra la barra del runtime */
        [data-md-module="urgency"]:not(.md-mod-bar):not(.md-mod-urgency) {
            display: none !important;
        }
        @media (max-width: 767px) {
            .md-atc-modal__card { width: 100%; margin: 0 8px; }
            .md-mod-bar { font-size: 13px; padding: 8px 12px; }
            .md-card__name, .md-product-card__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            [data-md-module="wheel"], [data-md-module="cross_sell"], [data-md-module="cross-sell"],
            [data-md-module="newsletter"], [data-md-module="social"], [data-md-module="urgency"] {
                max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch;
            }
            .md-locale-currency { flex-wrap: wrap; }
            .md-header, .md-nav { max-width: 100%; }
            .md-product__buy, .md-pdp__row .md-qty { flex-wrap: wrap; }
            [data-md-cart] { display: block; }
            .md-cart { grid-template-columns: 1fr !important; }
            .md-review__flag .fi { font-size: 1rem; }
        }
        .md-review__photos, .md-comment__photos { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
        .md-review__photos a, .md-comment__photos a { display:block; cursor:zoom-in; border-radius:10px; overflow:hidden; }
        .md-review__photos img, .md-comment__photos img { width:72px; height:72px; object-fit:cover; border-radius:10px; }
        .md-photo-lightbox { position:fixed; inset:0; z-index:12000; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(15,23,42,.78); }
        .md-photo-lightbox[hidden] { display:none !important; }
        .md-photo-lightbox__inner { position:relative; max-width:min(960px,100%); max-height:min(90vh,100%); }
        .md-photo-lightbox__img { display:block; max-width:100%; max-height:min(82vh,900px); margin:0 auto; border-radius:12px; object-fit:contain; background:#0f172a; box-shadow:0 20px 50px rgba(0,0,0,.35); }
        .md-photo-lightbox__close, .md-photo-lightbox__nav { position:absolute; border:0; cursor:pointer; color:#fff; background:rgba(15,23,42,.72); border-radius:999px; width:40px; height:40px; display:inline-flex; align-items:center; justify-content:center; }
        .md-photo-lightbox__close { top:-12px; right:-12px; font-size:22px; }
        .md-photo-lightbox__nav { top:50%; transform:translateY(-50%); font-size:28px; }
        .md-photo-lightbox__nav[hidden] { display:none !important; }
        .md-photo-lightbox__prev { left:-8px; }
        .md-photo-lightbox__next { right:-8px; }
        .md-photo-lightbox__counter { position:absolute; left:50%; bottom:-28px; transform:translateX(-50%); color:#fff; font-size:12px; opacity:.85; white-space:nowrap; }
        .md-pdp-short, .md-lede { color:#64748b; line-height:1.55; overflow-wrap:anywhere; }
        .md-product__specs[hidden] { display:none !important; }
        .md-pdp-long { line-height:1.65; font-size:.95rem; }
        .md-pdp-long h3 { margin:1.2em 0 .45em; font-size:1.02rem; }
        .md-pdp-long ul, .md-pdp-long ol { margin:0 0 1em; padding-left:1.15em; }
        .md-pdp-long li { margin:.35em 0; }
        .md-pdp-long dl { display:grid; grid-template-columns:minmax(8rem,max-content) 1fr; gap:.35em 1rem; margin:0 0 1em; }
        .md-pdp-long dt { color:#64748b; font-weight:650; }
        .md-pdp-long dd { margin:0; }
        .md-pdp-long p { margin:0 0 .75em; }
        .md-pdp-long img { display:none; }
        .md-pdp-video, [data-md-product-video] { margin-top:12px; }
        .md-pdp-video video, [data-md-product-video] video { width:100%; max-height:420px; border-radius:12px; background:#000; display:block; }
        .md-pdp-block { width:min(1100px, calc(100% - 32px)); margin:0 auto 40px; }
        .md-pdp-rating { display:flex; align-items:center; gap:8px; margin:6px 0 10px; font-size:.9rem; color:#64748b; }
        .md-pdp-rating .md-review__stars { color:#f59e0b; letter-spacing:1px; }
    </style>
    @foreach(($extraStylesheets ?? []) as $href)
        @if(is_string($href) && $href !== '')
            <link rel="stylesheet" href="{{ $href }}">
        @endif
    @endforeach
    @vite(['resources/css/storefront-flags.css'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/css/flag-icons.min.css" crossorigin="anonymous">
    @if(trim($css) !== '')
        <style>{!! $css !!}</style>
    @endif
    <style id="md-pdp-social">
        .md-review__photos, .md-comment__photos { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
        .md-review__photos a, .md-comment__photos a { display:block; cursor:zoom-in; border-radius:10px; overflow:hidden; }
        .md-review__photos img, .md-comment__photos img {
            display:block !important;
            width:72px;
            height:72px;
            object-fit:cover;
            border-radius:10px;
            background:#e2e8f0;
        }
        .md-photo-lightbox { position:fixed; inset:0; z-index:12000; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(15,23,42,.78); }
        .md-photo-lightbox[hidden] { display:none !important; }
        .md-photo-lightbox__inner { position:relative; max-width:min(960px,100%); max-height:min(90vh,100%); }
        .md-photo-lightbox__img { display:block; max-width:100%; max-height:min(82vh,900px); margin:0 auto; border-radius:12px; object-fit:contain; background:#0f172a; }
        .md-photo-lightbox__close, .md-photo-lightbox__nav { position:absolute; border:0; cursor:pointer; color:#fff; background:rgba(15,23,42,.72); border-radius:999px; width:40px; height:40px; display:inline-flex; align-items:center; justify-content:center; }
        .md-photo-lightbox__close { top:-12px; right:-12px; font-size:22px; }
        .md-photo-lightbox__nav { top:50%; transform:translateY(-50%); font-size:28px; }
        .md-photo-lightbox__nav[hidden] { display:none !important; }
        .md-photo-lightbox__prev { left:-8px; }
        .md-photo-lightbox__next { right:-8px; }
        .md-photo-lightbox__counter { position:absolute; left:50%; bottom:-28px; transform:translateX(-50%); color:#fff; font-size:12px; opacity:.85; }
    </style>
    <style id="md-qty-contrast">
        /* Stepper claro + texto oscuro (legible en temas oscuros y en summary arena) */
        .md-qty,
        .md-pdp__row .md-qty,
        [data-md-qty-wrap],
        [data-md-checkout-lines] .md-qty,
        .md-checkout-summary .md-qty,
        .md-checkout-summary__qty,
        [data-md-cart] .md-qty,
        .md-cart-line .md-qty {
            display: inline-flex !important;
            flex-direction: row !important;
            align-items: stretch !important;
            justify-content: flex-start !important;
            gap: 0 !important;
            padding: 0 !important;
            width: fit-content !important;
            max-width: 100% !important;
            flex: none !important;
            height: 36px !important;
            min-height: 36px !important;
            border: 1px solid rgba(36, 28, 16, 0.28) !important;
            border-radius: 999px !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            color: #241C10 !important;
            background: var(--md-sand, #EFE6D3) !important;
        }
        .md-pdp__row .md-qty { margin-top: 0 !important; }
        [data-md-checkout-lines] .md-qty,
        .md-checkout-summary .md-qty,
        .md-checkout-summary__qty,
        [data-md-cart] .md-qty,
        .md-cart-line .md-qty {
            margin: 0 !important;
            align-self: flex-start !important;
            height: 34px !important;
            min-height: 34px !important;
            border-radius: 10px !important;
        }
        .md-qty button,
        .md-qty [data-md-qty-minus],
        .md-qty [data-md-qty-plus],
        [data-md-qty-minus],
        [data-md-qty-plus],
        [data-md-cart-qty-minus],
        [data-md-cart-qty-plus],
        [data-md-checkout-lines] .md-qty button,
        .md-checkout-summary .md-qty button,
        .md-checkout-summary__qty button,
        [data-md-cart] .md-qty button,
        .md-cart-line .md-qty button {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            flex: 0 0 34px !important;
            width: 34px !important;
            min-width: 34px !important;
            height: 100% !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            color: #241C10 !important;
            box-shadow: none !important;
            outline: none !important;
            font: inherit !important;
            font-size: 18px !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            cursor: pointer !important;
        }
        .md-qty button:hover,
        [data-md-qty-minus]:hover,
        [data-md-qty-plus]:hover,
        [data-md-cart-qty-minus]:hover,
        [data-md-cart-qty-plus]:hover {
            background: color-mix(in srgb, var(--md-checkout-primary, #D98B3F) 16%, transparent) !important;
            color: #241C10 !important;
        }
        .md-qty input,
        .md-qty [data-md-qty],
        [data-md-qty],
        [data-md-cart-qty],
        [data-md-checkout-lines] .md-qty input,
        .md-checkout-summary .md-qty input,
        .md-checkout-summary__qty input,
        [data-md-cart] .md-qty input,
        .md-cart-line .md-qty input {
            display: block !important;
            flex: 0 0 42px !important;
            width: 42px !important;
            min-width: 42px !important;
            height: 100% !important;
            margin: 0 !important;
            padding: 0 2px !important;
            border: 0 !important;
            border-left: 1px solid rgba(36, 28, 16, 0.22) !important;
            border-right: 1px solid rgba(36, 28, 16, 0.22) !important;
            border-radius: 0 !important;
            background: transparent !important;
            color: #241C10 !important;
            box-shadow: none !important;
            outline: none !important;
            font-family: inherit !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            text-align: center !important;
            -moz-appearance: textfield !important;
        }
        .md-qty input::-webkit-outer-spin-button,
        .md-qty input::-webkit-inner-spin-button,
        [data-md-qty]::-webkit-outer-spin-button,
        [data-md-qty]::-webkit-inner-spin-button,
        [data-md-cart-qty]::-webkit-outer-spin-button,
        [data-md-cart-qty]::-webkit-inner-spin-button,
        [data-md-checkout-lines] .md-qty input::-webkit-outer-spin-button,
        [data-md-checkout-lines] .md-qty input::-webkit-inner-spin-button,
        .md-checkout-summary .md-qty input::-webkit-outer-spin-button,
        .md-checkout-summary .md-qty input::-webkit-inner-spin-button,
        .md-checkout-summary__qty input::-webkit-outer-spin-button,
        .md-checkout-summary__qty input::-webkit-inner-spin-button {
            -webkit-appearance: none !important;
            margin: 0 !important;
        }
        .md-checkout-summary__line-actions {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: center !important;
            gap: 10px !important;
            margin-top: 6px !important;
            width: 100% !important;
        }
    </style>
    <style id="md-checkout-center">
        /* El theme pone grid 2 col en section.md-checkout; el layout real es .md-checkout-layout */
        .md-checkout.md-mod-checkout,
        .md-mod-checkout {
            display: block !important;
        }
        .md-checkout.md-mod-checkout > .md-wrap,
        .md-mod-checkout > .md-wrap {
            width: 100%;
            max-width: var(--md-container, 1180px);
            margin-left: auto;
            margin-right: auto;
        }
        .md-checkout-layout {
            display: grid !important;
            gap: 24px;
            width: 100%;
        }
        @media (min-width: 960px) {
            .md-checkout-layout {
                grid-template-columns: 1.35fr 1fr !important;
                align-items: start;
            }
        }
    </style>
    <style id="md-atc-contrast">
        #md-atc-modal.md-atc-modal,
        #md-atc-modal .md-atc-modal__card {
            background: #ffffff !important;
            color: #0f172a !important;
        }
        #md-atc-modal .md-atc-modal__card,
        #md-atc-modal .md-atc-modal__card * {
            color: #0f172a;
        }
        #md-atc-modal .md-atc-modal__title,
        #md-atc-modal .md-atc-modal__name,
        #md-atc-modal .md-atc-modal__price,
        #md-atc-modal h1,
        #md-atc-modal h2,
        #md-atc-modal h3 {
            color: #0f172a !important;
        }
        #md-atc-modal .md-atc-modal__sub,
        #md-atc-modal .md-atc-modal__meta,
        #md-atc-modal .md-atc-modal__close {
            color: #64748b !important;
        }
        #md-atc-modal .md-atc-modal__product {
            display: grid !important;
            background: #f1f5f9 !important;
            color: #0f172a !important;
            border: 1px solid #e2e8f0 !important;
            grid-template-columns: 64px minmax(0, 1fr) auto !important;
            align-items: center !important;
        }
        #md-atc-modal .md-atc-modal__media {
            width: 64px !important;
            height: 64px !important;
            overflow: hidden !important;
            border-radius: 10px;
            background: #e2e8f0;
        }
        #md-atc-modal .md-atc-modal__media img,
        #md-atc-modal #md-atc-img {
            width: 64px !important;
            height: 64px !important;
            max-width: none !important;
            object-fit: cover !important;
            display: block !important;
            position: static !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        #md-atc-modal .md-atc-modal__ph[hidden],
        #md-atc-modal #md-atc-img[hidden] {
            display: none !important;
        }
        #md-atc-modal .md-atc-modal__name {
            overflow-wrap: anywhere;
        }
        #md-atc-modal .md-atc-modal__actions .md-btn--primary {
            color: #fff !important;
        }
        #md-atc-modal .md-atc-modal__actions .md-btn--ghost {
            background: #f8fafc !important;
            color: #0f172a !important;
            border: 1px solid #cbd5e1 !important;
        }
        [data-md-module="urgency"]:not(.md-mod-bar):not(.md-mod-urgency) {
            display: none !important;
        }
        .md-mod-newsletter-checkout {
            display: flex !important;
            flex-direction: row;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            margin: 10px 0 6px;
            padding: 12px 14px;
            border-radius: 12px;
            cursor: pointer;
            grid-column: 1 / -1;
        }
        .md-mod-newsletter-checkout span { flex: 1; min-width: 0; }
        .md-mod-newsletter-checkout input[type="checkbox"] {
            appearance: auto;
            width: 18px !important;
            height: 18px !important;
            min-width: 18px !important;
            max-width: 18px !important;
            margin: 3px 0 0 !important;
            padding: 0 !important;
            flex: none !important;
            accent-color: var(--md-checkout-primary, var(--md-primary, #0f766e));
        }
        .md-row2 > .md-mod-newsletter-checkout,
        .md-row3 > .md-mod-newsletter-checkout {
            grid-column: 1 / -1;
        }
    </style>
    <style id="md-nav-burger-contrast">
        /* Burger: chip claro + barras oscuras. Gana a appearance nativo iOS y al header Axiom. */
        @media (max-width: 900px) {
            button.md-nav__burger,
            button.md-nav__toggle,
            button[data-md-nav-toggle],
            .md-nav__burger[data-md-nav-toggle] {
                -webkit-appearance: none !important;
                appearance: none !important;
                color-scheme: only light !important;
                display: inline-flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 5px !important;
                width: 44px !important;
                min-width: 44px !important;
                height: 44px !important;
                min-height: 44px !important;
                padding: 0 !important;
                margin: 0 !important;
                background: #eaf0ff !important;
                background-color: #eaf0ff !important;
                color: #05070d !important;
                border: 1px solid #eaf0ff !important;
                border-radius: 12px !important;
                box-shadow: none !important;
                opacity: 1 !important;
                filter: none !important;
                mix-blend-mode: normal !important;
            }
            button.md-nav__burger > span,
            button[data-md-nav-toggle] > span,
            .md-nav__burger span {
                display: block !important;
                width: 18px !important;
                height: 2px !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #05070d !important;
                background-color: #05070d !important;
                opacity: 1 !important;
                visibility: visible !important;
            }
        }
        body.md-visit-mobile button.md-nav__burger,
        body.md-visit-mobile button.md-nav__toggle,
        body.md-visit-mobile button[data-md-nav-toggle],
        body.md-visit-mobile .md-nav__burger[data-md-nav-toggle] {
            -webkit-appearance: none !important;
            appearance: none !important;
            color-scheme: only light !important;
            display: inline-flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 5px !important;
            width: 44px !important;
            min-width: 44px !important;
            height: 44px !important;
            min-height: 44px !important;
            padding: 0 !important;
            background: #eaf0ff !important;
            background-color: #eaf0ff !important;
            color: #05070d !important;
            border: 1px solid #eaf0ff !important;
            border-radius: 12px !important;
        }
        body.md-visit-mobile button.md-nav__burger > span,
        body.md-visit-mobile button[data-md-nav-toggle] > span,
        body.md-visit-mobile .md-nav__burger span {
            display: block !important;
            width: 18px !important;
            height: 2px !important;
            margin: 0 !important;
            background: #05070d !important;
            background-color: #05070d !important;
            opacity: 1 !important;
        }
        body.md-visit-mobile button.md-burger[data-md-nav-toggle] {
            background: #f2a93b !important;
            background-color: #f2a93b !important;
            color: #17130a !important;
            border: 1px solid #f2a93b !important;
            border-radius: 3px !important;
            font-size: 22px !important;
            line-height: 1 !important;
            letter-spacing: 0 !important;
        }
        @media (max-width: 900px) {
            button.md-burger[data-md-nav-toggle] {
                background: #f2a93b !important;
                background-color: #f2a93b !important;
                color: #17130a !important;
                border: 1px solid #f2a93b !important;
                border-radius: 3px !important;
                font-size: 22px !important;
                line-height: 1 !important;
                letter-spacing: 0 !important;
            }
        }
        /* Emergency Power: gana al chip Axiom (este bloque va después) */
        body.md-theme-ep .md-nav__burger,
        body.md-theme-ep .md-burger,
        body.md-theme-ep [data-md-nav-toggle] {
            background: #f2a93b !important;
            background-color: #f2a93b !important;
            color: #17130a !important;
            border: 1px solid #f2a93b !important;
            border-radius: 3px !important;
        }
        body.md-theme-ep .md-nav__burger span,
        body.md-theme-ep [data-md-nav-toggle] span {
            background: #17130a !important;
            background-color: #17130a !important;
        }
        body.md-theme-ep .md-cart-link {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #1d2226 !important;
            border: 1px solid #f2a93b !important;
            color: #eef1f0 !important;
        }
        body.md-theme-ep .md-cart-link svg {
            stroke: #eef1f0 !important;
            color: #eef1f0 !important;
        }
        body.md-theme-ep .md-btn.md-btn--primary,
        body.md-theme-ep .md-btn--primary,
        body.md-theme-ep .md-checkout-box button[type="submit"],
        body.md-theme-ep [data-md-cart-checkout] {
            -webkit-appearance: none !important;
            appearance: none !important;
            background: #f2a93b !important;
            background-color: #f2a93b !important;
            color: #17130a !important;
            border: 0 !important;
        }
        body.md-theme-nocturno .md-nav__burger,
        body.md-theme-nocturno .md-burger,
        body.md-theme-nocturno [data-md-nav-toggle] {
            background: #ff6a39 !important;
            background-color: #ff6a39 !important;
            color: #170d07 !important;
            border: 1px solid #ff6a39 !important;
            border-radius: 10px !important;
        }
        body.md-theme-nocturno .md-nav__burger span,
        body.md-theme-nocturno [data-md-nav-toggle] span {
            background: #170d07 !important;
            background-color: #170d07 !important;
        }
        body.md-theme-nocturno .md-cart-link {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #1a212e !important;
            border: 1px solid #2b3448 !important;
            color: #f2f5f9 !important;
        }
        body.md-theme-nocturno .md-cart-link svg {
            stroke: #f2f5f9 !important;
            color: #f2f5f9 !important;
        }
        body.md-theme-nocturno .md-btn.md-btn--primary,
        body.md-theme-nocturno .md-btn--primary,
        body.md-theme-nocturno .md-checkout-box button[type="submit"],
        body.md-theme-nocturno [data-md-cart-checkout],
        body.md-theme-nocturno .md-add {
            -webkit-appearance: none !important;
            appearance: none !important;
            background: #ff6a39 !important;
            background-color: #ff6a39 !important;
            color: #170d07 !important;
            border: 0 !important;
        }
    </style>
    <style id="md-checkout-redirect">
        .md-checkout-redirect {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2147483000;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(5, 7, 13, .82);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
        }
        .md-checkout-redirect.is-on {
            display: flex !important;
        }
        .md-checkout-redirect__card {
            width: min(420px, 100%);
            padding: 28px 24px;
            border-radius: 18px;
            background: #fff;
            color: #0f172a;
            text-align: center;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .35);
        }
        .md-checkout-redirect__spin {
            width: 44px;
            height: 44px;
            margin: 0 auto 16px;
            border: 3px solid #e2e8f0;
            border-top-color: var(--md-checkout-primary, var(--md-primary, #0f766e));
            border-radius: 50%;
            animation: md-checkout-spin .8s linear infinite;
        }
        .md-checkout-redirect__title {
            margin: 0 0 6px;
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
        }
        .md-checkout-redirect__hint {
            margin: 0;
            font-size: .95rem;
            line-height: 1.4;
            color: #475569;
        }
        @keyframes md-checkout-spin {
            to { transform: rotate(360deg); }
        }
        body.md-checkout-redirecting {
            overflow: hidden !important;
        }
    </style>
</head>
@php
    $visit = (($visit ?? 'desktop') === 'mobile') ? 'mobile' : 'desktop';
    if (! str_contains((string) ($bodyClass ?? ''), 'md-visit-')) {
        $bodyClass = trim((string) ($bodyClass ?? '').' md-visit-'.$visit);
    }
    $mdBodyAttrs = '';
    if (! empty($bodyClass)) {
        $mdBodyAttrs .= ' class="'.e($bodyClass).'"';
    }
    if (! empty($bodyId)) {
        $mdBodyAttrs .= ' id="'.e($bodyId).'"';
    }
    if (! empty($bodyStyle)) {
        $mdBodyAttrs .= ' style="'.e($bodyStyle).'"';
    }
@endphp
<body{!! $mdBodyAttrs !!}>
@if(!empty($preview))
<div id="md-preview-banner" class="md-sandbox-banner" style="position:sticky;top:0;z-index:99999;background:var(--md-amber, var(--md-checkout-primary, #0f766e));color:#14181A;font:12px/1.4 inherit;padding:8px 14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
    <span>Vista previa · <strong>{{ $store->name }}</strong> · {{ $page['handle'] ?? '—' }}</span>
    <span style="opacity:.8">Así se ve el diseño con el catálogo de esta mini-tienda.</span>
</div>
@endif
@if(!empty($sandbox))
@php
    $nav = $sandboxNav ?? [];
    $isSandboxCheckout = in_array(($page['type'] ?? ''), ['checkout'], true)
        || in_array(($page['handle'] ?? ''), ['checkout'], true);
    $modLabels = $sandboxModuleLabels ?? [];
    $mods = is_array($sandboxModules ?? null) ? $sandboxModules : [];
@endphp
<div id="md-sandbox-banner" class="md-sandbox-banner" style="position:sticky;top:0;z-index:99999;background:var(--md-primary, var(--md-checkout-primary, #0f766e));color:#fff;font:12px/1.4 inherit;padding:8px 14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
    <span style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <span>{{ $sandboxLabel ?? 'Sandbox plantilla' }} · {{ $page['handle'] ?? '—' }}</span>
        @foreach($mods as $modKey => $modOn)
            @if($modOn)
                <span style="background:rgba(255,255,255,.2);border-radius:999px;padding:2px 8px;font-weight:600">{{ $modLabels[$modKey] ?? $modKey }}</span>
            @endif
        @endforeach
    </span>
    <nav style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        @if(!empty($nav['home']))<a href="{{ $nav['home'] }}" style="color:#fff;text-decoration:underline">Landing</a>@endif
        @if(!empty($nav['catalog']))<a href="{{ $nav['catalog'] }}" style="color:#fff;text-decoration:underline">Catálogo</a>@endif
        @if(!empty($nav['home']))<a href="{{ rtrim($nav['home'], '/') }}/pages/product" style="color:#fff;text-decoration:underline">Producto</a>@endif
        @if(!empty($nav['cart']))<a href="{{ $nav['cart'] }}" style="color:#fff;text-decoration:underline">Carrito</a>@endif
        @if(!empty($nav['checkout']))<a href="{{ $nav['checkout'] }}" style="color:#fff;text-decoration:underline">Checkout</a>@endif
        @if(!empty($nav['track']))<a href="{{ $nav['track'] }}" style="color:#fff;text-decoration:underline">Seguimiento</a>@endif
        <button type="button" id="md-sandbox-fill-checkout" class="md-btn" @unless($isSandboxCheckout) hidden @endunless
            style="background:#fff;color:var(--md-primary, var(--md-checkout-primary, #0f766e));border:0;border-radius:8px;padding:6px 10px;font:700 12px/1.2 inherit;cursor:pointer">
            Llenar checkout
        </button>
    </nav>
</div>
@endif

<div id="md-checkout-redirect" class="md-checkout-redirect" hidden aria-hidden="true" role="alertdialog" aria-modal="true" aria-labelledby="md-checkout-redirect-title" aria-describedby="md-checkout-redirect-hint">
    <div class="md-checkout-redirect__card">
        <div class="md-checkout-redirect__spin" aria-hidden="true"></div>
        <p id="md-checkout-redirect-title" class="md-checkout-redirect__title">{{ __('storefront.checkout.processing') }}</p>
        <p id="md-checkout-redirect-hint" class="md-checkout-redirect__hint">{{ __('storefront.checkout.redirecting') }}</p>
    </div>
</div>

@unless(!empty($moduleEngine))
{{-- Plugins de plataforma (legado). En engine=twig los módulos ya vienen ensamblados. --}}
<div id="md-sandbox-modules">
    <div data-md-module="urgency" class="md-hide md-mod-bar md-mod-urgency">
        <span data-md-urgency-copy>{!! __('storefront.urgency.left_units') !!}</span>
    </div>

    <div data-md-module="roulette" class="md-hide" id="md-roulette-root">
        <div id="md-roulette-fab-wrap" class="md-mod-roulette-fab-wrap">
            <button type="button" id="md-roulette-open" class="md-mod-roulette-fab" aria-haspopup="dialog" data-md-roulette-fab>{{ __('storefront.roulette.fab') }}</button>
            <div id="md-roulette-won" class="md-mod-roulette-won md-hide" aria-live="polite">
                <div class="md-mod-roulette-won-kicker" data-md-roulette-won-kicker>{{ __('storefront.roulette.won_kicker') }}</div>
                <strong id="md-roulette-won-label">—</strong>
                <div id="md-roulette-won-code-row" class="md-mod-roulette-won-code-row md-hide">
                    <code id="md-roulette-won-code"></code>
                    <button type="button" id="md-roulette-copy" class="md-mod-roulette-copy" data-md-roulette-copy>{{ __('storefront.roulette.copy') }}</button>
                </div>
                <div class="md-mod-roulette-won-timer"><span data-md-roulette-next-spin>{{ __('storefront.roulette.next_spin') }}</span> <b id="md-roulette-cooldown">--:--:--</b></div>
            </div>
            <div id="md-roulette-miss" class="md-mod-roulette-miss md-hide" aria-live="polite">
                <div class="md-mod-roulette-miss-face" aria-hidden="true">😢</div>
                <div class="md-mod-roulette-won-kicker" data-md-roulette-miss-title>{{ __('storefront.roulette.miss_title') }}</div>
                <strong data-md-roulette-miss-body>{{ __('storefront.roulette.miss_body') }}</strong>
                <button type="button" id="md-roulette-retry" class="md-mod-roulette-retry">{{ __('storefront.roulette.spin_again') }}</button>
            </div>
        </div>
        <div id="md-roulette-overlay" class="md-mod-roulette-overlay md-hide" role="dialog" aria-modal="true" aria-labelledby="md-roulette-title">
            <div class="md-mod-roulette-confetti" id="md-roulette-confetti" aria-hidden="true"></div>
            <div class="md-mod-roulette-stage">
                <button type="button" class="md-mod-roulette-x" id="md-roulette-close" aria-label="{{ __('storefront.roulette.close') }}">×</button>
                <h2 id="md-roulette-title">{{ __('storefront.roulette.headline') }}</h2>
                <p class="md-mod-roulette-sub" id="md-roulette-subtitle">{{ __('storefront.roulette.subtitle') }}</p>
                <div class="md-mod-roulette-pointer" aria-hidden="true"></div>
                <div class="md-mod-roulette-wheel-wrap">
                    <div id="md-roulette-wheel" class="md-mod-roulette-wheel"></div>
                    <div class="md-mod-roulette-hub" aria-hidden="true">★</div>
                </div>
                <button type="button" id="md-roulette-spin" class="md-btn md-mod-roulette-spin-btn">{{ __('storefront.roulette.spin') }}</button>
                <div id="md-roulette-result-panel" class="md-mod-roulette-result md-hide">
                    <strong id="md-roulette-result-label">—</strong>
                    <span id="md-roulette-result-extra"></span>
                    <div id="md-roulette-result-copy-row" class="md-mod-roulette-won-code-row md-hide" style="margin-top:10px;justify-content:center">
                        <code id="md-roulette-result-code"></code>
                        <button type="button" id="md-roulette-result-copy" class="md-mod-roulette-copy" data-md-roulette-copy-coupon>{{ __('storefront.roulette.copy_coupon') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Cross-sell vive junto al summary en checkout (no barra demo superior) --}}
    <div data-md-module="cross_sell" class="md-hide" hidden aria-hidden="true"></div>

    <div data-md-module="upsell" class="md-hide md-mod-upsell" id="md-upsell-demo">
        <div class="md-mod-upsell-head">
            <strong>{{ __('storefront.upsell.headline') }}</strong>
            <button type="button" id="md-upsell-close" class="md-mod-close" aria-label="{{ __('storefront.upsell.close') }}">×</button>
        </div>
        <div class="md-mod-upsell-body" id="md-upsell-body">
            <p id="md-upsell-copy">{{ __('storefront.upsell.copy_generic', ['pct' => '20%']) }}</p>
            <div class="md-mod-upsell-product" id="md-upsell-product" hidden>
                <img id="md-upsell-img" alt="" width="48" height="48" loading="lazy">
                <div>
                    <div id="md-upsell-name" class="md-mod-upsell-name"></div>
                    <div class="md-mod-upsell-prices">
                        <s id="md-upsell-was"></s>
                        <strong id="md-upsell-now"></strong>
                    </div>
                </div>
            </div>
        </div>
        <button type="button" id="md-upsell-accept" class="md-btn" data-md-upsell-accept>{{ __('storefront.upsell.cta', ['pct' => '20%']) }}</button>
        <p id="md-upsell-msg" class="md-mod-upsell-msg" hidden></p>
    </div>
</div>

{{-- Prueba social: toast (CSS .md-mod-social en modules_css) --}}
<div data-md-module="social_proof" id="md-social-proof" class="md-hide md-mod-social md-sp-left" aria-live="polite">
    <a id="md-sp-link" class="md-sp-thumb" href="#" data-md-sp-link>
        <img id="md-sp-img" class="md-sp-img" src="" alt="" width="48" height="48" loading="lazy" decoding="async">
        <span class="md-sp-dot md-sp-dot--fallback" aria-hidden="true"></span>
    </a>
    <div class="md-sp-body">
        <div><strong id="md-sp-name">Emma</strong> en <span class="md-sp-place"><img id="md-sp-flag" class="md-sp-flag" src="https://flagcdn.com/w40/us.png" width="18" height="13" alt="" decoding="async"><span id="md-sp-country">Estados Unidos</span></span></div>
        <div><span data-md-sp-bought>{{ __('storefront.social.bought') }}</span> <a id="md-sp-product" class="md-sp-product" href="#" data-md-sp-link>{{ __('storefront.social.a_product') }}</a></div>
        <div class="md-sp-muted" id="md-sp-when">hace 3 minutos</div>
    </div>
    <button type="button" id="md-sp-close" class="md-mod-close" title="Cerrar" aria-label="Cerrar">×</button>
</div>

{{-- Newsletter: solo checkbox en checkout (sin popup/FAB) --}}
<div data-md-module="newsletter" class="md-hide" id="md-newsletter-root" hidden aria-hidden="true"></div>

{{-- Cookies UE: banner + preferencias (CSS .md-mod-cookies) --}}
<div data-md-module="cookies" id="md-cookies" class="md-mod-cookies md-hide" hidden>
    <div class="md-mod-cookies__bar" id="md-cookies-bar" role="dialog" aria-modal="false" aria-labelledby="md-cookies-title" aria-describedby="md-cookies-body">
        <div class="md-mod-cookies__copy">
            <strong id="md-cookies-title">{{ __('storefront.cookies.title') }}</strong>
            <p id="md-cookies-body">{{ __('storefront.cookies.body') }}</p>
            <a id="md-cookies-policy" class="md-mod-cookies__policy" href="#" hidden target="_blank" rel="noopener">{{ __('storefront.cookies.policy') }}</a>
        </div>
        <div class="md-mod-cookies__actions">
            <button type="button" class="md-btn" data-md-cookies-accept>{{ __('storefront.cookies.accept') }}</button>
            <button type="button" class="md-btn md-btn--ghost" data-md-cookies-reject>{{ __('storefront.cookies.reject') }}</button>
            <button type="button" class="md-btn md-btn--ghost" data-md-cookies-configure>{{ __('storefront.cookies.configure') }}</button>
        </div>
    </div>
    <div class="md-mod-cookies__overlay md-hide" id="md-cookies-overlay" hidden>
        <div class="md-mod-cookies__card" role="dialog" aria-modal="true" aria-labelledby="md-cookies-prefs-title">
            <button type="button" class="md-mod-close" data-md-cookies-close aria-label="{{ __('storefront.upsell.close') }}">×</button>
            <h2 id="md-cookies-prefs-title">{{ __('storefront.cookies.configure') }}</h2>
            <p class="md-mod-cookies__hint">{{ __('storefront.cookies.body') }}</p>
            <label class="md-mod-cookies__cat">
                <input type="checkbox" checked disabled>
                <span>
                    <strong id="md-cookies-necessary-label">{{ __('storefront.cookies.necessary') }}</strong>
                    <em>{{ __('storefront.cookies.necessary_hint') }}</em>
                </span>
            </label>
            <label class="md-mod-cookies__cat" data-md-cookies-cat="analytics">
                <input type="checkbox" id="md-cookies-analytics">
                <span>
                    <strong id="md-cookies-analytics-label">{{ __('storefront.cookies.analytics') }}</strong>
                    <em>{{ __('storefront.cookies.analytics_hint') }}</em>
                </span>
            </label>
            <label class="md-mod-cookies__cat" data-md-cookies-cat="marketing">
                <input type="checkbox" id="md-cookies-marketing">
                <span>
                    <strong id="md-cookies-marketing-label">{{ __('storefront.cookies.marketing') }}</strong>
                    <em>{{ __('storefront.cookies.marketing_hint') }}</em>
                </span>
            </label>
            <button type="button" class="md-btn" data-md-cookies-save>{{ __('storefront.cookies.save') }}</button>
        </div>
    </div>
</div>

{{-- Modal: producto agregado → ir al checkout --}}
<div id="md-atc-modal" class="md-atc-modal md-hide" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="md-atc-title">
    <div class="md-atc-modal__backdrop" data-md-atc-close></div>
    <div class="md-atc-modal__card">
        <button type="button" class="md-atc-modal__close" data-md-atc-close aria-label="{{ __('storefront.upsell.close') }}">×</button>
        <div class="md-atc-modal__mark" aria-hidden="true">✓</div>
        <h2 class="md-atc-modal__title" id="md-atc-title">{{ __('storefront.atc.title') }}</h2>
        <p class="md-atc-modal__sub" id="md-atc-sub">{{ __('storefront.atc.sub') }}</p>
        <div class="md-atc-modal__product" id="md-atc-product">
            <div class="md-atc-modal__media">
                <span class="md-atc-modal__ph" id="md-atc-img-ph" aria-hidden="true"></span>
                <img id="md-atc-img" alt="" width="64" height="64" hidden referrerpolicy="no-referrer">
            </div>
            <div class="md-atc-modal__copy">
                <div class="md-atc-modal__name" id="md-atc-name">{{ __('storefront.social.a_product') }}</div>
                <div class="md-atc-modal__meta" id="md-atc-meta"></div>
            </div>
            <div class="md-atc-modal__price" id="md-atc-price"></div>
        </div>
        <div class="md-atc-modal__actions">
            <a class="md-btn md-btn--primary" id="md-atc-checkout" href="#">{{ __('storefront.atc.checkout') }}</a>
            <button type="button" class="md-btn md-btn--ghost" data-md-atc-close id="md-atc-continue">{{ __('storefront.atc.continue') }}</button>
            <a class="md-btn md-btn--ghost" id="md-atc-cart" href="#" hidden>{{ __('storefront.atc.cart') }}</a>
        </div>
    </div>
</div>
@endunless

{!! $html !!}

<script>
window.Multidrop = {!! $payloadJson !!};
window.Multidrop.csrf = document.querySelector('meta[name="csrf-token"]').content;
window.Multidrop.turnstile = window.Multidrop.turnstile || {};
if (
  window.Multidrop.turnstile.enabled &&
  window.Multidrop.turnstile.site_key &&
  !window.Multidrop.turnstile.local_bypass &&
  !window.turnstile &&
  !document.querySelector('script[data-md-turnstile]')
) {
  var ts = document.createElement('script');
  ts.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
  ts.async = true;
  ts.defer = true;
  ts.setAttribute('data-md-turnstile', '1');
  document.head.appendChild(ts);
}
if (Multidrop.locale) {
  try { document.documentElement.setAttribute('lang', String(Multidrop.locale).replace('_', '-')); } catch (eLang) {}
}
function mdLang() {
  var lang = String((window.Multidrop && Multidrop.locale) || (document.documentElement && document.documentElement.lang) || 'en').toLowerCase();
  return lang.split('-')[0].split('_')[0] || 'en';
}
function mdT(key, vars) {
  var cur = (window.Multidrop && Multidrop.i18n) || {};
  String(key || '').split('.').forEach(function (part) {
    cur = (cur && typeof cur === 'object') ? cur[part] : undefined;
  });
  var s = cur == null || typeof cur === 'object' ? '' : String(cur);
  if (!s) s = key;
  if (vars) {
    Object.keys(vars).forEach(function (k) {
      s = s.replace(new RegExp(':' + k, 'g'), String(vars[k]));
    });
  }
  return s;
}
function mdCheckoutRedirectOverlay() {
  return document.getElementById('md-checkout-redirect');
}
function mdShowCheckoutRedirect() {
  var el = mdCheckoutRedirectOverlay();
  if (!el) return;
  var title = el.querySelector('#md-checkout-redirect-title');
  var hint = el.querySelector('#md-checkout-redirect-hint');
  var processing = mdT('checkout.processing');
  if (!processing || processing === 'checkout.processing') processing = 'Procesando…';
  var redirecting = mdT('checkout.redirecting');
  if (!redirecting || redirecting === 'checkout.redirecting') redirecting = 'Te estamos llevando a la pasarela de pago';
  if (title) title.textContent = processing;
  if (hint) hint.textContent = redirecting;
  el.hidden = false;
  el.classList.add('is-on');
  el.setAttribute('aria-hidden', 'false');
  document.body.classList.add('md-checkout-redirecting');
  document.body.setAttribute('aria-busy', 'true');
  document.querySelectorAll('[data-md-checkout-form] button[type="submit"], [data-md-checkout-submit]').forEach(function (btn) {
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
  });
}
function mdHideCheckoutRedirect() {
  var el = mdCheckoutRedirectOverlay();
  if (el) {
    el.classList.remove('is-on');
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
  }
  document.body.classList.remove('md-checkout-redirecting');
  document.body.removeAttribute('aria-busy');
  document.querySelectorAll('[data-md-checkout-form] button[type="submit"], [data-md-checkout-submit]').forEach(function (btn) {
    btn.disabled = false;
    btn.removeAttribute('aria-busy');
  });
}
(function () {
  /** Selectores de idioma / moneda en el header (hooks data-md-locale-select / data-md-currency-select). */
  var CURRENCY_ISO = {
    USD: 'US', MXN: 'MX', EUR: 'EU', GBP: 'GB', CAD: 'CA', AUD: 'AU',
    BRL: 'BR', ARS: 'AR', CLP: 'CL', COP: 'CO', PEN: 'PE', UYU: 'UY',
    BOB: 'BO', PYG: 'PY', CRC: 'CR', GTQ: 'GT', HNL: 'HN', NIO: 'NI',
    PAB: 'PA', DOP: 'DO', CUP: 'CU', JPY: 'JP', CNY: 'CN', KRW: 'KR',
    INR: 'IN', CHF: 'CH', SEK: 'SE', NOK: 'NO', DKK: 'DK', PLN: 'PL',
    CZK: 'CZ', HUF: 'HU', RON: 'RO', TRY: 'TR', ZAR: 'ZA', NZD: 'NZ'
  };

  function normalizeIso(iso) {
    iso = String(iso || '').toLowerCase().replace(/[^a-z]/g, '');
    if (iso === 'uk') iso = 'gb';
    return iso.length === 2 || iso === 'eu' ? iso : '';
  }

  function localeIso(code) {
    var s = String(code || '').trim().replace(/-/g, '_');
    if (!s) return '';
    var parts = s.split('_');
    var region = parts.length > 1 ? parts[parts.length - 1] : '';
    region = region.toUpperCase();
    if (region === 'UK') region = 'GB';
    if (/^[A-Z]{2}$/.test(region)) return normalizeIso(region);
    // Solo idioma: heurística mínima
    var lang = (parts[0] || '').toLowerCase();
    if (lang === 'es') return 'mx';
    if (lang === 'en') return 'us';
    if (lang === 'pt') return 'br';
    if (lang === 'fr') return 'fr';
    if (lang === 'de') return 'de';
    if (lang === 'it') return 'it';
    return '';
  }

  function currencyIso(code) {
    var c = String(code || '').toUpperCase();
    return normalizeIso(CURRENCY_ISO[c] || (c.length === 3 ? c.slice(0, 2) : ''));
  }

  function localeLabel(code) {
    var s = String(code || '').trim();
    if (!s) return '';
    var parts = s.split(/[_-]/);
    return (parts[0] || s).toUpperCase();
  }

  function currencyLabel(code) {
    return String(code || '').toUpperCase();
  }

  function setFlagClass(el, iso) {
    if (!el) return;
    var code = normalizeIso(iso);
    el.className = 'md-locale-currency__flag fi' + (code ? (' fi-' + code) : '');
    el.textContent = '';
    el.setAttribute('aria-hidden', 'true');
  }

  function sameLocale(a, b) {
    var A = String(a || '').replace(/-/g, '_').toLowerCase();
    var B = String(b || '').replace(/-/g, '_').toLowerCase();
    if (A === B) return true;
    var a0 = A.split('_')[0];
    var b0 = B.split('_')[0];
    return a0 !== '' && a0 === b0;
  }

  function reloadWithParam(key, value) {
    try {
      var url = new URL(window.location.href);
      url.searchParams.set(key, value);
      window.location.assign(url.toString());
    } catch (e) {
      var join = window.location.search ? '&' : '?';
      window.location.assign(window.location.pathname + window.location.search + join + encodeURIComponent(key) + '=' + encodeURIComponent(value) + (window.location.hash || ''));
    }
  }

  function syncFlagBadge(sel, kind) {
    if (!sel) return;
    var item = sel.closest('.md-locale-currency__item');
    if (!item) return;
    var badge = item.querySelector('.md-locale-currency__flag');
    if (!badge) {
      badge = document.createElement('span');
      badge.setAttribute('aria-hidden', 'true');
      item.insertBefore(badge, sel);
    }
    var iso = kind === 'currency' ? currencyIso(sel.value) : localeIso(sel.value);
    setFlagClass(badge, iso);
    badge.setAttribute('title', String(sel.value || ''));
  }

  function ensureFlagWrap(sel) {
    if (!sel || sel.closest('.md-locale-currency__item')) return;
    var wrap = document.createElement('label');
    wrap.className = 'md-locale-currency__item';
    var flag = document.createElement('span');
    flag.className = 'md-locale-currency__flag fi';
    flag.setAttribute('aria-hidden', 'true');
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(flag);
    wrap.appendChild(sel);
  }

  function fillSelect(sel, values, current, paramKey, labelFn, kind) {
    if (!sel) return;
    var list = Array.isArray(values) ? values.filter(Boolean) : [];
    if (list.length === 0) {
      sel.hidden = true;
      var item = sel.closest('.md-locale-currency__item');
      if (item) item.hidden = true;
      return;
    }
    sel.hidden = false;
    ensureFlagWrap(sel);
    var itemShow = sel.closest('.md-locale-currency__item');
    if (itemShow) itemShow.hidden = false;
    sel.innerHTML = '';
    list.forEach(function (val) {
      var opt = document.createElement('option');
      opt.value = String(val);
      opt.textContent = labelFn ? labelFn(val) : String(val);
      if (paramKey === 'md_locale' ? sameLocale(val, current) : String(val).toUpperCase() === String(current || '').toUpperCase()) {
        opt.selected = true;
      }
      sel.appendChild(opt);
    });
    syncFlagBadge(sel, kind);
    if (sel._mdPrefsBound) return;
    sel._mdPrefsBound = true;
    sel.addEventListener('change', function () {
      syncFlagBadge(sel, kind);
      reloadWithParam(paramKey, sel.value);
    });
  }

  function initLocaleCurrency() {
    var md = window.Multidrop || {};
    var locales = Array.isArray(md.locales) ? md.locales : [];
    var currencies = Array.isArray(md.currencies) ? md.currencies : [];
    if (locales.length === 0 && md.locale) locales = [md.locale];
    if (currencies.length === 0 && md.currency) currencies = [md.currency];

    var locNodes = document.querySelectorAll('[data-md-locale-select]');
    var curNodes = document.querySelectorAll('[data-md-currency-select]');
    if (locNodes.length === 0 && curNodes.length === 0 && (locales.length || currencies.length)) {
      var host = document.querySelector('[data-md-locale-currency]')
        || document.querySelector('.md-header__actions')
        || document.querySelector('.md-nav__actions')
        || document.querySelector('header .md-nav__bar')
        || document.querySelector('header .md-nav')
        || document.querySelector('header');
      if (host) {
        var wrap = document.createElement('div');
        wrap.className = 'md-locale-currency';
        wrap.setAttribute('data-md-locale-currency', '');
        wrap.innerHTML = '<select data-md-locale-select aria-label="Language"></select>' +
          '<select data-md-currency-select aria-label="Currency"></select>';
        if (host.hasAttribute('data-md-locale-currency') || host.classList.contains('md-locale-currency')) {
          host.appendChild(wrap.firstChild);
          host.appendChild(wrap.lastChild);
        } else {
          host.insertBefore(wrap, host.firstChild);
        }
        locNodes = document.querySelectorAll('[data-md-locale-select]');
        curNodes = document.querySelectorAll('[data-md-currency-select]');
      }
    }

    locNodes.forEach(function (sel) {
      fillSelect(sel, locales, md.locale, 'md_locale', localeLabel, 'locale');
    });
    curNodes.forEach(function (sel) {
      fillSelect(sel, currencies, md.currency, 'md_currency', currencyLabel, 'currency');
    });

    document.querySelectorAll('[data-md-locale-currency]').forEach(function (box) {
      var loc = box.querySelector('[data-md-locale-select]');
      var cur = box.querySelector('[data-md-currency-select]');
      var any = (loc && !loc.hidden && loc.options.length) || (cur && !cur.hidden && cur.options.length);
      box.hidden = !any;
      box.classList.toggle('is-empty', !any);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLocaleCurrency);
  } else {
    initLocaleCurrency();
  }

  document.addEventListener('click', function (e) {
    var burger = e.target.closest('[data-md-nav-toggle], .md-nav__burger, .md-burger');
    if (!burger) return;
    var nav = burger.closest('.md-nav, .md-header, header');
    var scope = nav || document;
    var links = scope.querySelector('.md-nav__links');
    var mobile = document.querySelector('[data-md-nav-panel], .md-mobile-nav');
    if (!links && !mobile) return;
    var open = false;
    if (links) {
      open = links.classList.toggle('md-open');
    }
    if (mobile) {
      open = mobile.classList.toggle('is-open');
    }
    if (nav) {
      nav.classList.toggle('is-open', open);
    }
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    e.stopImmediatePropagation();
  }, true);
})();
@if(!empty($sandbox))
(function () {
  // Abrir Sandbox con ?md_reset=1 → limpia storage del navegador (carrito local, ruleta, countdown…)
  try {
    var params = new URLSearchParams(window.location.search || '');
    if (params.get('md_reset') === '1') {
      var store = (window.Multidrop && Multidrop.store) || {};
      var slug = String(store.slug || 'tpl');
      var id = String(store.id != null ? store.id : '0');
      var keys = [
        'md_cart_' + id,
        'md_cart__' + slug,
        'md_roulette_' + id,
        'md_roulette_' + slug,
        'md_roulette_sandbox',
        'md_sandbox_checkout_n_' + slug,
        'md_sandbox_checkout_n_tpl',
        'md_cross_expire_' + id,
        'md_cross_expire_' + slug,
        'md_cross_expire_sb',
        'md_demo_cart'
      ];
      keys.forEach(function (k) {
        try { localStorage.removeItem(k); } catch (e1) {}
        try { sessionStorage.removeItem(k); } catch (e2) {}
      });
      if (window.Multidrop) {
        Multidrop.cart = { items: [], count: 0, coupon: null, totals: { subtotal: 0, discount: 0, shipping: 0, magic_discount: 0, total: 0 } };
      }
      params.delete('md_reset');
      var qs = params.toString();
      var clean = window.location.pathname + (qs ? '?' + qs : '') + (window.location.hash || '');
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, '', clean);
      }
    }
  } catch (eReset) {}
})();
(function () {
  function checkoutForm() {
    var form = document.querySelector('[data-md-checkout-form]') || document.querySelector('form.md-checkout-form');
    if (form) return form;
    var email = document.querySelector('input[name="email"], input[type="email"]');
    var address = document.querySelector('input[name="address"], input[autocomplete*="address"]');
    if (email && address && email.closest('form') === address.closest('form')) {
      return email.closest('form');
    }
    return null;
  }
  function nextTestNo() {
    var key = 'md_sandbox_checkout_n_' + ((window.Multidrop && Multidrop.store && Multidrop.store.slug) || 'tpl');
    var n = parseInt(localStorage.getItem(key) || '0', 10);
    if (!n || n < 1) n = 0;
    n += 1;
    localStorage.setItem(key, String(n));
    return n;
  }
  function demoBuyer(n) {
    var pad = String(1000 + (n % 9000)).slice(-4);
    return {
      first_name: 'Ana',
      last_name: 'Prueba ' + n,
      name: 'Ana Prueba ' + n,
      email: 'sandbox+' + n + '@multidrop.test',
      phone: '55' + pad + pad.slice(0, 2),
      address: 'Calle Demo ' + n + ' #120, Col. Centro',
      city: 'Ciudad de México',
      state: 'CDMX',
      zip: String(10000 + (n % 8999)).padStart(5, '0'),
      country: 'MX',
      notes: 'Pedido sandbox #' + n + ' · DEMO10'
    };
  }
  function writeEl(el, value) {
    if (!el || value == null) return false;
    var tag = (el.tagName || '').toLowerCase();
    if (tag === 'select') {
      el.value = value;
      if (!el.value) {
        for (var i = 0; i < el.options.length; i++) {
          if (String(el.options[i].value).toUpperCase() === String(value).toUpperCase()
            || String(el.options[i].text).toUpperCase().indexOf(String(value).toUpperCase()) !== -1) {
            el.selectedIndex = i;
            break;
          }
        }
      }
    } else if (el.type === 'checkbox' || el.type === 'radio') {
      el.checked = !!value;
    } else {
      el.value = value;
    }
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }
  function fillByNames(form, names, value) {
    var ok = false;
    names.forEach(function (name) {
      form.querySelectorAll('[name="' + name + '"], #' + CSS.escape(name)).forEach(function (el) {
        if (writeEl(el, value)) ok = true;
      });
    });
    return ok;
  }
  function fillByAutocomplete(form, tokens, value) {
    var ok = false;
    form.querySelectorAll('[autocomplete]').forEach(function (el) {
      var ac = String(el.getAttribute('autocomplete') || '').toLowerCase();
      tokens.forEach(function (token) {
        if (ac === token || ac.indexOf(token) !== -1) {
          if (writeEl(el, value)) ok = true;
        }
      });
    });
    return ok;
  }
  function fillCheckout() {
    var form = checkoutForm();
    var btn = document.getElementById('md-sandbox-fill-checkout');
    if (!form) {
      alert('No hay formulario de checkout en esta página.');
      return;
    }
    var n = nextTestNo();
    var d = demoBuyer(n);
    fillByNames(form, ['first_name', 'firstname', 'fname'], d.first_name);
    fillByAutocomplete(form, ['given-name'], d.first_name);
    fillByNames(form, ['last_name', 'lastname', 'lname', 'surname'], d.last_name);
    fillByAutocomplete(form, ['family-name'], d.last_name);
    fillByNames(form, ['name', 'full_name', 'customer_name'], d.name);
    fillByNames(form, ['email'], d.email);
    fillByAutocomplete(form, ['email'], d.email);
    fillByNames(form, ['phone', 'tel', 'telephone', 'mobile'], d.phone);
    fillByAutocomplete(form, ['tel'], d.phone);
    fillByNames(form, ['address', 'address1', 'address_line1', 'street'], d.address);
    fillByAutocomplete(form, ['address-line1', 'street-address'], d.address);
    fillByNames(form, ['city', 'town', 'localidad'], d.city);
    fillByAutocomplete(form, ['address-level2'], d.city);
    fillByNames(form, ['state', 'province', 'region', 'estado'], d.state);
    fillByAutocomplete(form, ['address-level1'], d.state);
    fillByNames(form, ['zip', 'postal_code', 'postcode', 'cp', 'zipcode'], d.zip);
    fillByAutocomplete(form, ['postal-code'], d.zip);
    fillByNames(form, ['country', 'country_code', 'pais'], d.country);
    fillByAutocomplete(form, ['country'], d.country);
    fillByNames(form, ['notes', 'note', 'comment', 'comments'], d.notes);
    if (btn) btn.textContent = 'Llenado · #' + n;
  }
  function syncFillButton() {
    var btn = document.getElementById('md-sandbox-fill-checkout');
    if (!btn) return;
    if (checkoutForm()) btn.hidden = false;
    btn.addEventListener('click', fillCheckout);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncFillButton);
  } else {
    syncFillButton();
  }
})();
@endif

(function () {
  var bannerCount = document.getElementById('md-preview-product-count');
  if (bannerCount) {
    bannerCount.textContent = String((Multidrop.products || []).length);
  }
})();

(function () {
  // Runtime mínimo Multidrop. Si el theme trae theme.js propio, ese suele re-renderizar
  // en DOMContentLoaded; aquí dejamos un fallback compatible (featured/is_featured, handle/slug).
  function money(n) {
    if (n == null || isNaN(Number(n))) return '';
    return '$' + Number(n).toLocaleString('es-MX');
  }

  function salePriceHtml(p) {
    var now = p.price_formatted || money(p.price);
    var onSale = !!(p.on_sale && p.compare_at_price && Number(p.compare_at_price) > Number(p.price));
    var was = onSale ? (p.compare_at_formatted || money(p.compare_at_price)) : '';
    var save = onSale && p.save_percent ? ('−' + p.save_percent + '%') : '';
    return '<div class="md-price-row">' +
      (was ? '<s class="md-price-was">' + was + '</s>' : '') +
      '<span class="md-price">' + now + '</span>' +
      (save ? '<span class="md-price-save">' + save + '</span>' : '') +
    '</div>';
  }

  function paintSalePrice(root, product) {
    if (!product) return;
    var scope = root || document;
    var priceEl = scope.querySelector('[data-md-bind="product.price_formatted"], [data-md-bind="price_formatted"], .md-pdp__price, .md-hero__price, .md-price');
    if (!priceEl) return;
    if (priceEl.closest('.md-price-row')) {
      var row = priceEl.closest('.md-price-row');
      var wasEl = row.querySelector('.md-price-was');
      var saveEl = row.querySelector('.md-price-save');
      if (product.on_sale && product.compare_at_formatted) {
        if (wasEl) wasEl.textContent = product.compare_at_formatted;
        else {
          wasEl = document.createElement('s');
          wasEl.className = 'md-price-was';
          wasEl.textContent = product.compare_at_formatted;
          row.insertBefore(wasEl, priceEl);
        }
        if (product.save_percent) {
          if (saveEl) saveEl.textContent = '−' + product.save_percent + '%';
          else {
            saveEl = document.createElement('span');
            saveEl.className = 'md-price-save';
            saveEl.textContent = '−' + product.save_percent + '%';
            row.appendChild(saveEl);
          }
        }
      }
      return;
    }
    if (!(product.on_sale && product.compare_at_formatted)) return;
    var wrap = document.createElement('div');
    wrap.className = 'md-price-row';
    wrap.innerHTML = salePriceHtml(product);
    priceEl.parentNode.insertBefore(wrap, priceEl);
    priceEl.remove();
  }

  function isFeatured(p) {
    return !!(p && (p.featured || p.is_featured || p.is_star || p.star));
  }

  function isStar(p) {
    return !!(p && (p.is_star || p.star));
  }

  function liveProducts() {
    var all = (window.Multidrop && Array.isArray(Multidrop.products)) ? Multidrop.products : [];
    return all.filter(function (p) {
      var st = String(p.status || 'live').toLowerCase();
      return st === 'live' || st === '';
    });
  }

  function catalogPageSize() {
    var n = parseInt((window.Multidrop && Multidrop.catalog_per_page) || 12, 10);
    return [8, 12, 16, 24, 36, 48].indexOf(n) >= 0 ? n : 12;
  }

  function renderCatalogPager(root, page, pages) {
    var wrap = root.parentNode ? root.parentNode.querySelector('[data-md-catalog-pager]') : null;
    if (pages <= 1) {
      if (wrap) wrap.remove();
      return;
    }
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'md-catalog-pager';
      wrap.setAttribute('data-md-catalog-pager', '1');
      root.insertAdjacentElement('afterend', wrap);
    }
    wrap.innerHTML = '';
    for (var i = 1; i <= pages; i++) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = String(i);
      if (i === page) b.className = 'is-active';
      b.setAttribute('data-md-catalog-page', String(i));
      wrap.appendChild(b);
    }
  }

  function renderProducts(root) {
    if (!root || !window.Multidrop || !Array.isArray(Multidrop.products)) return;
    if (Multidrop.engine === 'twig' && root.querySelector('.md-card, [data-md-add-to-cart]')) return;
    if (root.getAttribute('data-md-manual') === '1' && !root.getAttribute('data-md-manual-list')) return;

    var list = liveProducts().slice();
    if (root.hasAttribute('data-md-star')) {
      list = list.filter(isStar);
      if (!list.length && Multidrop.star_product) list = [Multidrop.star_product];
      if (!list.length) list = liveProducts().slice(0, 1);
    } else if (root.hasAttribute('data-md-featured')) {
      list = list.filter(isFeatured);
      if (!list.length) list = liveProducts().slice();
    }
    var limit = parseInt(root.getAttribute('data-md-limit') || '0', 10);
    var paginate = !root.hasAttribute('data-md-featured') && !root.hasAttribute('data-md-star') && limit <= 0;
    var page = parseInt(root.getAttribute('data-md-page') || '1', 10) || 1;
    var per = catalogPageSize();
    var pages = paginate ? Math.max(1, Math.ceil(list.length / per)) : 1;
    if (page > pages) page = pages;
    if (limit > 0) list = list.slice(0, limit);
    else if (paginate) list = list.slice((page - 1) * per, page * per);

    if (!list.length) {
      root.innerHTML = '<div class="md-empty"><p>No hay productos en esta tienda todavía.</p></div>';
      renderCatalogPager(root, 1, 1);
      return;
    }

    root.innerHTML = list.map(function (p) {
      var name = p.name || p.title || 'Producto';
      var url = p.url || '#';
      var badge = p.badge ? '<span class="md-card__badge">' + String(p.badge).replace(/</g, '&lt;') + '</span>' : '';
      var videoBadge = p.has_video ? '<span class="md-card__video-badge" title="Video">▶</span>' : '';
      var media = p.image
        ? '<div class="md-card__media"><img src="' + String(p.image).replace(/"/g, '&quot;') + '" alt="' + String(name).replace(/"/g, '&quot;') + '" loading="lazy">' + badge + videoBadge + '</div>'
        : '<div class="md-card__media" style="background:#1e293b">' + badge + videoBadge + '</div>';
      return '' +
        '<article class="md-card" data-md-product-card data-id="' + (p.id || '') + '">' +
          '<a class="md-card__link" href="' + url + '">' + media +
            '<div class="md-card__body meta">' +
              '<h3 class="md-card__name">' + String(name).replace(/</g, '&lt;') + '</h3>' +
              '<div class="md-card__price price">' + salePriceHtml(p) + '</div>' +
            '</div>' +
          '</a>' +
          '<div class="md-card__cta">' +
            '<button type="button" class="md-btn md-btn--primary md-btn--block" data-md-add-to-cart data-product-id="' + (p.id || '') + '" data-id="' + (p.id || '') + '">Agregar</button>' +
          '</div>' +
        '</article>';
    }).join('');
    if (paginate) renderCatalogPager(root, page, pages);
  }

  document.querySelectorAll('[data-md-products]').forEach(renderProducts);
  (function hydrateThemeCatalog() {
    if (window.Multidrop && Multidrop.engine === 'twig') return;
    if (document.querySelector('[data-md-products]')) return;
    var grid = document.querySelector('.md-catalog, [data-md-catalog], .md-product-grid, .md-products, main .md-grid');
    if (!grid) return;
    grid.setAttribute('data-md-products', '1');
    renderProducts(grid);
  })();
  function refreshAllCatalogs() {
    document.querySelectorAll('[data-md-products]').forEach(function (root) {
      if (root.querySelector('[data-md-add-to-cart], [data-cart-add], [data-add-to-cart]')) return;
      renderProducts(root);
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    setTimeout(refreshAllCatalogs, 80);
  });
  setTimeout(refreshAllCatalogs, 400);
  document.addEventListener('click', function (e) {
    var pageBtn = e.target.closest('[data-md-catalog-page]');
    if (!pageBtn) return;
    var wrap = pageBtn.closest('[data-md-catalog-pager]');
    var root = wrap && wrap.previousElementSibling;
    if (!root || !root.hasAttribute('data-md-products')) {
      root = document.querySelector('[data-md-products]');
    }
    if (!root) return;
    root.setAttribute('data-md-page', pageBtn.getAttribute('data-md-catalog-page') || '1');
    renderProducts(root);
    try { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (err) {}
  });

  var product = Multidrop.product || null;
  if (product) {
    document.querySelectorAll('[data-md-bind]').forEach(function (el) {
      var key = el.getAttribute('data-md-bind') || '';
      var field = key.indexOf('product.') === 0 ? key.slice(8) : key;
      var val = product[field];
      if (field === 'price_formatted' && !val) val = money(product.price);
      if (field === 'name' && !val) val = product.title;
      if (field === 'image' && !val && product.images && product.images[0]) val = product.images[0];
      if (field === 'video' || field === 'video_url') val = product.video_url || (product.videos && product.videos[0] && product.videos[0].url) || val;
      if (field === 'description_short' && !val) val = product.summary || '';
      if (field === 'description_long' && !val) val = product.description_html || product.description || '';
      if (field === 'description_html' && !val) val = product.description_long || product.description || '';
      if (el.tagName === 'IMG') {
        if (val) el.setAttribute('src', val);
      } else if (el.tagName === 'VIDEO' || el.tagName === 'SOURCE') {
        if (val) {
          var src = playableVideoUrl(val);
          if (el.tagName === 'SOURCE') el.setAttribute('src', src);
          else {
            el.setAttribute('src', src);
            if (product.video_poster) el.setAttribute('poster', product.video_poster);
          }
        }
      } else if (field === 'description_long' || field === 'description_html') {
        el.innerHTML = storefrontLongHtml(val || product.description_long || product.description_html || '');
      } else if (field === 'description') {
        var longSlot = el.matches('.md-pdp-long, [data-md-description-long], section, article')
          || (el.tagName === 'DIV' && !el.classList.contains('md-lede'));
        if (longSlot) {
          el.innerHTML = storefrontLongHtml(product.description_long || product.description_html || val || '');
        } else {
          el.textContent = storefrontShortText(product.description_short || product.summary || val || '');
        }
      } else if (field === 'description_short' || field === 'summary') {
        el.textContent = storefrontShortText(val == null ? '' : String(val));
      } else {
        el.textContent = val == null ? '' : String(val);
      }
      // Badges / spans con hidden en el HTML: mostrar solo si hay valor
      if (field === 'badge' || el.classList.contains('md-product__badge') || el.classList.contains('md-badge')) {
        var showBadge = val != null && String(val).trim() !== '';
        el.hidden = !showBadge;
        if (showBadge) el.removeAttribute('hidden');
        else el.setAttribute('hidden', 'hidden');
      }
      // data-md-bind-attr="alt:product.name" (o varios separados por ;)
      var attrSpec = el.getAttribute('data-md-bind-attr');
      if (attrSpec) {
        attrSpec.split(';').forEach(function (part) {
          var bits = part.split(':');
          if (bits.length < 2) return;
          var attr = bits[0].trim();
          var srcKey = bits.slice(1).join(':').trim();
          var srcField = srcKey.indexOf('product.') === 0 ? srcKey.slice(8) : srcKey;
          var srcVal = product[srcField];
          if (srcField === 'name' && !srcVal) srcVal = product.title;
          if (attr && srcVal != null) el.setAttribute(attr, String(srcVal));
        });
      }
    });
    document.querySelectorAll('[data-md-add-to-cart]').forEach(function (btn) {
      if (btn.getAttribute('data-product-id') || btn.getAttribute('data-id')) return;
      if (product.id) {
        btn.setAttribute('data-product-id', String(product.id));
        btn.setAttribute('data-id', String(product.id));
      }
    });
    // Contenedor de video PDP: mostrar solo si hay video
    ensureVideoPlayer(product);
    hideEmptySpecGrids();
    document.querySelectorAll('[data-md-has-video]').forEach(function (el) {
      var show = !!product.has_video || !!(product.videos && product.videos.length);
      el.hidden = !show;
      el.style.display = show ? '' : 'none';
    });
    renderPdpExtras(product);
  }
  if (window.Multidrop && Multidrop.star_product) {
    paintSalePrice(document.querySelector('[data-md-star-product], .md-hero'), Multidrop.star_product);
  }

  function decodeStoreEntities(str) {
    var s = String(str == null ? '' : str);
    var ta = document.createElement('textarea');
    for (var i = 0; i < 4; i++) {
      ta.innerHTML = s;
      var next = ta.value.replace(/\u00a0/g, ' ');
      if (next === s) break;
      s = next;
    }
    return s.trim();
  }

  function parseEmbeddedSpecJson(raw) {
    var s = decodeStoreEntities(raw).replace(/<\/?p>/gi, '').trim();
    var start = s.indexOf('{');
    var end = s.lastIndexOf('}');
    if (start < 0 || end <= start) return null;
    try {
      var obj = JSON.parse(s.slice(start, end + 1));
      return obj && typeof obj === 'object' && !Array.isArray(obj) ? obj : null;
    } catch (e) {
      return null;
    }
  }

  function storefrontShortText(raw) {
    var obj = parseEmbeddedSpecJson(raw);
    if (obj) {
      var overview = obj.overview || obj.description || obj.summary || '';
      if (overview) return String(overview).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    }
    var s = decodeStoreEntities(raw).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (s.charAt(0) === '{') return '';
    return s;
  }

  function storefrontLongHtml(raw) {
    var obj = parseEmbeddedSpecJson(raw);
    if (obj) {
      var html = '';
      var specs = [];
      Object.keys(obj).forEach(function (k) {
        var v = obj[k];
        if (v == null || typeof v === 'object') return;
        v = String(v).trim();
        if (!v) return;
        var lk = k.toLowerCase();
        if (lk === 'overview' || lk === 'description' || lk === 'summary') {
          html += '<p>' + mdEsc(v) + '</p>';
          return;
        }
        specs.push([humanizeStorefrontSpec(k), v]);
      });
      if (specs.length) {
        html += '<h3>Ficha técnica</h3><dl>';
        specs.forEach(function (row) {
          html += '<dt>' + mdEsc(row[0]) + '</dt><dd>' + mdEsc(row[1]) + '</dd>';
        });
        html += '</dl>';
      }
      return html;
    }
    var s = decodeStoreEntities(raw);
    if (s.indexOf('<') === -1) return s ? '<p>' + mdEsc(s) + '</p>' : '';
    return s;
  }

  function humanizeStorefrontSpec(key) {
    var map = {
      power_type: 'Alimentación',
      motor_type: 'Motor',
      additional_functions: 'Funciones',
      blades: 'Aspas',
      specs: 'Medidas',
      specification: 'Medidas',
      modes: 'Modo',
      operation_mode: 'Modo',
      fan_speed: 'Velocidades',
      packing: 'Incluye',
      packing_list: 'Incluye',
      weight: 'Peso'
    };
    var lk = String(key || '').toLowerCase();
    if (map[lk]) return map[lk];
    return lk.replace(/[_-]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function hideEmptySpecGrids() {
    document.querySelectorAll('.md-product__specs').forEach(function (grid) {
      var any = false;
      grid.querySelectorAll('.md-spec__value').forEach(function (el) {
        var t = String(el.textContent || '').replace(/[—\-\s]/g, '');
        if (t) any = true;
      });
      grid.hidden = !any;
      if (!any) grid.style.display = 'none';
    });
  }

  function playableVideoUrl(url) {
    url = String(url || '');
    if (!url) return '';
    var proxy = (window.Multidrop && Multidrop.urls && Multidrop.urls.cj_video) || '/media/cj-video';
    if (/cjdropshipping\.com/i.test(url) && url.indexOf('/media/cj-video') === -1 && url.indexOf('cj-video') === -1) {
      return proxy + (proxy.indexOf('?') >= 0 ? '&' : '?') + 'u=' + encodeURIComponent(url);
    }
    return url;
  }

  function insertAfter(ref, node) {
    if (!ref || !node || !ref.parentNode) return;
    if (ref.nextSibling) ref.parentNode.insertBefore(node, ref.nextSibling);
    else ref.parentNode.appendChild(node);
  }

  function isAfterFooter(el) {
    var footer = document.querySelector('footer, .md-footer');
    if (!footer || !el) return false;
    return !!(footer.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING);
  }

  function mountPdpSection(section, afterHint) {
    if (!section) return;
    var related = document.querySelector('.md-related');
    var pdp = document.querySelector('[data-md-product]');
    var footer = document.querySelector('footer, .md-footer');
    var main = document.querySelector('main');
    if (afterHint && afterHint.parentNode && afterHint !== section) {
      insertAfter(afterHint, section);
      return;
    }
    if (related && related.parentNode && !related.contains(section)) {
      related.parentNode.insertBefore(section, related);
      return;
    }
    if (pdp && pdp.parentNode) {
      insertAfter(pdp, section);
      return;
    }
    if (main) {
      main.appendChild(section);
      return;
    }
    if (footer && footer.parentNode) {
      footer.parentNode.insertBefore(section, footer);
      return;
    }
    document.body.appendChild(section);
  }

  function ensureVideoPlayer(product) {
    renderMediaCarousel(product);
  }

  function mdEsc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function mdStars(score) {
    var n = Math.max(0, Math.min(5, parseInt(score, 10) || 0));
    return '★★★★★☆☆☆☆☆'.slice(5 - n, 10 - n);
  }

  function selectedVariantId() {
    var el = document.querySelector('[data-md-variant].is-selected, [data-md-variants] .is-selected');
    if (!el) return null;
    var id = parseInt(el.getAttribute('data-variant-id') || el.getAttribute('data-id') || '0', 10);
    return id || null;
  }

  function variantIdForAdd(el) {
    var scope = el ? el.closest('[data-md-product-card], .md-card, [data-md-product], [data-md-star-product]') : null;
    var sel = scope ? scope.querySelector('[data-md-variant].is-selected, [data-variant-id].is-selected, .md-variant-option.is-selected') : null;
    if (sel) {
      var sid = parseInt(sel.getAttribute('data-variant-id') || sel.getAttribute('data-id') || sel.dataset.variantId || '0', 10);
      if (sid) return sid;
    }
    var onBtn = parseInt((el && (el.getAttribute('data-variant-id') || '0')) || '0', 10);
    if (onBtn) return onBtn;
    if (el && el.closest('[data-md-products], .md-catalog, [data-md-catalog]')) return null;
    return selectedVariantId();
  }

  function setMainProductImage(url) {
    if (!url) return;
    var car = document.querySelector('[data-md-media-carousel]');
    if (car && typeof car._mdShowImage === 'function') {
      car._mdShowImage(url);
      return;
    }
    var img = document.querySelector('[data-md-gallery-main], [data-md-product] [data-md-bind="product.image"], [data-md-product] img[data-md-bind="image"], [data-md-product] .md-pdp-media img, [data-md-product] .md-product__main-media img, [data-md-product] .md-pdp__main img');
    if (img) img.setAttribute('src', url);
    if (Multidrop.product) Multidrop.product.image = url;
  }

  function collectImageSlides(product) {
    var slides = [];
    var seen = {};
    function add(url) {
      url = String(url || '').trim();
      if (!url || seen[url]) return;
      seen[url] = true;
      slides.push({ src: url });
    }
    if (product.image) add(product.image);
    (Array.isArray(product.images) ? product.images : []).forEach(add);
    return slides;
  }

  function collectVideoSlides(product) {
    var slides = [];
    var seen = {};
    function add(row) {
      var raw = row && typeof row === 'object' ? (row.url || '') : String(row || '');
      var url = playableVideoUrl(raw);
      if (!url || seen[url]) return;
      seen[url] = true;
      slides.push({
        src: url,
        poster: (row && (row.poster || row.cover)) || product.video_poster || '',
        name: (row && row.name) || 'Video'
      });
    }
    (Array.isArray(product.videos) ? product.videos : []).forEach(add);
    if (!slides.length && product.video_url) {
      add({ url: product.video_url, poster: product.video_poster, name: 'Video' });
    }
    return slides;
  }

  function lockThumbs(root) {
    if (!root) return;
    root.setAttribute('data-md-gallery-locked', '1');
    root.querySelectorAll('button:not([data-md-media-slide])').forEach(function (b) { b.remove(); });
    if (root._mdLock) return;
    root._mdLock = new MutationObserver(function () {
      root.querySelectorAll('button:not([data-md-media-slide])').forEach(function (b) { b.remove(); });
    });
    root._mdLock.observe(root, { childList: true });
  }

  function renderMediaCarousel(product) {
    var images = collectImageSlides(product);
    var videos = collectVideoSlides(product);
    var img = document.querySelector('[data-md-gallery-main], [data-md-product] [data-md-bind="product.image"], [data-md-product] img[data-md-bind="image"], [data-md-product] .md-product__main-media img');
    var thumbs = document.querySelector('[data-md-gallery-thumbs], [data-md-gallery], .md-product__thumbs, .md-pdp__thumbs');
    var pdp = document.querySelector('[data-md-product]') || document.querySelector('.md-product__gallery, .md-pdp-media');
    if (!img && pdp) {
      img = document.createElement('img');
      img.setAttribute('data-md-gallery-main', '');
      var media = pdp.querySelector('.md-product__main-media, .md-pdp-media, .md-product__gallery') || pdp;
      media.insertBefore(img, media.firstChild);
    }
    if (!img) return;
    var stage = img.closest('.md-product__main-media, .md-media-carousel__stage');
    if (!stage) {
      stage = document.createElement('div');
      stage.className = 'md-media-carousel__stage';
      img.parentNode.insertBefore(stage, img);
      stage.appendChild(img);
    }
    stage.classList.add('md-media-carousel__stage');
    var carousel = stage.closest('[data-md-media-carousel]') || stage.parentElement;
    if (carousel) {
      carousel.setAttribute('data-md-media-carousel', '');
      carousel.classList.add('md-media-carousel');
    }
    var strayVideo = stage.querySelector('video');
    if (strayVideo) {
      strayVideo.hidden = true;
      strayVideo.style.display = 'none';
    }
    var oldWrap = document.querySelector('[data-md-product-video]');
    if (oldWrap && !oldWrap.closest('[data-md-video-carousel]')) {
      oldWrap.hidden = true;
      oldWrap.style.display = 'none';
    }
    if (!thumbs) {
      thumbs = document.createElement('div');
      thumbs.setAttribute('data-md-gallery-thumbs', '');
      insertAfter(stage, thumbs);
    }
    thumbs.setAttribute('data-md-gallery', '');
    thumbs.setAttribute('data-md-gallery-thumbs', '');
    thumbs.classList.add('md-gallery', 'md-media-carousel__thumbs');
    lockThumbs(thumbs);

    if (!stage.querySelector('[data-md-media-prev]')) {
      var prev = document.createElement('button');
      prev.type = 'button';
      prev.className = 'md-media-carousel__nav md-media-carousel__prev';
      prev.setAttribute('data-md-media-prev', '');
      prev.setAttribute('aria-label', 'Anterior');
      prev.textContent = '‹';
      var next = document.createElement('button');
      next.type = 'button';
      next.className = 'md-media-carousel__nav md-media-carousel__next';
      next.setAttribute('data-md-media-next', '');
      next.setAttribute('aria-label', 'Siguiente');
      next.textContent = '›';
      var count = document.createElement('span');
      count.className = 'md-media-carousel__count';
      count.setAttribute('data-md-media-count', '');
      stage.appendChild(prev);
      stage.appendChild(next);
      stage.appendChild(count);
    }

    var imgIndex = 0;
    function showImage(i) {
      if (!images.length) return;
      imgIndex = ((i % images.length) + images.length) % images.length;
      img.style.display = '';
      img.setAttribute('referrerpolicy', 'no-referrer');
      img.setAttribute('src', images[imgIndex].src);
      if (Multidrop.product) Multidrop.product.image = images[imgIndex].src;
      thumbs.querySelectorAll('[data-md-media-slide]').forEach(function (b) {
        b.classList.toggle('is-active', parseInt(b.getAttribute('data-md-media-slide'), 10) === imgIndex);
      });
      var active = thumbs.querySelector('[data-md-media-slide].is-active');
      if (active && active.scrollIntoView) active.scrollIntoView({ inline: 'nearest', block: 'nearest' });
      var countEl = stage.querySelector('[data-md-media-count]');
      if (countEl) {
        countEl.textContent = (imgIndex + 1) + ' / ' + images.length;
        countEl.hidden = images.length < 2;
      }
      var many = images.length > 1;
      var prevBtn = stage.querySelector('[data-md-media-prev]');
      var nextBtn = stage.querySelector('[data-md-media-next]');
      if (prevBtn) prevBtn.hidden = !many;
      if (nextBtn) nextBtn.hidden = !many;
    }

    thumbs.hidden = images.length < 2;
    thumbs.innerHTML = images.map(function (s, i) {
      return '<button type="button" class="' + (i === 0 ? 'is-active' : '') + '" data-md-media-slide="' + i + '">' +
        '<img src="' + mdEsc(s.src) + '" alt="" loading="lazy" referrerpolicy="no-referrer"></button>';
    }).join('');
    thumbs.querySelectorAll('[data-md-media-slide]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showImage(parseInt(btn.getAttribute('data-md-media-slide'), 10) || 0);
      });
    });
    stage.querySelectorAll('[data-md-media-prev]').forEach(function (btn) {
      btn.onclick = function () { showImage(imgIndex - 1); };
    });
    stage.querySelectorAll('[data-md-media-next]').forEach(function (btn) {
      btn.onclick = function () { showImage(imgIndex + 1); };
    });
    if (!stage.getAttribute('data-md-swipe')) {
      stage.setAttribute('data-md-swipe', '1');
      var startX = 0;
      stage.addEventListener('pointerdown', function (ev) { startX = ev.clientX; });
      stage.addEventListener('pointerup', function (ev) {
        var dx = ev.clientX - startX;
        if (Math.abs(dx) < 40) return;
        showImage(imgIndex + (dx < 0 ? 1 : -1));
      });
    }
    if (carousel) {
      carousel._mdShowImage = function (url) {
        var i = images.findIndex(function (s) { return s.src === url; });
        if (i >= 0) showImage(i);
        else img.setAttribute('src', url);
      };
    }
    showImage(0);

    var mount = thumbs || stage;
    var vbox = document.querySelector('[data-md-video-carousel]');
    if (!vbox && mount && mount.parentNode) {
      vbox = document.createElement('div');
      vbox.className = 'md-video-carousel';
      vbox.setAttribute('data-md-video-carousel', '');
      vbox.innerHTML = '<div class="md-video-carousel__head">Videos <span data-md-video-label></span></div>' +
        '<div class="md-video-carousel__stage">' +
          '<video data-md-video-player controls playsinline preload="metadata"></video>' +
          '<button type="button" class="md-video-carousel__nav md-video-carousel__prev" data-md-video-prev aria-label="Video anterior">‹</button>' +
          '<button type="button" class="md-video-carousel__nav md-video-carousel__next" data-md-video-next aria-label="Video siguiente">›</button>' +
          '<span class="md-video-carousel__count" data-md-video-count></span>' +
        '</div>' +
        '<div class="md-video-carousel__thumbs" data-md-video-thumbs></div>';
      insertAfter(mount, vbox);
    }
    if (!vbox) return;
    if (!videos.length) {
      vbox.hidden = true;
      vbox.style.display = 'none';
      return;
    }
    vbox.hidden = false;
    vbox.removeAttribute('hidden');
    vbox.style.display = '';
    var vStage = vbox.querySelector('.md-video-carousel__stage');
    var vPlayer = vbox.querySelector('[data-md-video-player]');
    var vThumbs = vbox.querySelector('[data-md-video-thumbs]');
    if (!vPlayer || !vThumbs) return;
    if (vStage && !vStage.querySelector('[data-md-video-prev]')) {
      vStage.insertAdjacentHTML('beforeend',
        '<button type="button" class="md-video-carousel__nav md-video-carousel__prev" data-md-video-prev aria-label="Video anterior">‹</button>' +
        '<button type="button" class="md-video-carousel__nav md-video-carousel__next" data-md-video-next aria-label="Video siguiente">›</button>' +
        '<span class="md-video-carousel__count" data-md-video-count></span>'
      );
    }
    var vLabel = vbox.querySelector('[data-md-video-label]');
    if (vLabel) vLabel.textContent = '(' + videos.length + ')';
    var vIndex = 0;
    function showVideo(i) {
      vIndex = ((i % videos.length) + videos.length) % videos.length;
      var s = videos[vIndex];
      if (s.poster) vPlayer.setAttribute('poster', s.poster);
      if (vPlayer.getAttribute('src') !== s.src) vPlayer.src = s.src;
      vThumbs.querySelectorAll('[data-md-video-slide]').forEach(function (b) {
        b.classList.toggle('is-active', parseInt(b.getAttribute('data-md-video-slide'), 10) === vIndex);
      });
      var c = vbox.querySelector('[data-md-video-count]');
      if (c) {
        c.textContent = (vIndex + 1) + ' / ' + videos.length;
        c.hidden = videos.length < 2;
      }
      var manyV = videos.length > 1;
      var vp = vbox.querySelector('[data-md-video-prev]');
      var vn = vbox.querySelector('[data-md-video-next]');
      if (vp) vp.hidden = !manyV;
      if (vn) vn.hidden = !manyV;
    }
    vThumbs.hidden = videos.length < 2;
    vThumbs.innerHTML = videos.map(function (s, i) {
      var thumb = s.poster
        ? '<img src="' + mdEsc(s.poster) + '" alt="" referrerpolicy="no-referrer">'
        : '<span class="md-media-carousel__vid-ph"></span>';
      return '<button type="button" class="' + (i === 0 ? 'is-active' : '') + '" data-md-video-slide="' + i + '">' +
        thumb + '<span class="md-media-carousel__play" aria-hidden="true">▶</span></button>';
    }).join('');
    vThumbs.querySelectorAll('[data-md-video-slide]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showVideo(parseInt(btn.getAttribute('data-md-video-slide'), 10) || 0);
      });
    });
    var vp = vbox.querySelector('[data-md-video-prev]');
    var vn = vbox.querySelector('[data-md-video-next]');
    if (vp) vp.onclick = function () { showVideo(vIndex - 1); };
    if (vn) vn.onclick = function () { showVideo(vIndex + 1); };
    if (vStage && !vStage.getAttribute('data-md-swipe')) {
      vStage.setAttribute('data-md-swipe', '1');
      var vStartX = 0;
      vStage.addEventListener('pointerdown', function (ev) { vStartX = ev.clientX; });
      vStage.addEventListener('pointerup', function (ev) {
        var dx = ev.clientX - vStartX;
        if (Math.abs(dx) < 40) return;
        showVideo(vIndex + (dx < 0 ? 1 : -1));
      });
    }
    showVideo(0);
  }

  function renderGallery(product) {
    renderMediaCarousel(product);
  }

  function renderVariants(product) {
    var variants = Array.isArray(product.variants) ? product.variants : [];
    var root = document.querySelector('[data-md-variants]');
    if (!root) return;
    if (!variants.length) {
      root.innerHTML = '';
      root.hidden = true;
      var wrapEmpty = root.closest('.md-product__variants');
      if (wrapEmpty) wrapEmpty.hidden = true;
      return;
    }
    root.hidden = false;
    root.classList.add('md-variants');
    var wrap = root.closest('.md-product__variants');
    if (wrap) wrap.hidden = false;
    function setChosen(name) {
      var lab = document.querySelector('[data-md-variant-chosen]');
      if (lab) lab.textContent = name || '';
    }
    root.innerHTML = variants.map(function (v, i) {
      var name = v.name || v.sku || ('Opción ' + (i + 1));
      var img = v.image ? '<img src="' + mdEsc(v.image) + '" alt="">' : '';
      return '<button type="button" class="md-variant' + (v.image ? ' md-variant--photo' : '') + (i === 0 ? ' is-selected' : '') + '" data-md-variant data-variant-id="' + mdEsc(v.id || '') + '" data-image="' + mdEsc(v.image || '') + '" data-name="' + mdEsc(name) + '">' +
        img + '<span>' + mdEsc(name) + '</span></button>';
    }).join('');
    setChosen(variants[0] && (variants[0].name || variants[0].sku) || '');
    root.querySelectorAll('[data-md-variant]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        root.querySelectorAll('[data-md-variant]').forEach(function (b) { b.classList.remove('is-selected'); });
        btn.classList.add('is-selected');
        setChosen(btn.getAttribute('data-name') || '');
        var img = btn.getAttribute('data-image');
        if (img) setMainProductImage(img);
      });
    });
  }

  function countryFlagHtml(code, flagUrl) {
    code = String(code || '').trim();
    flagUrl = String(flagUrl || '').trim();
    var iso = code.length === 2 ? code.toLowerCase() : '';
    if (code.toLowerCase() === 'uk') iso = 'gb';
    var label = code ? mdEsc(code.toUpperCase()) : '';
    var flag = '';
    if (iso) {
      flag = '<span class="fi fi-' + iso + '" title="' + label + '"></span>';
    } else if (flagUrl) {
      flag = '<img src="' + mdEsc(flagUrl) + '" alt="" width="16" height="12" style="border-radius:2px">';
    }
    if (!flag && !label) return '';
    return '<span class="md-review__flag">' + flag + (label ? '<span>' + label + '</span>' : '') + '</span>';
  }

  function hydrateCountryFlags(root) {
    root = root || document;
    root.querySelectorAll('.md-review__meta > span, .md-comment__meta > span').forEach(function (el) {
      if (el.classList.contains('md-review__flag') || el.classList.contains('md-review__stars')) return;
      if (el.querySelector('.fi')) return;
      var t = String(el.textContent || '').trim();
      if (/^[A-Za-z]{2}$/.test(t)) el.outerHTML = countryFlagHtml(t);
    });
  }

  function commentsDuplicateReviews(comments, reviews) {
    comments = Array.isArray(comments) ? comments : [];
    reviews = Array.isArray(reviews) ? reviews : [];
    if (!comments.length) return true;
    if (!reviews.length) return false;
    var ids = {};
    reviews.forEach(function (r) {
      var id = String((r && r.comment_id) || '');
      if (id) ids[id] = true;
    });
    return comments.every(function (c) {
      var id = String((c && c.comment_id) || '');
      return id && ids[id];
    });
  }

  function reviewPhotoUrls(r) {
    r = r || {};
    var raw = r.images || r.commentUrls || r.comment_urls || r.photos || r.pics || [];
    if (typeof raw === 'string') raw = [raw];
    if (!Array.isArray(raw)) return [];
    return raw.map(function (item) {
      if (!item) return '';
      if (typeof item === 'string') return item.trim();
      return String(item.url || item.src || item.image || item.img || '').trim();
    }).filter(function (url) {
      return /^https?:\/\//i.test(url) || url.indexOf('//') === 0;
    }).map(function (url) {
      return url.indexOf('//') === 0 ? 'https:' + url : url;
    });
  }

  var mdPhotoLightboxState = { urls: [], index: 0 };

  function ensurePhotoLightbox() {
    var el = document.getElementById('md-photo-lightbox');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'md-photo-lightbox';
    el.className = 'md-photo-lightbox';
    el.setAttribute('hidden', '');
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', 'Foto de reseña');
    el.innerHTML =
      '<div class="md-photo-lightbox__inner">' +
        '<button type="button" class="md-photo-lightbox__close" aria-label="Cerrar">&times;</button>' +
        '<button type="button" class="md-photo-lightbox__nav md-photo-lightbox__prev" aria-label="Anterior" hidden>&lsaquo;</button>' +
        '<img class="md-photo-lightbox__img" src="" alt="" referrerpolicy="no-referrer">' +
        '<button type="button" class="md-photo-lightbox__nav md-photo-lightbox__next" aria-label="Siguiente" hidden>&rsaquo;</button>' +
        '<div class="md-photo-lightbox__counter" hidden></div>' +
      '</div>';
    document.body.appendChild(el);
    el.addEventListener('click', function (e) {
      if (e.target === el || e.target.classList.contains('md-photo-lightbox__close')) {
        closePhotoLightbox();
      }
    });
    el.querySelector('.md-photo-lightbox__prev').addEventListener('click', function (e) {
      e.stopPropagation();
      showPhotoLightboxIndex(mdPhotoLightboxState.index - 1);
    });
    el.querySelector('.md-photo-lightbox__next').addEventListener('click', function (e) {
      e.stopPropagation();
      showPhotoLightboxIndex(mdPhotoLightboxState.index + 1);
    });
    return el;
  }

  function showPhotoLightboxIndex(index) {
    var urls = mdPhotoLightboxState.urls || [];
    if (!urls.length) return;
    if (index < 0) index = urls.length - 1;
    if (index >= urls.length) index = 0;
    mdPhotoLightboxState.index = index;
    var el = ensurePhotoLightbox();
    var img = el.querySelector('.md-photo-lightbox__img');
    var prev = el.querySelector('.md-photo-lightbox__prev');
    var next = el.querySelector('.md-photo-lightbox__next');
    var counter = el.querySelector('.md-photo-lightbox__counter');
    img.src = urls[index];
    img.alt = 'Foto ' + (index + 1);
    var multi = urls.length > 1;
    prev.hidden = !multi;
    next.hidden = !multi;
    if (multi) {
      counter.hidden = false;
      counter.textContent = (index + 1) + ' / ' + urls.length;
    } else {
      counter.hidden = true;
      counter.textContent = '';
    }
  }

  function openPhotoLightbox(urls, startIndex) {
    urls = (Array.isArray(urls) ? urls : []).filter(Boolean);
    if (!urls.length) return;
    mdPhotoLightboxState.urls = urls;
    ensurePhotoLightbox().hidden = false;
    document.documentElement.style.overflow = 'hidden';
    showPhotoLightboxIndex(startIndex || 0);
  }

  function closePhotoLightbox() {
    var el = document.getElementById('md-photo-lightbox');
    if (!el) return;
    el.hidden = true;
    var img = el.querySelector('.md-photo-lightbox__img');
    if (img) img.removeAttribute('src');
    mdPhotoLightboxState = { urls: [], index: 0 };
    document.documentElement.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var link = e.target && e.target.closest
      ? e.target.closest('.md-review__photos a, .md-comment__photos a')
      : null;
    if (!link) return;
    var wrap = link.closest('.md-review__photos, .md-comment__photos');
    if (!wrap) return;
    e.preventDefault();
    var urls = Array.prototype.map.call(wrap.querySelectorAll('a'), function (a) {
      return a.getAttribute('href') || '';
    }).filter(Boolean);
    var start = urls.indexOf(link.getAttribute('href') || '');
    openPhotoLightbox(urls, start >= 0 ? start : 0);
  });

  document.addEventListener('keydown', function (e) {
    var el = document.getElementById('md-photo-lightbox');
    if (!el || el.hidden) return;
    if (e.key === 'Escape') {
      closePhotoLightbox();
    } else if (e.key === 'ArrowLeft') {
      showPhotoLightboxIndex(mdPhotoLightboxState.index - 1);
    } else if (e.key === 'ArrowRight') {
      showPhotoLightboxIndex(mdPhotoLightboxState.index + 1);
    }
  });

  function renderSocialList(root, items, kind) {
    if (!root) return;
    items = Array.isArray(items) ? items : [];
    if (!items.length) {
      root.classList.add(kind === 'reviews' ? 'md-reviews' : 'md-comments');
      root.innerHTML = '<p class="md-empty">' + (kind === 'reviews' ? mdT('pdp.no_reviews') : mdT('pdp.no_comments')) + '</p>';
      return;
    }
    root.classList.add(kind === 'reviews' ? 'md-reviews' : 'md-comments');
    root.innerHTML = items.map(function (r) {
      var photos = reviewPhotoUrls(r).map(function (url) {
        return '<a href="' + mdEsc(url) + '" target="_blank" rel="noopener"><img src="' + mdEsc(url) + '" alt="" loading="lazy" referrerpolicy="no-referrer"></a>';
      }).join('');
      var cc = r.country || r.countryCode || r.country_code || '';
      var stars = kind === 'reviews' ? '<span class="md-review__stars">' + mdStars(r.score) + '</span>' : '';
      return '<article class="' + (kind === 'reviews' ? 'md-review' : 'md-comment') + '">' +
        '<div class="' + (kind === 'reviews' ? 'md-review__meta' : 'md-comment__meta') + '">' +
          '<strong>' + mdEsc(r.author || 'Comprador') + '</strong>' +
          (cc || r.flag_url ? countryFlagHtml(cc, r.flag_url) : '') +
          stars +
          (r.date ? '<span>' + mdEsc(r.date) + '</span>' : '') +
        '</div>' +
        (r.comment ? '<p>' + mdEsc(r.comment) + '</p>' : '') +
        (photos ? '<div class="' + (kind === 'reviews' ? 'md-review__photos' : 'md-comment__photos') + '">' + photos + '</div>' : '') +
      '</article>';
    }).join('');
    hydrateCountryFlags(root);
  }

  function ensurePdpHook(attr, afterSelector, title) {
    var existing = document.querySelector('[' + attr + ']');
    if (existing) {
      var section = existing.closest('section') || existing.parentElement;
      if (section && (isAfterFooter(section) || (section.parentElement && section.parentElement.tagName === 'BODY'))) {
        var after = afterSelector ? document.querySelector(afterSelector) : null;
        mountPdpSection(section, after ? (after.closest('section') || after) : null);
      }
      if (!existing.closest('.md-container, .md-section, [data-md-product]') && existing.parentElement) {
        existing.parentElement.classList.add('md-section', 'md-container', 'md-pdp-block');
      }
      return existing;
    }
    var pdp = document.querySelector('[data-md-product], .md-pdp, .md-product__grid, main');
    if (!pdp) return null;
    var section = document.createElement('section');
    section.className = 'md-section md-pdp-block';
    var wrap = document.createElement('div');
    wrap.className = 'md-wrap';
    if (title) {
      var h = document.createElement('h2');
      h.textContent = title;
      wrap.appendChild(h);
    }
    var box = document.createElement('div');
    box.setAttribute(attr, '');
    if (attr === 'data-md-reviews') box.className = 'md-reviews';
    if (attr === 'data-md-comments') box.className = 'md-comments';
    wrap.appendChild(box);
    section.appendChild(wrap);
    var after = afterSelector ? document.querySelector(afterSelector) : null;
    mountPdpSection(section, after ? (after.closest('section') || after) : document.querySelector('[data-md-product]'));
    return box;
  }

  function renderPdpExtras(product) {
    var pdp = document.querySelector('[data-md-product]')
      || document.querySelector('.md-pdp, .md-product, .md-product__grid')
      || document.querySelector('[data-md-gallery-main]')
      || document.querySelector('main');
    if (!pdp) return;

    var gallery = document.querySelector('[data-md-gallery], [data-md-gallery-thumbs], .md-product__thumbs, .md-pdp__thumbs');
    if (!gallery) {
      var media = (document.querySelector('[data-md-product]') || pdp).querySelector('.md-pdp-media, .md-product__gallery, .md-pdp__gallery, .md-product__media') || pdp;
      gallery = document.createElement('div');
      gallery.setAttribute('data-md-gallery', '');
      media.appendChild(gallery);
    }
    renderGallery(product);

    var variants = document.querySelector('[data-md-variants]');
    var buy = document.querySelector('.md-product__buy');
    var infoBox = (document.querySelector('[data-md-product]') || pdp).querySelector('.md-pdp-info, .md-product__info') || pdp;
    if (variants && buy && buy.contains(variants)) {
      buy.parentNode.insertBefore(variants, buy);
    }
    if (!variants) {
      variants = document.createElement('div');
      variants.setAttribute('data-md-variants', '');
      if (buy && buy.parentNode) buy.parentNode.insertBefore(variants, buy);
      else {
        var atc = infoBox.querySelector('[data-md-add-to-cart]');
        if (atc) infoBox.insertBefore(variants, atc);
        else infoBox.appendChild(variants);
      }
    }
    if (variants && !variants.closest('.md-product__variants')) {
      var vwrap = document.createElement('div');
      vwrap.className = 'md-product__variants';
      vwrap.innerHTML = '<div class="md-product__variants-label">Option · <strong data-md-variant-chosen></strong></div>';
      variants.parentNode.insertBefore(vwrap, variants);
      vwrap.appendChild(variants);
    }
    renderVariants(product);

    ensureVideoPlayer(product);

    if (product.rating_avg && !document.querySelector('[data-md-rating]')) {
      var priceEl = document.querySelector('[data-md-bind="product.price_formatted"], [data-md-bind="price_formatted"], .md-price');
      if (priceEl && priceEl.parentNode) {
        var rating = document.createElement('p');
        rating.className = 'md-pdp-rating';
        rating.setAttribute('data-md-rating', '');
        var count = product.review_count || (product.reviews && product.reviews.length) || 0;
        rating.innerHTML = '<span class="md-review__stars">' + mdStars(product.rating_avg) + '</span> '
          + mdEsc(Number(product.rating_avg).toFixed(1))
          + (count ? ' · ' + count + ' reseñas' : '');
        insertAfter(priceEl, rating);
      }
    }

    if (!document.querySelector('[data-md-bind="product.description_short"]') && product.description_short) {
      var info2 = (document.querySelector('[data-md-product]') || pdp).querySelector('.md-pdp-info, .md-product__info') || pdp;
      if (!info2.querySelector('.md-lede, .md-pdp-short')) {
        var shortEl = document.createElement('p');
        shortEl.className = 'md-pdp-short';
        shortEl.setAttribute('data-md-bind', 'product.description_short');
        shortEl.textContent = product.description_short;
        var priceEl2 = info2.querySelector('[data-md-bind="product.price_formatted"], .md-price');
        if (priceEl2 && priceEl2.parentNode) priceEl2.parentNode.insertBefore(shortEl, priceEl2.nextSibling);
        else info2.insertBefore(shortEl, info2.firstChild);
      }
    }

    var longBind = document.querySelector('[data-md-bind="product.description_long"], [data-md-bind="product.description_html"], [data-md-description-long], .md-pdp-long');
    if (!longBind && (product.description_long || product.description_html)) {
      var longBox = ensurePdpHook('data-md-description-long', '[data-md-product]', 'Descripción');
      if (longBox) {
        longBox.className = 'md-pdp-long';
        longBox.setAttribute('data-md-bind', 'product.description_long');
        longBox.innerHTML = storefrontLongHtml(product.description_long || product.description_html || '');
      }
    } else if (longBind && isAfterFooter(longBind.closest('section') || longBind)) {
      mountPdpSection(longBind.closest('section') || longBind.parentElement, document.querySelector('[data-md-product]'));
    }

    paintSalePrice(document.querySelector('[data-md-star-product], .md-hero') || document, window.Multidrop && Multidrop.star_product);
    paintSalePrice(document.querySelector('[data-md-product], .md-pdp, .md-product') || document, product);

    if (product.is_combo && document.querySelector('[data-md-combo-prices]')) {
      document.querySelectorAll('[data-md-combo-prices]').forEach(function (el) { el.remove(); });
    }

    var reviewsRoot = document.querySelector('[data-md-reviews]') || ensurePdpHook('data-md-reviews', '[data-md-product]', 'Reseñas');
    if (reviewsRoot && isAfterFooter(reviewsRoot.closest('section') || reviewsRoot)) {
      reviewsRoot = ensurePdpHook('data-md-reviews', '[data-md-product]', 'Reseñas');
    }
    renderSocialList(reviewsRoot, product.reviews, 'reviews');
    var commentItems = Array.isArray(product.comments) ? product.comments : [];
    var reviewItems = Array.isArray(product.reviews) ? product.reviews : [];
    var commentsAreDup = !commentItems.length || commentsDuplicateReviews(commentItems, reviewItems);
    var commentsRoot = document.querySelector('[data-md-comments]');
    if (commentsAreDup) {
      if (commentsRoot) {
        var commentSection = commentsRoot.closest('section');
        if (commentSection && !commentSection.querySelector('[data-md-reviews]')) commentSection.remove();
        else commentsRoot.remove();
      }
    } else {
      if (!commentsRoot || isAfterFooter(commentsRoot.closest('section') || commentsRoot)) {
        commentsRoot = ensurePdpHook('data-md-comments', '[data-md-reviews]', 'Comentarios');
      }
      renderSocialList(commentsRoot, commentItems, 'comments');
    }
    hydrateCountryFlags(document);
    hideEmptySpecGrids();
  }

  var useApi = !!(Multidrop.commerce && Multidrop.urls && Multidrop.urls.cart_add);

  function loadCart() {
    if (Multidrop.cart && Array.isArray(Multidrop.cart.items)) return Multidrop.cart;
    try {
      var raw = JSON.parse(localStorage.getItem('md_cart_' + Multidrop.store.id) || 'null');
      if (raw && Array.isArray(raw.items)) return raw;
      if (Array.isArray(raw)) return { items: raw };
      return { items: [] };
    } catch (e) { return { items: [] }; }
  }
  function applyCart(cart) {
    Multidrop.cart = cart || { items: [] };
    if (!Array.isArray(Multidrop.cart.items)) Multidrop.cart.items = [];
    if (!useApi) {
      localStorage.setItem('md_cart_' + Multidrop.store.id, JSON.stringify(Multidrop.cart));
    }
    renderCart();
    syncCheckoutSummary(Multidrop.cart);
    var count = Multidrop.cart.count != null
      ? Multidrop.cart.count
      : (Multidrop.cart.items || []).reduce(function (n, it) { return n + (it.qty || 1); }, 0);
    document.querySelectorAll('[data-md-cart-count]').forEach(function (el) {
      el.textContent = String(count);
    });
    var totals = Multidrop.cart.totals || {};
    var shipOk = !!checkoutShippingCountry(Multidrop.cart);
    document.querySelectorAll('[data-md-checkout-totals]').forEach(function (el) {
      if (!shipOk) {
        el.textContent = mdT('checkout.total_pending') || mdT('checkout.pick_country');
        return;
      }
      if (!totals.total && totals.total !== 0) return;
      el.textContent = 'Subtotal ' + money(totals.subtotal) +
        (totals.discount ? ' · Desc. -' + money(totals.discount) : '') +
        ' · Total ' + money(totals.total);
    });
    try {
      document.dispatchEvent(new CustomEvent('md:cart:change', { detail: Multidrop.cart }));
    } catch (e) {}
  }

  function formatCheckoutMoney(n) {
    if (window.Multidrop && Multidrop.Theme && typeof Multidrop.Theme.formatPrice === 'function') {
      try { return Multidrop.Theme.formatPrice(n); } catch (e) {}
    }
    return money(n);
  }

  function countryFlagUrl(code) {
    var c = String(code || '').toLowerCase();
    if (c === 'uk') c = 'gb';
    if (!c) return 'https://flagcdn.com/w40/un.png';
    return 'https://flagcdn.com/w40/' + c + '.png';
  }

  function shippingCountryStorageKey() {
    var id = (Multidrop.store && Multidrop.store.id != null) ? Multidrop.store.id : 0;
    return 'md_ship_cc_' + id;
  }

  function normalizeCountryCode(code) {
    code = String(code || '').trim().toUpperCase();
    if (code === 'UK') code = 'GB';
    return code;
  }

  function isAllowedShippingCountry(code) {
    code = normalizeCountryCode(code);
    if (!code) return false;
    var countries = Array.isArray(Multidrop.shipping_countries) ? Multidrop.shipping_countries : [];
    return countries.some(function (c) { return normalizeCountryCode(c.code) === code; });
  }

  function readStoredShippingCountry() {
    try {
      var v = localStorage.getItem(shippingCountryStorageKey()) || '';
      return isAllowedShippingCountry(v) ? normalizeCountryCode(v) : '';
    } catch (eStore) {
      return '';
    }
  }

  function writeStoredShippingCountry(code) {
    code = normalizeCountryCode(code);
    if (!isAllowedShippingCountry(code)) return;
    try {
      localStorage.setItem(shippingCountryStorageKey(), code);
    } catch (eWrite) {}
  }

  function persistGeoCountryIfEmpty() {
    if (readStoredShippingCountry()) return;
    var geo = normalizeCountryCode((Multidrop.geo && Multidrop.geo.country) || '');
    if (isAllowedShippingCountry(geo)) writeStoredShippingCountry(geo);
  }

  function preferredShippingCountry() {
    var cartShip = normalizeCountryCode(
      (Multidrop.cart && (Multidrop.cart.shipping_country || (Multidrop.cart.shipping_info && Multidrop.cart.shipping_info.country))) || ''
    );
    if (isAllowedShippingCountry(cartShip)) return cartShip;
    var stored = readStoredShippingCountry();
    if (stored) return stored;
    var geo = normalizeCountryCode((Multidrop.geo && Multidrop.geo.country) || '');
    if (isAllowedShippingCountry(geo)) return geo;
    return '';
  }

  var mdShippingQuoteInflight = '';

  function maybeAutoSelectShippingCountry() {
    persistGeoCountryIfEmpty();
    var preferred = preferredShippingCountry();
    var picker = document.querySelector('[data-md-country-picker]');
    if (picker) setCountryPickerDisplay(preferred);
    if (!preferred || !picker) return;
    applyShippingCountry(preferred);
  }

  persistGeoCountryIfEmpty();

  function findShippingAddressSection() {
    var form = document.querySelector('[data-md-checkout-form], form.md-checkout-form, form#md-checkout-form, .md-checkout form');
    if (!form) return null;

    // fieldset / section con leyenda Envío · Shipping · Dirección
    var blocks = form.querySelectorAll('fieldset, .md-checkout-section, .md-fieldset, section');
    for (var i = 0; i < blocks.length; i++) {
      var head = blocks[i].querySelector('legend, h2, h3, .md-h3, .md-eyebrow');
      var t = ((head && head.textContent) || '').toLowerCase();
      if (
        t.indexOf('shipping') !== -1 ||
        t.indexOf('envío') !== -1 ||
        t.indexOf('envio') !== -1 ||
        t.indexOf('dirección') !== -1 ||
        t.indexOf('direccion') !== -1 ||
        t.indexOf('address') !== -1
      ) {
        return blocks[i];
      }
    }

    var addr = form.querySelector('#address, [name="address"], #ck-address');
    if (addr) {
      return addr.closest('fieldset, .md-checkout-section, .md-fieldset, section, .md-checkout-form-grid') || addr.parentNode || form;
    }

    var country = form.querySelector('[name="country"], [data-md-country], #ck-country');
    if (country) {
      return country.closest('fieldset, .md-checkout-section, .md-fieldset, .md-field, section') || country.parentNode || form;
    }

    return null;
  }

  function ensureCheckoutCountryPicker() {
    document.querySelectorAll('.md-checkout-summary__shipping').forEach(function (el) {
      el.remove();
    });

    var form = document.querySelector('[data-md-checkout-form], form.md-checkout-form, form#md-checkout-form, .md-checkout form');
    var existing = document.querySelector('[data-md-country-picker]');
    if (existing) {
      // Si quedó mal ubicado (después del CTA), reubicarlo
      relocateCountryPicker(existing, form);
      bindCountryPicker(existing);
      maybeAutoSelectShippingCountry();
      return existing;
    }

    var section = findShippingAddressSection();
    if (!section && !form) return null;

    // Si el theme ya trae input de país, reemplazarlo por el picker (mismo sitio)
    var nativeCountry = form
      ? form.querySelector('.md-country-search, .md-field--country, [data-md-country], #ck-country, [name="country"]:not([type="hidden"])')
      : null;
    var insertTarget = null;
    if (nativeCountry) {
      insertTarget = nativeCountry.closest('.md-field, .md-country-search, .md-checkout-form-grid > *') || nativeCountry;
    }

    var wrap = document.createElement('div');
    wrap.className = 'md-field md-field--country';
    wrap.setAttribute('data-md-country-field', '1');
    wrap.innerHTML =
      '<label for="md-shipping-country-search">País de envío</label>' +
      '<div class="md-country-picker" data-md-country-picker>' +
        '<button type="button" class="md-country-picker__trigger" data-md-country-trigger aria-haspopup="listbox" aria-expanded="false">' +
          '<img class="md-country-picker__flag md-hide" data-md-country-flag src="" width="20" height="15" alt="" decoding="async" hidden>' +
          '<span data-md-country-label>' + mdT('checkout.pick_country_ellipsis') + '</span>' +
          '<span class="md-country-picker__chev" aria-hidden="true">▾</span>' +
        '</button>' +
        '<div class="md-country-picker__panel md-hide" data-md-country-panel hidden>' +
          '<input type="search" id="md-shipping-country-search" class="md-country-picker__search" data-md-country-search placeholder="Buscar país…" autocomplete="off">' +
          '<ul class="md-country-picker__list" data-md-country-list role="listbox"></ul>' +
        '</div>' +
        '<input type="hidden" name="country" value="" data-md-shipping-country-field data-md-shipping-country>' +
      '</div>' +
      '<p class="md-country-picker__msg" data-md-shipping-msg></p>';

    if (insertTarget && insertTarget.parentNode) {
      // Quitar input nativo / datalist del theme para no duplicar name=country
      var oldInput = insertTarget.matches('[name="country"], [data-md-country], input')
        ? insertTarget
        : insertTarget.querySelector('[name="country"], [data-md-country], #ck-country');
      if (oldInput && oldInput.tagName === 'INPUT' && oldInput.type !== 'hidden') {
        var listId = oldInput.getAttribute('list');
        if (listId) {
          var dl = document.getElementById(listId);
          if (dl) dl.remove();
        }
      }
      insertTarget.parentNode.replaceChild(wrap, insertTarget);
    } else if (section) {
      // Insertar dentro del bloque Envío, antes del botón de pagar / ofertas
      var stopAt = section.querySelector('[data-md-module="cross_sell"], button[type="submit"], [data-md-checkout-submit]');
      var zipRow = section.querySelector('#ck-zip, [name="zip"], [name="postal_code"]');
      var after = zipRow ? (zipRow.closest('.md-row3, .md-row2, .md-field, .md-checkout-form-grid') || zipRow) : null;
      if (after && after.parentNode === section) {
        if (after.nextSibling) section.insertBefore(wrap, after.nextSibling);
        else section.appendChild(wrap);
      } else if (stopAt && stopAt.parentNode === section) {
        section.insertBefore(wrap, stopAt);
      } else if (stopAt && section.contains(stopAt)) {
        stopAt.parentNode.insertBefore(wrap, stopAt);
      } else {
        var firstGrid = section.querySelector('.md-checkout-form-grid, .md-row3, .md-row2');
        if (firstGrid && firstGrid.parentNode === section) section.insertBefore(wrap, firstGrid.nextSibling);
        else section.appendChild(wrap);
      }
    } else {
      return null;
    }

    bindCountryPicker(wrap.querySelector('[data-md-country-picker]'));
    maybeAutoSelectShippingCountry();
    return wrap.querySelector('[data-md-country-picker]');
  }

  function relocateCountryPicker(picker, form) {
    if (!picker || !form) return;
    var fieldWrap = picker.closest('[data-md-country-field], .md-field--country') || picker.parentNode;
    if (!fieldWrap || !form.contains(fieldWrap)) return;

    var submit = form.querySelector('button[type="submit"], [data-md-checkout-submit]');
    if (!submit || !form.contains(submit)) return;

    // ¿El picker está después del botón pagar en el DOM?
    var pos = submit.compareDocumentPosition(fieldWrap);
    if (!(pos & Node.DOCUMENT_POSITION_FOLLOWING)) return;

    var section = findShippingAddressSection() || form;
    var zipRow = section.querySelector('#ck-zip, [name="zip"], [name="postal_code"]');
    var after = zipRow ? (zipRow.closest('.md-row3, .md-row2, .md-field') || zipRow) : null;
    if (after && after.parentNode) {
      if (after.nextSibling) after.parentNode.insertBefore(fieldWrap, after.nextSibling);
      else after.parentNode.appendChild(fieldWrap);
      return;
    }
    if (submit.parentNode) submit.parentNode.insertBefore(fieldWrap, submit);
  }

  function normalizeSearchText(str) {
    var s = String(str || '').toLowerCase();
    try {
      s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    } catch (eNorm) {
      s = s
        .replace(/[áàäâã]/g, 'a')
        .replace(/[éèëê]/g, 'e')
        .replace(/[íìïî]/g, 'i')
        .replace(/[óòöôõ]/g, 'o')
        .replace(/[úùüû]/g, 'u')
        .replace(/ñ/g, 'n')
        .replace(/ç/g, 'c');
    }
    return s
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function countrySearchScore(country, queryNorm) {
    if (!queryNorm) return 0;
    var name = normalizeSearchText(country.name || '');
    var code = normalizeSearchText(country.code || '');
    if (!name && !code) return 9999;

    // Código exacto / prefijo
    if (code === queryNorm) return 0;
    if (code.indexOf(queryNorm) === 0) return 1;

    // Nombre exacto
    if (name === queryNorm) return 2;

    // Prefijo del nombre (lo más cercano al orden de escritura)
    if (name.indexOf(queryNorm) === 0) {
      return 3 + Math.min(40, Math.max(0, name.length - queryNorm.length));
    }

    // Prefijo de alguna palabra del nombre
    var words = name.split(' ');
    for (var i = 0; i < words.length; i++) {
      if (words[i].indexOf(queryNorm) === 0) {
        return 50 + i * 5 + Math.min(20, Math.max(0, words[i].length - queryNorm.length));
      }
    }

    // Contiene en el nombre
    var idx = name.indexOf(queryNorm);
    if (idx !== -1) {
      return 100 + idx + Math.min(30, Math.max(0, name.length - queryNorm.length));
    }

    // Contiene en el código
    if (code.indexOf(queryNorm) !== -1) return 200;

    return 9999;
  }

  function filterCountriesByQuery(countries, q) {
    var queryNorm = normalizeSearchText(q);
    var list = Array.isArray(countries) ? countries.slice() : [];
    if (!queryNorm) {
      return list.slice().sort(function (a, b) {
        return String(a.name || '').localeCompare(String(b.name || ''), 'es', { sensitivity: 'base' });
      });
    }
    var scored = [];
    for (var i = 0; i < list.length; i++) {
      var score = countrySearchScore(list[i], queryNorm);
      if (score < 9999) scored.push({ c: list[i], score: score });
    }
    scored.sort(function (a, b) {
      if (a.score !== b.score) return a.score - b.score;
      return String(a.c.name || '').localeCompare(String(b.c.name || ''), 'es', { sensitivity: 'base' });
    });
    return scored.map(function (row) { return row.c; });
  }

  function bindCountryPicker(picker) {
    if (!picker || picker.getAttribute('data-bound') === '1') return;
    picker.setAttribute('data-bound', '1');

    var trigger = picker.querySelector('[data-md-country-trigger]');
    var panel = picker.querySelector('[data-md-country-panel]');
    var search = picker.querySelector('[data-md-country-search]');
    var list = picker.querySelector('[data-md-country-list]');
    var countries = Array.isArray(Multidrop.shipping_countries) ? Multidrop.shipping_countries : [];

    function openPanel() {
      if (!panel) return;
      panel.classList.remove('md-hide');
      panel.removeAttribute('hidden');
      if (trigger) trigger.setAttribute('aria-expanded', 'true');
      renderCountryOptions((search && search.value) || '');
      if (search) setTimeout(function () { search.focus(); }, 0);
    }
    function closePanel() {
      if (!panel) return;
      panel.classList.add('md-hide');
      panel.setAttribute('hidden', 'hidden');
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }
    function renderCountryOptions(q) {
      if (!list) return;
      var filtered = filterCountriesByQuery(countries, q);
      if (!filtered.length) {
        list.innerHTML = '<li class="md-country-picker__empty">Sin resultados</li>';
        return;
      }
      list.innerHTML = filtered.map(function (c) {
        var flag = c.flag || countryFlagUrl(c.code);
        var fieldEl = picker.querySelector('[data-md-shipping-country-field]');
        var selected = fieldEl && fieldEl.value === c.code;
        return '<li><button type="button" class="md-country-picker__option' + (selected ? ' is-active' : '') + '" data-code="' + c.code + '" role="option">' +
          '<img src="' + flag + '" alt="" width="20" height="15" loading="lazy" decoding="async">' +
          '<span>' + (c.name || c.code) + '</span>' +
          '<span class="md-country-picker__option-code">' + c.code + '</span>' +
          '</button></li>';
      }).join('');
      list.querySelectorAll('[data-code]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          selectShippingCountry(btn.getAttribute('data-code'));
          closePanel();
        });
      });
    }

    if (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        if (panel && panel.classList.contains('md-hide')) openPanel();
        else closePanel();
      });
    }
    if (search) {
      search.setAttribute('autocomplete', 'off');
      search.setAttribute('autocapitalize', 'off');
      search.setAttribute('spellcheck', 'false');
      search.addEventListener('input', function () { renderCountryOptions(search.value); });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
        if (e.key === 'Enter') {
          e.preventDefault();
          var first = list && list.querySelector('[data-code]');
          if (first) {
            selectShippingCountry(first.getAttribute('data-code'));
            closePanel();
          }
        }
      });
    }
    document.addEventListener('click', function (e) {
      if (!picker.contains(e.target)) closePanel();
    });
  }

  function setCountryPickerDisplay(code) {
    var picker = document.querySelector('[data-md-country-picker]');
    if (!picker) return;
    code = String(code || '').toUpperCase();
    if (code === 'UK') code = 'GB';
    var countries = Array.isArray(Multidrop.shipping_countries) ? Multidrop.shipping_countries : [];
    var match = code ? countries.find(function (c) { return String(c.code).toUpperCase() === code; }) : null;
    var flag = picker.querySelector('[data-md-country-flag]');
    var label = picker.querySelector('[data-md-country-label]');
    var field = picker.querySelector('[data-md-shipping-country-field], [data-md-shipping-country]');
    if (field) field.value = match ? match.code : '';
    if (match) {
      if (flag) {
        flag.src = match.flag || countryFlagUrl(match.code);
        flag.classList.remove('md-hide');
        flag.removeAttribute('hidden');
      }
      if (label) label.textContent = match.name || match.code;
    } else {
      if (flag) {
        flag.removeAttribute('src');
        flag.classList.add('md-hide');
        flag.setAttribute('hidden', 'hidden');
      }
      if (label) label.textContent = mdT('checkout.pick_country_ellipsis');
    }
  }

  function selectShippingCountry(code) {
    code = normalizeCountryCode(code);
    if (!code) return;
    writeStoredShippingCountry(code);
    setCountryPickerDisplay(code);
    applyShippingCountry(code);
  }

  function checkoutLinesRoot() {
    return document.querySelector('[data-md-checkout-lines], [data-md-checkout-items]');
  }

  /** Fila del total: themes usan .md-checkout-summary__row o .md-summary__line / .md-summary__row */
  function checkoutTotalRowEl(scope) {
    var root = scope || document;
    var totalEl = root.querySelector('[data-md-checkout-total]');
    if (!totalEl) return null;
    return totalEl.closest(
      '[data-md-checkout-total-row], .md-checkout-summary__row, .md-summary__line--total, .md-summary__line, .md-summary__row--total, .md-summary__row'
    ) || totalEl.parentElement;
  }

  /** Marca spans de total en themes que no traen data-md-checkout-total. */
  function ensureCheckoutTotalHooks(summary) {
    if (!summary) return;
    if (summary.querySelector('[data-md-checkout-total]')) return;
    var rows = summary.querySelectorAll(
      '[data-md-checkout-total-row], .md-checkout-summary__row--total, .md-summary__line--total, .md-summary__row--total, .md-checkout-summary__row, .md-summary__line, .md-summary__row'
    );
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      if (row.querySelector('[data-md-checkout-subtotal], [data-md-checkout-shipping], [data-md-checkout-discount], [data-md-checkout-magic-discount], [data-md-checkout-combo-discount]')) continue;
      if (row.hasAttribute('data-md-checkout-shipping-row') || row.hasAttribute('data-md-checkout-discount-row') || row.hasAttribute('data-md-checkout-magic-row') || row.hasAttribute('data-md-checkout-combo-row')) continue;
      var label = row.querySelector('span:not(.md-price), dt, .label, strong');
      var t = String((label && label.textContent) || '').trim().toLowerCase();
      var isTotalClass = row.classList.contains('md-checkout-summary__row--total')
        || row.classList.contains('md-summary__line--total')
        || row.classList.contains('md-summary__row--total')
        || row.hasAttribute('data-md-checkout-total-row');
      if (!isTotalClass && t !== 'total' && !/^total\b/.test(t) && t !== 'totale' && t !== '合計') continue;
      var price = row.querySelector('.md-price, [data-money], strong:last-child, span:last-child');
      if (!price || price === label) continue;
      if (price.hasAttribute('data-md-checkout-subtotal') || price.hasAttribute('data-md-checkout-shipping')) continue;
      price.setAttribute('data-md-checkout-total', '1');
      return;
    }
  }

  function checkoutShippingCountry(cart) {
    cart = cart || (window.Multidrop && Multidrop.cart) || {};
    var fromCart = cart.shipping_country || (cart.shipping_info && cart.shipping_info.country) || '';
    return fromCart ? normalizeCountryCode(fromCart) : '';
  }

  function focusCheckoutCountryPicker() {
    var picker = document.querySelector('[data-md-country-picker]');
    if (picker) {
      picker.classList.add('is-needed');
      try { picker.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
      window.setTimeout(function () { picker.classList.remove('is-needed'); }, 2600);
    }
    var search = document.querySelector('[data-md-country-search], #md-shipping-country-search');
    if (search) {
      try { search.focus(); } catch (e2) {}
      var toggle = document.querySelector('[data-md-country-toggle], .md-country-picker__toggle');
      if (toggle && typeof toggle.click === 'function') {
        var list = document.querySelector('[data-md-country-list], .md-country-picker__list');
        if (list && list.hidden) toggle.click();
      }
    }
  }

  function bindPendingTotalClick(el) {
    if (!el || el._mdPendingTotalBound) return;
    el._mdPendingTotalBound = true;
    el.addEventListener('click', function () {
      if (!el.classList.contains('is-pending')) return;
      focusCheckoutCountryPicker();
    });
    el.addEventListener('keydown', function (ev) {
      if (!el.classList.contains('is-pending')) return;
      if (ev.key !== 'Enter' && ev.key !== ' ') return;
      ev.preventDefault();
      focusCheckoutCountryPicker();
    });
  }

  function setCheckoutTotalPending(pending) {
    var label = mdT('checkout.total_pending') || mdT('checkout.pick_country') || 'Select a country';
    document.querySelectorAll('[data-md-checkout-total]').forEach(function (el) {
      bindPendingTotalClick(el);
      el.classList.toggle('is-pending', !!pending);
      if (pending) {
        el.textContent = label;
        el.setAttribute('role', 'button');
        el.setAttribute('tabindex', '0');
        el.setAttribute('title', label);
      } else {
        el.removeAttribute('role');
        el.removeAttribute('tabindex');
        el.removeAttribute('title');
      }
      var row = el.closest(
        '[data-md-checkout-total-row], .md-checkout-summary__row--total, .md-summary__line--total, .md-summary__row--total, .md-checkout-summary__row, .md-summary__line, .md-summary__row'
      );
      if (row) row.classList.toggle('is-pending', !!pending);
    });
    document.querySelectorAll('[data-md-checkout-totals]').forEach(function (el) {
      el.classList.toggle('is-pending', !!pending);
    });
    var form = document.querySelector('[data-md-checkout-form], form.md-checkout-form, form#md-checkout-form');
    if (form) {
      form.querySelectorAll('button[type="submit"], [data-md-checkout-submit]').forEach(function (btn) {
        if (pending) {
          btn.disabled = true;
          btn.setAttribute('aria-disabled', 'true');
          btn.setAttribute('title', label);
        } else {
          btn.disabled = false;
          btn.removeAttribute('aria-disabled');
          btn.removeAttribute('title');
        }
      });
    }
    var picker = document.querySelector('[data-md-country-picker]');
    if (picker) picker.classList.toggle('is-awaiting-country', !!pending);
  }

  function insertBeforeCheckoutTotal(summary, node) {
    if (!summary || !node) return;
    var totalWrap = checkoutTotalRowEl(summary);
    if (totalWrap && totalWrap.parentNode) {
      totalWrap.parentNode.insertBefore(node, totalWrap);
      return;
    }
    summary.appendChild(node);
  }

  /** Si un theme dejó descuentos debajo del total, los reordena. */
  function reorderCheckoutSummaryRows(summary) {
    if (!summary) return;
    var totalWrap = checkoutTotalRowEl(summary);
    if (!totalWrap || !totalWrap.parentNode) return;
    ['data-md-checkout-combo-row', 'data-md-checkout-bundle-row', 'data-md-checkout-discount-row', 'data-md-checkout-magic-row', 'data-md-checkout-shipping-row'].forEach(function (attr) {
      var row = summary.querySelector('[' + attr + ']');
      if (!row || row.parentNode !== totalWrap.parentNode) return;
      // Si el row está después del total (o no justo antes en cadena de descuentos), moverlo antes del total
      if (totalWrap.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_FOLLOWING) {
        totalWrap.parentNode.insertBefore(row, totalWrap);
      }
    });
  }

  function ensureCheckoutSummaryScaffold() {
    // Form sin hook: aceptar id clásico de themes
    var form = document.querySelector('[data-md-checkout-form], form.md-checkout-form, form#md-checkout-form, .md-checkout form');
    if (form && !form.hasAttribute('data-md-checkout-form')) {
      form.setAttribute('data-md-checkout-form', '1');
      form.classList.add('md-checkout-form');
    }

    var summary = document.querySelector('[data-md-checkout-summary]');
    if (!summary) {
      var aside = document.querySelector('aside.md-checkout-summary, .md-checkout-summary');
      if (aside) {
        summary = document.createElement('div');
        summary.setAttribute('data-md-checkout-summary', '1');
        aside.appendChild(summary);
      }
    }
    if (!summary) return null;

    // Líneas de productos: alias data-md-checkout-items (usado por varios themes)
    var lines = checkoutLinesRoot();
    if (!lines) {
      lines = document.createElement('div');
      lines.setAttribute('data-md-checkout-lines', '1');
      lines.className = 'md-checkout-summary__lines';
      summary.parentNode && summary.parentNode.insertBefore(lines, summary);
    } else if (!lines.hasAttribute('data-md-checkout-lines')) {
      lines.setAttribute('data-md-checkout-lines', '1');
    }

    if (!summary.querySelector('[data-md-checkout-subtotal]')) {
      var sub = document.createElement('div');
      sub.className = 'md-checkout-summary__row';
      sub.innerHTML = '<span>Subtotal</span><span class="md-price" data-md-checkout-subtotal>—</span>';
      summary.insertBefore(sub, summary.firstChild);
    }
    ensureCheckoutTotalHooks(summary);
    if (!summary.querySelector('[data-md-checkout-total]')) {
      var tot = document.createElement('div');
      tot.className = 'md-checkout-summary__row md-checkout-summary__row--total';
      tot.innerHTML = '<span>Total</span><span class="md-price" data-md-checkout-total>—</span>';
      summary.appendChild(tot);
    }

    return summary;
  }

  function ensureCheckoutCouponUi() {
    ensureCheckoutSummaryScaffold();
    ensureCheckoutCountryPicker();

    var summary = document.querySelector('[data-md-checkout-summary]');
    if (!summary) return null;

    var coupon = summary.querySelector('[data-md-coupon-form]');
    if (!coupon) {
      coupon = document.createElement('div');
      coupon.className = 'md-checkout-summary__coupon md-checkout-summary__coupon--inline';
      coupon.setAttribute('data-md-coupon-form', '1');
      coupon.innerHTML =
        '<div class="md-checkout-summary__coupon-row">' +
          '<input type="text" name="code" placeholder="Cupón" autocomplete="off" aria-label="Código de cupón">' +
          '<button type="button" class="md-btn" data-md-coupon-apply>Aplicar</button>' +
          '<div class="md-checkout-summary__coupon-applied md-hide" data-md-checkout-coupon-applied hidden>' +
            '<strong data-md-checkout-coupon-code></strong>' +
            '<button type="button" data-md-coupon-clear title="Quitar cupón">×</button>' +
          '</div>' +
        '</div>' +
        '<p class="md-checkout-summary__coupon-msg" data-md-coupon-msg></p>';
    } else {
      coupon.classList.add('md-checkout-summary__coupon--inline');
      if (!coupon.querySelector('.md-checkout-summary__coupon-row .md-checkout-summary__coupon-applied') &&
          coupon.querySelector('[data-md-checkout-coupon-applied]')) {
        var row = coupon.querySelector('.md-checkout-summary__coupon-row');
        var applied = coupon.querySelector('[data-md-checkout-coupon-applied]');
        if (row && applied && applied.parentNode !== row) {
          applied.innerHTML = '<strong data-md-checkout-coupon-code></strong><button type="button" data-md-coupon-clear title="Quitar cupón">×</button>';
          row.appendChild(applied);
        }
      }
      var oldLabel = coupon.querySelector('.md-checkout-summary__coupon-label');
      if (oldLabel) oldLabel.remove();
    }

    if (!summary.querySelector('[data-md-checkout-combo-row]')) {
      var combo = document.createElement('div');
      combo.className = 'md-checkout-summary__row md-checkout-summary__row--discount md-hide';
      combo.setAttribute('data-md-checkout-combo-row', '1');
      combo.innerHTML = '<span data-md-checkout-combo-label></span><span class="md-price" data-md-checkout-combo-discount></span>';
      insertBeforeCheckoutTotal(summary, combo);
    }

    if (!summary.querySelector('[data-md-checkout-bundle-row]')) {
      var bundle = document.createElement('div');
      bundle.className = 'md-checkout-summary__row md-checkout-summary__row--discount md-hide';
      bundle.setAttribute('data-md-checkout-bundle-row', '1');
      bundle.innerHTML = '<span data-md-checkout-bundle-label></span><span class="md-price" data-md-checkout-bundle-discount></span>';
      insertBeforeCheckoutTotal(summary, bundle);
    }

    if (!summary.querySelector('[data-md-checkout-discount-row]')) {
      var disc = document.createElement('div');
      disc.className = 'md-checkout-summary__row md-checkout-summary__row--discount md-hide';
      disc.setAttribute('data-md-checkout-discount-row', '1');
      disc.innerHTML = '<span>' + mdT('checkout.discount') + '</span><span class="md-price" data-md-checkout-discount></span>';
      insertBeforeCheckoutTotal(summary, disc);
    }

    if (!summary.querySelector('[data-md-checkout-magic-row]')) {
      var magic = document.createElement('div');
      magic.className = 'md-checkout-summary__row md-checkout-summary__row--magic md-hide';
      magic.setAttribute('data-md-checkout-magic-row', '1');
      magic.innerHTML =
        '<span data-md-checkout-magic-label>' + mdT('checkout.magic') + '</span>' +
        '<span class="md-price" data-md-checkout-magic-discount></span>';
      insertBeforeCheckoutTotal(summary, magic);
    }

    var shipRow = summary.querySelector('[data-md-checkout-shipping-row]');
    if (!shipRow) {
      var rows = summary.querySelectorAll('.md-checkout-summary__row, .md-summary__line, .md-summary__row');
      rows.forEach(function (row) {
        var txt = (row.textContent || '').toLowerCase();
        if (txt.indexOf('shipping') !== -1 || txt.indexOf('envío') !== -1 || txt.indexOf('envio') !== -1) {
          if (!row.hasAttribute('data-md-checkout-discount-row') && !row.hasAttribute('data-md-checkout-magic-row') && !row.querySelector('[data-md-checkout-total]') && !row.querySelector('[data-md-checkout-subtotal]')) {
            shipRow = row;
          }
        }
      });
      if (shipRow) {
        shipRow.setAttribute('data-md-checkout-shipping-row', '1');
        shipRow.innerHTML = '<span>' + mdT('checkout.shipping') + ' <button type="button" class="md-ship-eta md-hide" data-md-ship-eta aria-label="Tiempo de entrega">ℹ</button></span><span class="md-price" data-md-checkout-shipping>' + mdT('checkout.pick_country') + '</span>';
      } else {
        shipRow = document.createElement('div');
        shipRow.className = 'md-checkout-summary__row';
        shipRow.setAttribute('data-md-checkout-shipping-row', '1');
        shipRow.innerHTML = '<span>' + mdT('checkout.shipping') + ' <button type="button" class="md-ship-eta md-hide" data-md-ship-eta aria-label="Tiempo de entrega">ℹ</button></span><span class="md-price" data-md-checkout-shipping>' + mdT('checkout.pick_country') + '</span>';
        insertBeforeCheckoutTotal(summary, shipRow);
      }
    }

    if (coupon.parentNode !== summary || coupon.nextElementSibling !== shipRow) {
      shipRow.parentNode.insertBefore(coupon, shipRow);
    }

    var checkoutForm = document.querySelector('[data-md-checkout-form], form.md-checkout-form');
    if (checkoutForm && !checkoutForm.querySelector('[name="country"]')) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'country';
      hidden.value = '';
      hidden.setAttribute('data-md-shipping-country-field', '1');
      checkoutForm.appendChild(hidden);
    }

    reorderCheckoutSummaryRows(summary);

    return coupon;
  }

  function crossSellOffer() {
    var cs = Multidrop.cross_sell || {};
    if (cs.offer) return cs.offer;
    if (Multidrop.cart && Multidrop.cart.cross_sell && Multidrop.cart.cross_sell.offer) {
      return Multidrop.cart.cross_sell.offer;
    }
    return null;
  }

  function cartProductKeys(cart) {
    var keys = {};
    ((cart && cart.items) || []).forEach(function (it) {
      var id = parseInt(it.product_id != null ? it.product_id : it.id, 10);
      if (id) keys['id:' + id] = true;
      var slug = String(it.slug || it.handle || '').toLowerCase().trim();
      if (slug) keys['slug:' + slug] = true;
      var raw = it.product_id;
      if (raw != null && String(raw) !== '' && !/^\d+$/.test(String(raw))) {
        keys['slug:' + String(raw).toLowerCase().trim()] = true;
      }
    });
    return keys;
  }

  function crossSellProducts(cart) {
    cart = cart || Multidrop.cart || {};
    var list = [];
    if (cart.cross_sell && Array.isArray(cart.cross_sell.products)) {
      list = cart.cross_sell.products.slice();
    } else if (Multidrop.cross_sell && Array.isArray(Multidrop.cross_sell.products)) {
      list = Multidrop.cross_sell.products.slice();
    }
    var inCart = cartProductKeys(cart);
    return list.filter(function (p) {
      var id = parseInt(p.product_id != null ? p.product_id : p.id, 10);
      if (id && inCart['id:' + id]) return false;
      var slug = String(p.slug || p.handle || '').toLowerCase().trim();
      if (slug && inCart['slug:' + slug]) return false;
      return true;
    });
  }

  function ensureCheckoutCrossSellUi() {
    var mods = Multidrop.modules || {};
    var offer = crossSellOffer();
    var form = document.querySelector('[data-md-checkout-form], form.md-checkout-form');
    var summary = document.querySelector('[data-md-checkout-summary]');
    var layout = document.querySelector('.md-checkout-layout');
    var existing = document.querySelector('[data-md-cross-checkout]');
    var onCheckout = !!(form || summary || layout || (Multidrop.page && String(Multidrop.page.handle || Multidrop.page.type || '').indexOf('checkout') !== -1));

    if (!mods.cross_sell || !offer || !offer.enabled || !onCheckout) {
      if (existing) {
        existing.classList.add('md-hide');
        existing.setAttribute('hidden', 'hidden');
      }
      return null;
    }

    var panel = existing;
    if (!panel) {
      panel = document.createElement('aside');
      panel.className = 'md-mod-cross-checkout';
      panel.setAttribute('data-md-cross-checkout', '1');
    }
    // Markup compacto (migra layouts viejos)
    if (!panel.querySelector('[data-md-cross-timer]') || !panel.querySelector('.md-mod-cross-checkout__top')) {
      panel.innerHTML =
        '<div class="md-mod-cross-checkout__top">' +
          '<div class="md-mod-cross-checkout__intro">' +
            '<span class="md-mod-cross-checkout__badge" data-md-cross-badge></span>' +
            '<strong class="md-mod-cross-checkout__title" data-md-cross-headline></strong>' +
            '<span class="md-mod-cross-checkout__hint" data-md-cross-hint></span>' +
          '</div>' +
          '<div class="md-mod-cross-checkout__timer" data-md-cross-timer aria-live="polite">' +
            '<span class="md-mod-cross-checkout__timer-label">Expira en</span>' +
            '<b data-md-cross-countdown>--</b>' +
          '</div>' +
        '</div>' +
        '<div class="md-mod-cross-checkout__list" data-md-cross-list></div>';
    }

    // Ancho completo encima de Contact + Order summary
    if (!layout && (form || summary)) {
      layout = (form && form.parentElement) || (summary && summary.parentElement);
      if (layout && !layout.classList.contains('md-checkout-layout')) {
        layout.classList.add('md-checkout-layout');
      }
    }
    if (layout) {
      if (panel.parentNode !== layout || layout.firstElementChild !== panel) {
        layout.insertBefore(panel, layout.firstChild);
      }
    } else if (form && panel.parentNode !== form) {
      form.parentNode ? form.parentNode.insertBefore(panel, form) : form.insertBefore(panel, form.firstChild);
    }

    // Limpiar wrapper lateral legacy
    var side = document.querySelector('[data-md-checkout-side]');
    if (side && summary && side.contains(summary)) {
      side.parentNode.insertBefore(summary, side);
      side.remove();
    }

    panel.classList.remove('md-hide');
    panel.removeAttribute('hidden');
    var badge = panel.querySelector('[data-md-cross-badge]');
    var title = panel.querySelector('[data-md-cross-headline]');
    var hint = panel.querySelector('[data-md-cross-hint]');
    if (badge) badge.textContent = offer.badge || '✨ Oferta mágica';
    if (title) title.textContent = offer.headline || 'Completa tu pedido';
    if (hint) {
      var hintText = offer.hint_display || offer.hint || offer.subtitle || '';
      hint.textContent = hintText;
      hint.classList.toggle('md-hide', !String(hintText).trim());
    }
    startCrossSellCountdown(panel, offer);

    return panel;
  }

  function crossSellExpireKey() {
    return 'md_cross_expire_' + ((Multidrop.store && (Multidrop.store.id || Multidrop.store.slug)) || 'sb');
  }

  function startCrossSellCountdown(panel, offer) {
    if (!panel) return;
    var el = panel.querySelector('[data-md-cross-countdown]');
    var timerBox = panel.querySelector('[data-md-cross-timer]');
    if (!el) return;
    var mins = Math.max(3, parseInt(offer.expires_minutes, 10) || 15);
    var key = crossSellExpireKey();
    var endsAt = 0;
    try {
      endsAt = parseInt(sessionStorage.getItem(key) || '0', 10) || 0;
    } catch (e) {}
    var now = Date.now();
    if (!endsAt || endsAt <= now) {
      endsAt = now + mins * 60 * 1000;
      try { sessionStorage.setItem(key, String(endsAt)); } catch (e2) {}
    }

    function fmt(ms) {
      var s = Math.max(0, Math.floor(ms / 1000));
      var m = Math.floor(s / 60);
      var sec = s % 60;
      return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }
    function tick() {
      var left = endsAt - Date.now();
      if (left <= 0) {
        el.textContent = '00:00';
        if (timerBox) timerBox.classList.add('is-expired');
        panel.classList.add('is-expired');
        return;
      }
      el.textContent = fmt(left);
      if (timerBox) timerBox.classList.toggle('is-urgent', left < 60 * 1000);
    }
    tick();
    if (panel._mdCrossTimer) clearInterval(panel._mdCrossTimer);
    panel._mdCrossTimer = setInterval(tick, 1000);
  }

  function renderCheckoutCrossSell(cart) {
    var panel = ensureCheckoutCrossSellUi();
    if (!panel) return;
    var offer = crossSellOffer() || {};
    var list = panel.querySelector('[data-md-cross-list]');
    if (!list) return;
    var products = crossSellProducts(cart);
    if (Multidrop.cross_sell) {
      Multidrop.cross_sell.products = products;
      if (cart && cart.cross_sell && cart.cross_sell.offer) {
        Multidrop.cross_sell.offer = cart.cross_sell.offer;
      }
    }

    if (!products.length) {
      list.innerHTML = '<p class="md-mod-cross-checkout__empty">Ya agregaste los complementos ✨</p>';
      return;
    }

    var expired = panel.classList.contains('is-expired');
    list.innerHTML = products.map(function (p) {
      var id = p.product_id || p.id || '';
      var img = p.image
        ? '<img src="' + p.image + '" alt="" class="md-mod-cross-checkout__img" loading="lazy">'
        : '<div class="md-mod-cross-checkout__img md-mod-cross-checkout__img--ph" aria-hidden="true"></div>';
      var save = p.magic_save_formatted || formatCheckoutMoney(p.magic_save);
      return (
        '<div class="md-mod-cross-checkout__card">' +
          img +
          '<div class="md-mod-cross-checkout__meta">' +
            '<div class="md-mod-cross-checkout__name" title="' + String(p.name || 'Producto').replace(/"/g, '&quot;') + '">' + (p.name || 'Producto') + '</div>' +
            '<div class="md-mod-cross-checkout__prices">' +
              '<span class="md-mod-cross-checkout__was">' + (p.price_formatted || formatCheckoutMoney(p.price)) + '</span>' +
              '<span class="md-mod-cross-checkout__now">' + (p.magic_price_formatted || formatCheckoutMoney(p.magic_price)) + '</span>' +
              '<span class="md-mod-cross-checkout__save">−' + save + '</span>' +
            '</div>' +
          '</div>' +
          '<button type="button" class="md-btn md-mod-cross-checkout__cta" data-md-cross-add data-product-id="' + id + '"' +
            (expired ? ' disabled' : '') + '>' +
            (offer.cta || 'Agregar') +
          '</button>' +
        '</div>'
      );
    }).join('');

    list.querySelectorAll('[data-md-cross-add]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var pid = parseInt(btn.getAttribute('data-product-id'), 10);
        if (!pid) return;
        btn.disabled = true;
        var url = Multidrop.urls && Multidrop.urls.cart_cross_sell;
        if (useApi && url) {
          api(url, 'POST', { product_id: pid, qty: 1 }).then(function (res) {
            if (res.cart) applyCart(res.cart);
            btn.disabled = false;
            if (!res.ok) {
              alert(res.message || 'No se pudo agregar');
            }
          }).catch(function () {
            btn.disabled = false;
            alert('Error de red');
          });
        } else {
          btn.disabled = false;
        }
      });
    });
  }

  function resolveCountryCode(raw) {
    var q = String(raw || '').trim();
    if (!q) return '';
    var countries = Array.isArray(Multidrop.shipping_countries) ? Multidrop.shipping_countries : [];
    var up = q.toUpperCase();
    for (var i = 0; i < countries.length; i++) {
      if (String(countries[i].code).toUpperCase() === up) return countries[i].code;
    }
    var ranked = filterCountriesByQuery(countries, q);
    if (ranked.length) return ranked[0].code;
    return up.length === 2 ? up : '';
  }

  function applyShippingCountry(code) {
    if (!useApi || !Multidrop.urls || !Multidrop.urls.cart_shipping) return;
    code = normalizeCountryCode(code);
    var msg = document.querySelector('[data-md-shipping-msg]');
    if (!code) {
      if (msg) { msg.textContent = mdT('checkout.choose_country'); msg.classList.add('is-error'); }
      setCheckoutTotalPending(true);
      return;
    }
    var sessionCountry = checkoutShippingCountry(Multidrop.cart);
    if (sessionCountry === code) {
      setCountryPickerDisplay(code);
      return;
    }
    if (mdShippingQuoteInflight === code) return;
    mdShippingQuoteInflight = code;
    if (msg) { msg.textContent = mdT('checkout.quoting'); msg.classList.remove('is-error'); }
    document.querySelectorAll('[data-md-checkout-total]').forEach(function (el) {
      el.classList.add('is-pending');
      el.textContent = mdT('checkout.quoting');
    });
    document.querySelectorAll('[data-md-checkout-shipping]').forEach(function (el) {
      el.textContent = mdT('checkout.quoting');
    });
    api(Multidrop.urls.cart_shipping, 'POST', { country: code }).then(function (res) {
      if (mdShippingQuoteInflight === code) mdShippingQuoteInflight = '';
      if (res.cart) applyCart(res.cart);
      if (msg) {
        msg.textContent = res.ok ? '' : (res.message || mdT('checkout.quote_error'));
        msg.classList.toggle('is-error', !res.ok);
      }
      var field = document.querySelector('[data-md-shipping-country-field], [name="country"]');
      if (field && res.ok) field.value = code;
      if (res.ok) {
        writeStoredShippingCountry(code);
        setCountryPickerDisplay(code);
      }
      if (!res.ok) setCheckoutTotalPending(true);
    }).catch(function () {
      if (mdShippingQuoteInflight === code) mdShippingQuoteInflight = '';
      if (msg) { msg.textContent = mdT('checkout.quote_error'); msg.classList.add('is-error'); }
      setCheckoutTotalPending(true);
    });
  }

  function applyShippingFromInput(input) {
    var code = resolveCountryCode(input && input.value);
    if (code) selectShippingCountry(code);
  }

  function checkoutLineHtml(item, index) {
    var id = item.product_id || item.id || '';
    var vid = item.variant_id != null ? item.variant_id : '';
    var qty = Number(item.qty) || 1;
    var unit = Number(item.price) || 0;
    var line = Number(item.line_total != null ? item.line_total : (unit * qty));
    var img = item.image
      ? '<img src="' + String(item.image).replace(/"/g, '&quot;') + '" alt="" loading="lazy">'
      : '<span class="md-checkout-summary__line-ph" aria-hidden="true"></span>';
    var name = String(item.name || 'Producto');
    var badge = item.upsell_combo
      ? '<span class="md-checkout-summary__badge" style="display:inline-block;margin-left:6px;font-size:10px;font-weight:800;letter-spacing:.02em;padding:2px 6px;border-radius:999px;background:color-mix(in srgb,var(--md-checkout-primary,#0f766e) 18%,transparent);color:var(--md-checkout-primary,#0f766e);vertical-align:middle;">COMBO</span>'
      : (item.cross_sell_magic
        ? '<span class="md-checkout-summary__badge" style="display:inline-block;margin-left:6px;font-size:10px;font-weight:800;letter-spacing:.02em;padding:2px 6px;border-radius:999px;background:#fef3c7;color:#92400e;vertical-align:middle;">MAGIC</span>'
        : '');
    return '' +
      '<div class="md-checkout-summary__line" data-cart-row="' + id + '" data-md-cart-row="' + id + '" data-product-id="' + id + '" data-variant-id="' + vid + '" data-cart-line-index="' + index + '">' +
        img +
        '<div class="md-checkout-summary__line-main">' +
          '<span class="md-checkout-summary__line-name">' + name + badge + '</span>' +
          '<div class="md-checkout-summary__line-actions">' +
            '<div class="md-qty md-checkout-summary__qty" data-md-cart-qty-wrap>' +
              '<button type="button" data-md-cart-qty-minus aria-label="Restar">−</button>' +
              '<input type="number" min="0" step="1" value="' + qty + '" data-md-cart-qty aria-label="Cantidad">' +
              '<button type="button" data-md-cart-qty-plus aria-label="Sumar">+</button>' +
            '</div>' +
            '<button type="button" class="md-checkout-summary__remove md-cart-row__remove" data-md-cart-remove="' + id + '" data-cart-remove="' + id + '" data-product-id="' + id + '" data-variant-id="' + vid + '" data-cart-line-index="' + index + '" aria-label="' + mdT('cart.remove') + '">' + mdT('cart.remove') + '</button>' +
          '</div>' +
        '</div>' +
        '<span class="md-price">' + (item.line_total_formatted || formatCheckoutMoney(line)) + '</span>' +
      '</div>';
  }

  function renderCheckoutLines(cart) {
    var linesEl = checkoutLinesRoot();
    if (!linesEl) return;
    var items = (cart && cart.items) ? cart.items : [];
    if (!items.length) {
      var catalog = (Multidrop.urls && Multidrop.urls.catalog) || '#';
      linesEl.innerHTML = '<p class="md-checkout-summary__empty" style="font-size:13px;color:#64748b;">Tu carrito está vacío — <a href="' + catalog + '" style="color:var(--md-checkout-primary,#0f766e)">ver catálogo</a>.</p>';
      return;
    }
    linesEl.innerHTML = items.map(function (it, idx) { return checkoutLineHtml(it, idx); }).join('');
  }

  function syncCheckoutSummary(cart) {
    ensureCheckoutCouponUi();
    cart = cart || Multidrop.cart || {};
    var items = cart.items || [];
    var totals = cart.totals || {};
    var subtotal = totals.subtotal != null ? Number(totals.subtotal) : items.reduce(function (s, it) {
      return s + (Number(it.price) || 0) * (Number(it.qty) || 1);
    }, 0);
    var discount = Number(totals.discount || 0);
    var comboDiscount = Number(totals.combo_discount || 0);
    var comboPct = totals.combo_percent != null ? Number(totals.combo_percent) : 20;
    var bundleDiscount = Number(totals.bundle_discount || 0);
    var bundleLabel = totals.bundle_label || 'Combo';
    var magicDiscount = Number(totals.magic_discount || (cart.cross_sell && cart.cross_sell.magic_discount) || 0);
    var shipping = Number(totals.shipping || 0);
    var total = totals.total != null ? Number(totals.total) : Math.max(0, subtotal - discount - magicDiscount + shipping);
    var couponCode = cart.coupon || (cart.coupon_info && cart.coupon_info.code) || '';
    var shipCountry = checkoutShippingCountry(cart);
    var magicLabel = (cart.cross_sell && cart.cross_sell.label) || (crossSellOffer() && crossSellOffer().badge) || mdT('checkout.magic');
    if (!(comboDiscount > 0)) {
      items.forEach(function (it) {
        var unit = Number(it.price) || 0;
        var was = Number(it.compare_at) || 0;
        var qty = Math.max(1, parseInt(it.qty, 10) || 1);
        if (it.upsell_combo && was > unit) comboDiscount += (was - unit) * qty;
      });
      comboDiscount = Math.round(comboDiscount * 100) / 100;
    }
    var listSubtotal = totals.subtotal_list != null ? Number(totals.subtotal_list) : (subtotal + comboDiscount);

    renderCheckoutLines(cart);
    ensureCheckoutTotalHooks(document.querySelector('[data-md-checkout-summary]'));

    document.querySelectorAll('[data-md-checkout-subtotal]').forEach(function (el) {
      el.textContent = formatCheckoutMoney(comboDiscount > 0 ? listSubtotal : subtotal);
    });
    document.querySelectorAll('[data-md-checkout-combo-row]').forEach(function (el) {
      var on = comboDiscount > 0;
      el.classList.toggle('md-hide', !on);
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
      var lab = el.querySelector('[data-md-checkout-combo-label]');
      if (lab) lab.textContent = mdT('cart.combo_discount', { pct: (comboPct || 20) + '%' });
      var val = el.querySelector('[data-md-checkout-combo-discount]');
      if (val) val.textContent = '−' + formatCheckoutMoney(comboDiscount);
    });
    document.querySelectorAll('[data-md-checkout-bundle-row]').forEach(function (el) {
      var on = bundleDiscount > 0;
      el.classList.toggle('md-hide', !on);
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
      var lab = el.querySelector('[data-md-checkout-bundle-label]');
      if (lab) lab.textContent = 'Ahorro combo' + (bundleLabel ? ' · ' + bundleLabel : '');
      var val = el.querySelector('[data-md-checkout-bundle-discount]');
      if (val) val.textContent = '−' + formatCheckoutMoney(bundleDiscount);
    });
    document.querySelectorAll('[data-md-checkout-discount]').forEach(function (el) {
      el.textContent = discount > 0 ? ('−' + formatCheckoutMoney(discount)) : formatCheckoutMoney(0);
    });
    document.querySelectorAll('[data-md-checkout-magic-discount]').forEach(function (el) {
      el.textContent = magicDiscount > 0 ? ('−' + formatCheckoutMoney(magicDiscount)) : formatCheckoutMoney(0);
    });
    document.querySelectorAll('[data-md-checkout-magic-label]').forEach(function (el) {
      el.textContent = magicLabel;
    });
    document.querySelectorAll('[data-md-checkout-shipping]').forEach(function (el) {
      if (!shipCountry) el.textContent = mdT('checkout.pick_country');
      else el.textContent = formatCheckoutMoney(shipping);
    });
    (function paintEta() {
      var eta = (cart.shipping_info && (cart.shipping_info.eta_label || cart.shipping_info.eta)) || '';
      if (!eta && shipCountry && Array.isArray(Multidrop.shipping_countries)) {
        var c = Multidrop.shipping_countries.find(function (x) { return String(x.code).toUpperCase() === String(shipCountry).toUpperCase(); });
        eta = c && c.eta_label;
      }
      var tip = typeof eta === 'string' ? eta : (eta && eta.min ? (eta.min + '–' + eta.max + ' días hábiles aprox.') : '');
      document.querySelectorAll('[data-md-ship-eta]').forEach(function (btn) {
        if (shipCountry && tip) {
          btn.classList.remove('md-hide');
          btn.setAttribute('data-tip', tip);
          btn.setAttribute('title', tip);
        } else {
          btn.classList.add('md-hide');
        }
      });
    })();
    if (!shipCountry) {
      setCheckoutTotalPending(true);
    } else {
      setCheckoutTotalPending(false);
      document.querySelectorAll('[data-md-checkout-total]').forEach(function (el) {
        el.textContent = formatCheckoutMoney(total);
      });
    }
    document.querySelectorAll('[data-md-checkout-discount-row]').forEach(function (el) {
      el.classList.toggle('md-hide', !(discount > 0));
      if (discount > 0) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
    });
    document.querySelectorAll('[data-md-checkout-magic-row]').forEach(function (el) {
      el.classList.toggle('md-hide', !(magicDiscount > 0));
      if (magicDiscount > 0) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
    });

    maybeAutoSelectShippingCountry();

    document.querySelectorAll('[data-md-coupon-form]').forEach(function (wrap) {
      var applied = wrap.querySelector('[data-md-checkout-coupon-applied]');
      var codeEl = wrap.querySelector('[data-md-checkout-coupon-code]');
      var input = wrap.querySelector('input[name="code"]');
      var applyBtn = wrap.querySelector('[data-md-coupon-apply]');
      if (couponCode) {
        if (codeEl) codeEl.textContent = couponCode;
        if (applied) {
          applied.classList.remove('md-hide');
          applied.removeAttribute('hidden');
        }
        if (input) {
          input.value = couponCode;
          input.disabled = true;
          input.classList.add('md-hide');
        }
        if (applyBtn) applyBtn.classList.add('md-hide');
      } else {
        if (applied) {
          applied.classList.add('md-hide');
          applied.setAttribute('hidden', 'hidden');
        }
        if (input) {
          input.disabled = false;
          input.classList.remove('md-hide');
        }
        if (applyBtn) applyBtn.classList.remove('md-hide');
      }
    });

    renderCheckoutCrossSell(cart);
  }
  function csrfHeaders() {
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': Multidrop.csrf || '',
      'X-Requested-With': 'XMLHttpRequest'
    };
  }
  function api(url, method, body) {
    return fetch(url, {
      method: method || 'GET',
      headers: csrfHeaders(),
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
  }

  function setMdHidden(el, hidden) {
    if (!el) return;
    el.classList.toggle('md-hide', !!hidden);
    if (hidden) el.setAttribute('hidden', 'hidden');
    else el.removeAttribute('hidden');
  }

  function cartProductUrl(it) {
    var url = String((it && (it.url || it.product_url)) || '').trim();
    if (url && url !== '#') return url;
    var slug = String((it && (it.slug || it.handle)) || '').trim();
    var base = (Multidrop.urls && (Multidrop.urls.home || Multidrop.urls.catalog)) || '';
    if (slug && base) {
      var root = String(base).replace(/\/pages\/[^/]+\/?$/, '').replace(/\/$/, '');
      return root + '/pages/' + encodeURIComponent(slug);
    }
    var id = parseInt((it && (it.product_id || it.id)) || 0, 10);
    if (id && Array.isArray(Multidrop.products)) {
      for (var i = 0; i < Multidrop.products.length; i++) {
        var p = Multidrop.products[i] || {};
        if (Number(p.id) === id && p.url) return String(p.url);
        if (Number(p.id) === id && (p.slug || p.handle) && base) {
          var root2 = String(base).replace(/\/pages\/[^/]+\/?$/, '').replace(/\/$/, '');
          return root2 + '/pages/' + encodeURIComponent(p.slug || p.handle);
        }
      }
    }
    return '';
  }

  function cartRowHtml(it, index) {
    var id = it.product_id || it.id || '';
    var vid = it.variant_id != null ? it.variant_id : '';
    var qty = Math.max(1, parseInt(it.qty, 10) || 1);
    var unit = Number(it.price) || 0;
    var lineTotal = unit * qty;
    var href = cartProductUrl(it);
    var safeHref = href.replace(/"/g, '&quot;');
    var imgInner = it.image
      ? '<img src="' + String(it.image).replace(/"/g, '&quot;') + '" alt="" loading="lazy">'
      : '<div style="width:76px;height:76px;border-radius:12px;background:#1e293b"></div>';
    var img = href
      ? '<a class="md-cart-line__media" href="' + safeHref + '" data-md-cart-product-link>' + imgInner + '</a>'
      : '<div class="md-cart-line__media">' + imgInner + '</div>';
    var name = String(it.name || '').replace(/</g, '&lt;');
    var nameHtml = href
      ? '<a class="md-cart-line__link" href="' + safeHref + '" data-md-cart-product-link><strong>' + name + '</strong></a>'
      : '<strong>' + name + '</strong>';
    var comboPct = it.upsell_combo ? (Number(it.upsell_percent) || 0) : 0;
    var was = it.compare_at && Number(it.compare_at) > unit ? Number(it.compare_at) : 0;
    var comboBadge = '';
    if (it.combo_id) comboBadge = ' <span style="font-size:10px;font-weight:800;color:var(--md-primary,#7c5cff)">Combo</span>';
    else if (it.upsell_combo) comboBadge = ' <span style="font-size:10px;font-weight:800;color:var(--md-primary,#7c5cff)">' + mdT('cart.combo_badge') + (comboPct ? ' −' + comboPct + '%' : '') + '</span>';
    else if (it.cross_sell) comboBadge = ' <span style="font-size:10px;font-weight:800;color:var(--md-primary,#7c5cff)">Cross-sell</span>';
    return '' +
      '<div class="md-cart-line md-cart-row" data-cart-row="' + id + '" data-md-cart-row="' + id + '" data-product-id="' + id + '" data-variant-id="' + vid + '" data-cart-line-index="' + index + '">' +
        img +
        '<div class="md-cart-line__info">' +
          '<div class="md-cart-line__name">' + nameHtml + comboBadge +
          '</div>' +
          '<div class="md-cart-line__price">' +
            (was ? '<s style="opacity:.6;margin-right:6px">' + money(was) + '</s>' : '') +
            '<strong>' + money(unit) + '</strong> ' + mdT('cart.each') + ' · <span data-md-cart-line-total>' + money(lineTotal) + '</span>' +
          '</div>' +
          '<div class="md-qty" data-md-cart-qty-wrap>' +
            '<button type="button" data-md-cart-qty-minus aria-label="Restar">−</button>' +
            '<input type="number" min="0" step="1" value="' + qty + '" data-md-cart-qty aria-label="Cantidad">' +
            '<button type="button" data-md-cart-qty-plus aria-label="Sumar">+</button>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="md-btn md-btn--ghost md-cart-row__remove" data-md-cart-remove="' + id + '" data-cart-remove="' + id + '" data-product-id="' + id + '" data-variant-id="' + vid + '" data-cart-line-index="' + index + '" aria-label="' + mdT('cart.remove') + '">' + mdT('cart.remove') + '</button>' +
      '</div>';
  }

  function cartRowIdFromEl(el) {
    var meta = cartLineMetaFromEl(el);
    return meta ? meta.product_id : 0;
  }

  function cartLineMetaFromEl(el) {
    if (!el) return null;
    var row = el.closest('[data-cart-row], [data-md-cart-row]');
    if (!row) return null;
    var productId = parseInt(row.getAttribute('data-product-id') || row.getAttribute('data-cart-row') || row.getAttribute('data-md-cart-row') || '0', 10) || 0;
    var variantId = parseInt(row.getAttribute('data-variant-id') || '0', 10) || 0;
    var lineIndex = parseInt(row.getAttribute('data-cart-line-index') || '-1', 10);
    return {
      row: row,
      product_id: productId,
      variant_id: variantId,
      line_index: lineIndex >= 0 ? lineIndex : null
    };
  }

  function cartLineOptsFromEl(el) {
    var meta = cartLineMetaFromEl(el);
    if (!meta) return {};
    var opts = {};
    if (meta.variant_id > 0) opts.variant_id = meta.variant_id;
    if (meta.line_index != null) opts.line_index = meta.line_index;
    return opts;
  }

  function applyCartQtyFromControl(el, nextQty) {
    var meta = cartLineMetaFromEl(el);
    if (!meta || !meta.product_id) return;
    nextQty = parseInt(nextQty, 10);
    if (isNaN(nextQty)) return;
    var opts = cartLineOptsFromEl(el);
    if (nextQty < 1) {
      Multidrop.Cart.remove(meta.product_id, opts);
      return;
    }
    Multidrop.Cart.update(meta.product_id, nextQty, opts);
  }

  function checkoutUrl() {
    return (Multidrop.urls && Multidrop.urls.checkout) || '#';
  }

  function findCheckoutCta() {
    var byHook = document.querySelector('[data-md-cart-checkout]');
    if (byHook) return byHook;
    var url = checkoutUrl();
    if (!url || url === '#') return null;
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href') || '';
      if (href === url || /\/pages\/checkout\/?$/.test(href) || /\/checkout\/?$/.test(href)) {
        return links[i];
      }
    }
    return null;
  }

  function ensureCheckoutCta(root, hasItems) {
    var url = checkoutUrl();
    var cta = findCheckoutCta();
    if (!cta && hasItems && root) {
      cta = document.createElement('a');
      cta.className = 'md-btn md-btn--primary md-btn--block';
      cta.setAttribute('data-md-cart-checkout', '1');
      cta.textContent = mdT('cart.checkout');
      cta.style.cssText = 'display:inline-block;margin-top:16px;background:var(--md-checkout-button,#0f766e);color:#fff;text-decoration:none;padding:12px 18px;border-radius:12px;font-weight:700;text-align:center;';
      var summary = document.querySelector('[data-md-cart-summary]');
      (summary || root).appendChild(cta);
    }
    if (!cta) return;
    if (cta.tagName === 'A' && url && url !== '#') cta.setAttribute('href', url);
    setMdHidden(cta, !hasItems);
    if (hasItems) cta.style.display = '';
  }

  function ensureCartSummaryDiscounts(summary) {
    if (!summary) return;
    var totalEl = summary.querySelector('[data-md-cart-total]');
    var totalWrap = totalEl ? (totalEl.closest('.md-cart-summary__row, .md-summary__row, p, div') || totalEl.parentNode) : null;
    function ensureRow(attr, html) {
      var row = summary.querySelector('[' + attr + ']');
      if (row) return row;
      row = document.createElement('div');
      row.className = 'md-cart-summary__row md-cart-summary__row--discount';
      row.setAttribute(attr, '1');
      row.innerHTML = html;
      if (totalWrap && totalWrap.parentNode) totalWrap.parentNode.insertBefore(row, totalWrap);
      else summary.appendChild(row);
      return row;
    }
    ensureRow('data-md-cart-combo-row',
      '<span data-md-cart-combo-label></span><span class="md-price" data-md-cart-combo-discount></span>');
    ensureRow('data-md-cart-bundle-row',
      '<span data-md-cart-bundle-label></span><span class="md-price" data-md-cart-bundle-discount></span>');
    ensureRow('data-md-cart-discount-row',
      '<span data-md-cart-discount-label></span><span class="md-price" data-md-cart-discount></span>');
    ensureRow('data-md-cart-magic-row',
      '<span data-md-cart-magic-label></span><span class="md-price" data-md-cart-magic-discount></span>');
  }

  function localizeCartSummary(summary) {
    if (!summary) return;
    var head = summary.querySelector('h1, h2, h3, .md-h3, .md-h2');
    if (head) {
      var ht = String(head.textContent || '').trim().toLowerCase();
      if (ht === '' || /order summary|resumen|resumo/.test(ht)) head.textContent = mdT('cart.summary');
    }
    summary.querySelectorAll('.md-cart-summary__row, .md-summary__row').forEach(function (row) {
      if (row.hasAttribute('data-md-cart-combo-row') || row.hasAttribute('data-md-cart-discount-row') || row.hasAttribute('data-md-cart-magic-row')) return;
      var label = row.querySelector('span:not(.md-price)');
      if (!label) return;
      var t = String(label.textContent || '').trim().toLowerCase();
      if (t === 'subtotal') label.textContent = mdT('cart.subtotal');
      else if (t === 'total') label.textContent = mdT('cart.total');
      else if (/^shipping|^env[ií]o|^frete/.test(t)) label.textContent = mdT('cart.shipping');
      var val = row.querySelector('span.md-price, span:last-child');
      if (val && /calculated at checkout|se calcula|calculado no checkout/.test(String(val.textContent || '').toLowerCase())) {
        val.textContent = mdT('cart.shipping_later');
      }
    });
    var cta = summary.querySelector('[data-md-cart-checkout], .md-btn--primary');
    if (cta && /checkout/.test(String(cta.textContent || '').toLowerCase())) {
      cta.textContent = mdT('cart.checkout');
    }
  }

  function renderCart() {
    var root = document.querySelector('[data-md-cart]');
    if (!root) return;
    var items = (Multidrop.cart && Multidrop.cart.items) ? Multidrop.cart.items : [];
    var hasItems = items.length > 0;
    var itemsRoot = root.querySelector('[data-md-cart-items], [data-md-cart-lines]');
    var emptyEl = root.querySelector('[data-md-cart-empty]') || document.querySelector('[data-md-cart-empty]');
    var summaryEl = root.querySelector('[data-md-cart-summary], .md-summary, aside.md-summary') || document.querySelector('[data-md-cart-summary]');
    var structured = !!(itemsRoot || emptyEl || summaryEl);

    if (structured) {
      setMdHidden(emptyEl, hasItems);
      if (emptyEl) {
        if (hasItems) emptyEl.removeAttribute('data-active');
        else emptyEl.setAttribute('data-active', '1');
      }
      if (itemsRoot) setMdHidden(itemsRoot, !hasItems);
      if (summaryEl && summaryEl !== root) setMdHidden(summaryEl, !hasItems);
      var target = itemsRoot || root;
      if (!hasItems) {
        if (itemsRoot) itemsRoot.innerHTML = '';
      } else {
        target.innerHTML = items.map(function (it, idx) { return cartRowHtml(it, idx); }).join('');
      }
    } else if (!hasItems) {
      root.innerHTML = '<p>' + mdT('cart.empty') + '</p>';
    } else {
      root.innerHTML = items.map(function (it, idx) { return cartRowHtml(it, idx); }).join('');
    }

    var totals = (Multidrop.cart && Multidrop.cart.totals) || {};
    var comboDiscount = Number(totals.combo_discount || 0);
    var couponDiscount = Number(totals.discount || 0);
    var magicDiscount = Number(totals.magic_discount || 0);
    var bundleDiscount = Number(totals.bundle_discount || 0);
    var bundleLabel = totals.bundle_label || 'Combo';
    var comboPct = totals.combo_percent != null ? Number(totals.combo_percent) : 0;
    if (!(comboDiscount > 0)) {
      items.forEach(function (it) {
        var unit = Number(it.price) || 0;
        var was = Number(it.compare_at) || 0;
        var qty = Math.max(1, parseInt(it.qty, 10) || 1);
        if (it.upsell_combo && was > unit) {
          comboDiscount += (was - unit) * qty;
          if (!comboPct) comboPct = Number(it.upsell_percent) || 20;
        }
      });
      comboDiscount = Math.round(comboDiscount * 100) / 100;
    }
    var subtotal = totals.subtotal != null ? Number(totals.subtotal) : items.reduce(function (s, it) {
      return s + (Number(it.price) || 0) * (it.qty || 1);
    }, 0);
    var listSubtotal = totals.subtotal_list != null ? Number(totals.subtotal_list) : (subtotal + comboDiscount);
    var total = totals.total != null ? Number(totals.total) : subtotal;
    var summaryBox = document.querySelector('[data-md-cart-summary], .md-cart-summary');
    ensureCartSummaryDiscounts(summaryBox);
    localizeCartSummary(summaryBox);

    document.querySelectorAll('[data-md-cart-subtotal]').forEach(function (el) {
      el.textContent = money(comboDiscount > 0 ? listSubtotal : subtotal);
    });
    document.querySelectorAll('[data-md-cart-combo-row]').forEach(function (el) {
      var on = comboDiscount > 0;
      el.classList.toggle('md-hide', !on);
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
      var lab = el.querySelector('[data-md-cart-combo-label]');
      if (lab) lab.textContent = mdT('cart.combo_discount', { pct: (comboPct || 20) + '%' });
      var val = el.querySelector('[data-md-cart-combo-discount]');
      if (val) val.textContent = '−' + money(comboDiscount);
    });
    document.querySelectorAll('[data-md-cart-bundle-row]').forEach(function (el) {
      var on = bundleDiscount > 0;
      el.classList.toggle('md-hide', !on);
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
      var lab = el.querySelector('[data-md-cart-bundle-label]');
      if (lab) lab.textContent = 'Ahorro combo' + (bundleLabel ? ' · ' + bundleLabel : '');
      var val = el.querySelector('[data-md-cart-bundle-discount]');
      if (val) val.textContent = '−' + money(bundleDiscount);
    });
    document.querySelectorAll('[data-md-cart-discount-row]').forEach(function (el) {
      var on = couponDiscount > 0;
      el.classList.toggle('md-hide', !on);
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
      var lab = el.querySelector('[data-md-cart-discount-label]');
      if (lab) lab.textContent = mdT('cart.discount');
      var val = el.querySelector('[data-md-cart-discount]');
      if (val) val.textContent = '−' + money(couponDiscount);
    });
    document.querySelectorAll('[data-md-cart-magic-row]').forEach(function (el) {
      var on = magicDiscount > 0;
      el.classList.toggle('md-hide', !on);
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
      var lab = el.querySelector('[data-md-cart-magic-label]');
      if (lab) lab.textContent = mdT('checkout.magic');
      var val = el.querySelector('[data-md-cart-magic-discount]');
      if (val) val.textContent = '−' + money(magicDiscount);
    });
    document.querySelectorAll('[data-md-cart-total]').forEach(function (el) {
      el.textContent = money(total);
    });

    ensureCheckoutCta(root, hasItems);
  }
  applyCart(loadCart());
  document.addEventListener('md:cart:sync', function (e) {
    var cart = e && e.detail && e.detail.cart;
    if (cart) applyCart(cart);
  });
  // Re-sincronizar summary tras theme.js/checkout.js (DOMContentLoaded)
  document.addEventListener('DOMContentLoaded', function () {
    syncCheckoutSummary(Multidrop.cart || loadCart());
  });
  setTimeout(function () {
    syncCheckoutSummary(Multidrop.cart || loadCart());
  }, 50);

  function pulseCartCount() {
    document.querySelectorAll('[data-md-cart-count]').forEach(function (el) {
      el.classList.remove('md-cart-bump');
      void el.offsetWidth;
      el.classList.add('md-cart-bump');
    });
  }

  /**
   * API de carrito para themes. Obligatorio en sandbox/commerce:
   * theme.js debe llamar Multidrop.Cart.add(id, qty) — no localStorage paralelo.
   */
  function resolveProductId(el) {
    if (!el) return 0;
    return parseInt(
      el.getAttribute('data-product-id') ||
      el.getAttribute('data-id') ||
      (Multidrop.product && Multidrop.product.id) ||
      '0',
      10
    ) || 0;
  }

  function resolveQty(el) {
    var scope = el ? el.closest('[data-md-product], [data-md-product-card], form, article, .md-pdp') : null;
    var input = scope ? scope.querySelector('[data-md-qty], [name="qty"], input[type="number"]') : null;
    var q = input ? parseInt(input.value, 10) : 1;
    return q > 0 ? q : 1;
  }

  Multidrop.Cart = Multidrop.Cart || {
    add: function (id, qty, opts) {
      id = parseInt(id, 10) || 0;
      qty = parseInt(qty, 10) || 1;
      opts = opts || {};
      if (!id) return Promise.resolve(null);
      if (useApi) {
        var payload = { product_id: id, qty: qty };
        if (opts.variant_id || opts.variantId) payload.variant_id = opts.variant_id || opts.variantId;
        return api(Multidrop.urls.cart_add, 'POST', payload).then(function (res) {
          if (res.ok && res.cart) {
            applyCart(res.cart);
            pulseCartCount();
            if (!opts.silent) showAddedToCartModal(id, qty, res.cart);
          }
          return res.cart || null;
        });
      }
      var cart = loadCart();
      cart.items = cart.items || [];
      var found = null;
      for (var i = 0; i < cart.items.length; i++) {
        if (Number(cart.items[i].product_id || cart.items[i].id) === id) {
          found = cart.items[i];
          break;
        }
      }
      if (found) {
        found.qty = (parseInt(found.qty, 10) || 0) + qty;
      } else {
        var p = (Multidrop.products || []).find(function (x) { return Number(x.id) === id; }) || Multidrop.product;
        cart.items.push({
          product_id: id,
          id: id,
          name: (p && (p.name || p.title)) || ('#' + id),
          price: p ? p.price : 0,
          image: p ? p.image : null,
          url: p ? p.url : null,
          qty: qty
        });
      }
      applyCart(cart);
      pulseCartCount();
      if (!opts.silent) showAddedToCartModal(id, qty, cart);
      return Promise.resolve(cart);
    },
    remove: function (id, opts) {
      id = parseInt(id, 10) || 0;
      opts = opts || {};
      if (!id && opts.line_index == null) return Promise.resolve(null);
      if (useApi && Multidrop.urls.cart_items) {
        var q = [];
        if (opts.variant_id) q.push('variant_id=' + encodeURIComponent(opts.variant_id));
        if (opts.line_index != null && opts.line_index >= 0) q.push('line_index=' + encodeURIComponent(opts.line_index));
        var qs = q.length ? ('?' + q.join('&')) : '';
        var deleteId = id || 0;
        return api(Multidrop.urls.cart_items + '/' + deleteId + qs, 'DELETE').then(function (res) {
          if (res.cart) applyCart(res.cart);
          return res.cart || null;
        });
      }
      var cart2 = loadCart();
      if (opts.line_index != null && opts.line_index >= 0 && Array.isArray(cart2.items) && cart2.items[opts.line_index]) {
        cart2.items.splice(opts.line_index, 1);
      } else {
        cart2.items = (cart2.items || []).filter(function (it) {
          return Number(it.product_id || it.id) !== id;
        });
      }
      applyCart(cart2);
      return Promise.resolve(cart2);
    },
    update: function (id, qty, opts) {
      id = parseInt(id, 10) || 0;
      qty = parseInt(qty, 10) || 0;
      opts = opts || {};
      if (!id && opts.line_index == null) return Promise.resolve(null);
      if (qty <= 0) return Multidrop.Cart.remove(id, opts);
      if (useApi && Multidrop.urls.cart_items) {
        var payload = { qty: qty };
        if (opts.variant_id) payload.variant_id = opts.variant_id;
        if (opts.line_index != null && opts.line_index >= 0) payload.line_index = opts.line_index;
        var patchId = id || 0;
        return api(Multidrop.urls.cart_items + '/' + patchId, 'PATCH', payload).then(function (res) {
          if (res.cart) applyCart(res.cart);
          return res.cart || null;
        });
      }
      var cart3 = loadCart();
      if (opts.line_index != null && opts.line_index >= 0 && Array.isArray(cart3.items) && cart3.items[opts.line_index]) {
        cart3.items[opts.line_index].qty = qty;
      } else {
        (cart3.items || []).forEach(function (it) {
          if (Number(it.product_id || it.id) === id) it.qty = qty;
        });
      }
      applyCart(cart3);
      return Promise.resolve(cart3);
    }
  };

  function atcCopy() {
    return {
      title: mdT('atc.title'),
      sub: mdT('atc.sub'),
      checkout: mdT('atc.checkout'),
      continue: mdT('atc.continue'),
      cart: mdT('atc.cart'),
      qty: mdT('atc.qty')
    };
  }

  function findProductForModal(id, cart) {
    id = Number(id) || 0;
    var fromCart = ((cart && cart.items) || []).find(function (it) {
      return Number(it.product_id || it.id) === id;
    });
    if (fromCart) return fromCart;
    var fromCatalog = (Multidrop.products || []).find(function (p) { return Number(p.id) === id; });
    if (fromCatalog) return fromCatalog;
    if (Multidrop.product && Number(Multidrop.product.id) === id) return Multidrop.product;
    return null;
  }

  function ensureAtcModalBound() {
    var modal = document.getElementById('md-atc-modal');
    if (!modal || modal.getAttribute('data-md-bound') === '1') return modal;
    modal.setAttribute('data-md-bound', '1');
    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-md-atc-close]')) {
        e.preventDefault();
        hideAddedToCartModal();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') hideAddedToCartModal();
    });
    return modal;
  }

  function hideAddedToCartModal() {
    var modal = document.getElementById('md-atc-modal');
    if (!modal) return;
    modal.classList.add('md-hide');
    modal.setAttribute('hidden', 'hidden');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = 'none';
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
  }

  function showAddedToCartModal(productId, qty, cart) {
    var modal = ensureAtcModalBound();
    if (!modal) return;
    if (modal.parentNode !== document.body) {
      document.body.appendChild(modal);
    }
    var copy = atcCopy();
    var item = findProductForModal(productId, cart);
    var name = (item && (item.name || item.title)) || ('#' + productId);
    var image = item && item.image ? item.image : '';
    var unit = item ? Number(item.price) || 0 : 0;
    var lineQty = qty || (item && item.qty) || 1;
    var line = unit * lineQty;

    var title = modal.querySelector('#md-atc-title') || document.getElementById('md-atc-title');
    var sub = modal.querySelector('#md-atc-sub') || document.getElementById('md-atc-sub');
    var nameEl = modal.querySelector('#md-atc-name') || document.getElementById('md-atc-name');
    var metaEl = modal.querySelector('#md-atc-meta') || document.getElementById('md-atc-meta');
    var priceEl = modal.querySelector('#md-atc-price') || document.getElementById('md-atc-price');
    var img = modal.querySelector('#md-atc-img') || document.getElementById('md-atc-img');
    var ph = modal.querySelector('#md-atc-img-ph') || document.getElementById('md-atc-img-ph');
    var checkoutBtn = modal.querySelector('#md-atc-checkout') || document.getElementById('md-atc-checkout');
    var continueBtn = modal.querySelector('#md-atc-continue') || document.getElementById('md-atc-continue');
    var cartBtn = modal.querySelector('#md-atc-cart') || document.getElementById('md-atc-cart');

    if (title) title.textContent = copy.title;
    if (sub) sub.textContent = copy.sub;
    if (nameEl) nameEl.textContent = name;
    if (metaEl) metaEl.textContent = copy.qty + ' ' + lineQty;
    if (priceEl) priceEl.textContent = money(line || unit);
    if (!image && item) {
      image = item.img || item.thumbnail || item.cover || item.photo
        || (Array.isArray(item.images) && item.images[0]) || '';
    }
    if (img) {
      img.setAttribute('referrerpolicy', 'no-referrer');
      img.setAttribute('width', '64');
      img.setAttribute('height', '64');
      if (image) {
        img.src = image;
        img.removeAttribute('hidden');
        img.hidden = false;
        img.style.display = 'block';
        img.style.width = '64px';
        img.style.height = '64px';
        img.style.opacity = '1';
        img.style.visibility = 'visible';
        img.style.objectFit = 'cover';
        if (ph) {
          ph.hidden = true;
          ph.setAttribute('hidden', 'hidden');
          ph.style.display = 'none';
        }
      } else {
        img.removeAttribute('src');
        img.hidden = true;
        img.setAttribute('hidden', 'hidden');
        img.style.display = 'none';
        if (ph) {
          ph.hidden = false;
          ph.removeAttribute('hidden');
          ph.style.display = 'block';
        }
      }
    }
    if (checkoutBtn) {
      checkoutBtn.textContent = copy.checkout;
      checkoutBtn.href = (Multidrop.urls && Multidrop.urls.checkout) || '#';
    }
    if (continueBtn) continueBtn.textContent = copy.continue;
    if (cartBtn) {
      cartBtn.textContent = copy.cart;
      cartBtn.href = (Multidrop.urls && Multidrop.urls.cart) || '#';
      cartBtn.hidden = false;
    }

    modal.classList.remove('md-hide');
    modal.removeAttribute('hidden');
    modal.setAttribute('aria-hidden', 'false');
    modal.style.display = 'flex';
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
  }

  Multidrop.ui = Multidrop.ui || {};
  Multidrop.ui.showAddedToCartModal = showAddedToCartModal;
  Multidrop.ui.hideAddedToCartModal = hideAddedToCartModal;

  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-md-add-to-cart], [data-cart-add], [data-add-to-cart]');
    if (add) {
      // Un solo dueño del add: runtime. Themes animan vía md:cart:add (no re-agregar).
      e.preventDefault();
      e.stopImmediatePropagation();
      var pid = resolveProductId(add);
      if (!pid) {
        var card = add.closest('[data-md-product-card], .md-card, [data-id], article');
        if (card) pid = parseInt(card.getAttribute('data-id') || card.getAttribute('data-product-id') || '0', 10) || 0;
      }
      var qty = resolveQty(add);
      if (!pid) return;
      var variantId = variantIdForAdd(add);
      var addOpts = { silent: true };
      if (variantId) addOpts.variant_id = variantId;
      Multidrop.Cart.add(pid, qty, addOpts).then(function (cart) {
        if (window.Multidrop && Multidrop.ui && typeof Multidrop.ui.showAddedToCartModal === 'function') {
          Multidrop.ui.showAddedToCartModal(pid, qty, cart || Multidrop.cart);
        }
        try {
          document.dispatchEvent(new CustomEvent('md:cart:add', {
            detail: { product_id: pid, qty: qty, variant_id: variantId, cart: cart, button: add }
          }));
        } catch (err) {}
      }).catch(function () {});
      return;
    }
    var rmMd = e.target.closest('[data-md-cart-remove], .md-cart-row__remove, [data-cart-remove]');
    var rm = rmMd;
    if (rm) {
      e.preventDefault();
      e.stopPropagation();
      var lineOpts = cartLineOptsFromEl(rm);
      var rid = parseInt(rm.getAttribute('data-md-cart-remove') || rm.getAttribute('data-cart-remove') || rm.getAttribute('data-product-id') || '', 10);
      var row = rm.closest('[data-cart-row], [data-md-cart-row], .md-cart-row, .md-cart-line');
      if (!rid && row) {
        rid = parseInt(row.getAttribute('data-cart-row') || row.getAttribute('data-md-cart-row') || row.getAttribute('data-product-id') || '', 10);
      }
      if (!rid && row) {
        var link = row.querySelector('[href*="/pages/"]');
        if (link) {
          var handle = String(link.getAttribute('href') || '').split('/pages/').pop();
          var found = (Multidrop.cart && Multidrop.cart.items || []).find(function (it) {
            return String(it.slug || '') === handle || String(it.url || '').indexOf(handle) !== -1;
          });
          if (found) rid = parseInt(found.product_id || found.id, 10);
        }
      }
      if (!rid && lineOpts.line_index == null) return;
      if (!lineOpts.variant_id && row) {
        lineOpts.variant_id = parseInt(row.getAttribute('data-variant-id') || '0', 10) || undefined;
      }
      Multidrop.Cart.remove(rid || 0, lineOpts);
      return;
    }
    var qtyMinus = e.target.closest('[data-md-cart-qty-minus]');
    if (qtyMinus) {
      e.preventDefault();
      e.stopPropagation();
      var wrapM = qtyMinus.closest('[data-md-cart-qty-wrap]') || qtyMinus.parentNode;
      var inputM = wrapM ? wrapM.querySelector('[data-md-cart-qty]') : null;
      var curM = inputM ? (parseInt(inputM.value, 10) || 1) : 1;
      applyCartQtyFromControl(qtyMinus, curM - 1);
      return;
    }
    var qtyPlus = e.target.closest('[data-md-cart-qty-plus]');
    if (qtyPlus) {
      e.preventDefault();
      e.stopPropagation();
      var wrapP = qtyPlus.closest('[data-md-cart-qty-wrap]') || qtyPlus.parentNode;
      var inputP = wrapP ? wrapP.querySelector('[data-md-cart-qty]') : null;
      var curP = inputP ? (parseInt(inputP.value, 10) || 1) : 1;
      applyCartQtyFromControl(qtyPlus, curP + 1);
      return;
    }
    var pdpQtyBtn = e.target.closest('[data-md-qty-minus], [data-md-qty-plus]');
    if (pdpQtyBtn && !pdpQtyBtn.closest('[data-md-cart]')) {
      e.preventDefault();
      var wrapQ = pdpQtyBtn.closest('.md-qty') || pdpQtyBtn.parentNode;
      var inputQ = wrapQ ? wrapQ.querySelector('[data-md-qty], [name="qty"], input[type="number"]') : null;
      if (inputQ) {
        var nQ = parseInt(inputQ.value, 10) || 1;
        if (pdpQtyBtn.hasAttribute('data-md-qty-minus')) nQ = Math.max(1, nQ - 1);
        else nQ += 1;
        inputQ.value = String(nQ);
      }
      return;
    }
    var couponBtn = e.target.closest('[data-md-coupon-apply]');
    if (couponBtn && useApi && Multidrop.urls.cart_coupon) {
      var wrap = couponBtn.closest('[data-md-coupon-form]') || document;
      var codeInput = wrap.querySelector('input[name="code"]');
      var code = codeInput ? codeInput.value : '';
      api(Multidrop.urls.cart_coupon, 'POST', { code: code }).then(function (res) {
        if (res.cart) applyCart(res.cart);
        var msg = wrap.querySelector('[data-md-coupon-msg]');
        if (msg) {
          msg.textContent = res.message || (res.ok ? 'Cupón aplicado' : 'Cupón no válido');
          msg.classList.toggle('is-error', !res.ok);
        }
      });
    }
    var clearCouponBtn = e.target.closest('[data-md-coupon-clear]');
    if (clearCouponBtn && useApi && Multidrop.urls.cart_coupon_clear) {
      var wrapClear = clearCouponBtn.closest('[data-md-coupon-form]') || document;
      api(Multidrop.urls.cart_coupon_clear, 'DELETE').then(function (res) {
        if (res.cart) applyCart(res.cart);
        var msg2 = wrapClear.querySelector('[data-md-coupon-msg]');
        if (msg2) {
          msg2.textContent = res.message || 'Cupón quitado';
          msg2.classList.remove('is-error');
        }
        var input2 = wrapClear.querySelector('input[name="code"]');
        if (input2) input2.value = '';
      });
    }
  });

  document.addEventListener('change', function (e) {
    var qtyInput = e.target.closest('[data-md-cart-qty]');
    if (!qtyInput) return;
    var next = parseInt(qtyInput.value, 10);
    if (isNaN(next) || next < 0) next = 0;
    if (next < 1) {
      applyCartQtyFromControl(qtyInput, 0);
      return;
    }
    qtyInput.value = String(next);
    applyCartQtyFromControl(qtyInput, next);
  });

  document.addEventListener('keydown', function (e) {
    var qtyInput = e.target.closest('[data-md-cart-qty]');
    if (!qtyInput) return;
    if (e.key !== 'Enter') return;
    e.preventDefault();
    qtyInput.blur();
  });

  document.addEventListener('submit', function (e) {
    var couponForm = e.target.closest('[data-md-coupon-form]');
    if (couponForm && couponForm.tagName === 'FORM') {
      e.preventDefault();
      if (!useApi || !Multidrop.urls.cart_coupon) return;
      var code = (couponForm.querySelector('input[name="code"]') || {}).value || '';
      api(Multidrop.urls.cart_coupon, 'POST', { code: code }).then(function (res) {
        if (res.cart) applyCart(res.cart);
        var msg = couponForm.querySelector('[data-md-coupon-msg]');
        if (msg) msg.textContent = res.message || (res.ok ? 'OK' : 'Cupón no válido');
      });
    }
    var checkoutForm = e.target.closest('[data-md-checkout-form]');
    if (checkoutForm) {
      e.preventDefault();
      if (!useApi || !Multidrop.urls.checkout_place) return;
      if (checkoutForm.getAttribute('data-md-placing') === '1') return;
      var fd = new FormData(checkoutForm);
      var body = {};
      fd.forEach(function (v, k) { body[k] = v; });
      if (!String(body.name || '').trim()) {
        body.name = String(body.first_name || body.firstname || '').trim()
          + (String(body.last_name || body.lastname || '').trim() ? ' ' + String(body.last_name || body.lastname || '').trim() : '');
      }
      body.phone = body.phone || body.tel || body.telephone || body.mobile || '';
      body.zip = body.zip || body.postal_code || body.postcode || body.cp || '';
      body.state = body.state || body.province || body.region || '';
      body.country = body.country || body.country_code || '';
      var msgEl = checkoutForm.querySelector('[data-md-checkout-msg]');
      var shipInput = document.querySelector('[data-md-shipping-country-field], [data-md-shipping-country], [name="country"]');
      if ((!body.country || String(body.country).trim() === '') && shipInput) {
        body.country = resolveCountryCode(shipInput.value) || String(shipInput.value || '').toUpperCase();
      }
      if (!body.country) {
        if (msgEl) msgEl.textContent = mdT('checkout.choose_country') || 'Select a country';
        focusCheckoutCountryPicker();
        setCheckoutTotalPending(true);
        return;
      }
      var cartShip = (Multidrop.cart && Multidrop.cart.shipping_country) || '';
      if (!cartShip) {
        if (msgEl) msgEl.textContent = 'Calculando envío…';
        if (useApi && Multidrop.urls.cart_shipping) {
          api(Multidrop.urls.cart_shipping, 'POST', { country: body.country }).then(function (res) {
            if (res.cart) applyCart(res.cart);
            if (!res.ok) {
              if (msgEl) msgEl.textContent = res.message || 'No se pudo calcular el envío.';
              return;
            }
            checkoutForm.requestSubmit ? checkoutForm.requestSubmit() : checkoutForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
          });
        }
        return;
      }
      if (msgEl) msgEl.textContent = mdT('checkout.processing') || 'Procesando…';
      checkoutForm.setAttribute('data-md-placing', '1');
      mdShowCheckoutRedirect();
      api(Multidrop.urls.checkout_place, 'POST', body).then(function (res) {
        if (res.confirm_url) {
          window.location.href = res.confirm_url;
          return;
        }
        if (res.checkout_url) {
          window.location.href = res.checkout_url;
          return;
        }
        if (res.track_url) {
          window.location.href = res.track_url;
          return;
        }
        checkoutForm.removeAttribute('data-md-placing');
        mdHideCheckoutRedirect();
        if (msgEl) msgEl.textContent = res.message || (res.ok ? 'Pedido creado' : 'No se pudo pagar');
      }).catch(function () {
        checkoutForm.removeAttribute('data-md-placing');
        mdHideCheckoutRedirect();
        if (msgEl) msgEl.textContent = mdT('checkout.network') || 'Error de red';
      });
    }
  });
})();
</script>
<script>
(function () {
  // Compat: themes Claude/IA a menudo leen featured/handle; si nadie es featured, marcar los primeros.
  var MD = window.Multidrop;
  if (!MD || !Array.isArray(MD.products)) return;
  MD.products.forEach(function (p) {
    p.handle = p.handle || p.slug;
    p.title = p.title || p.name;
    p.featured = !!(p.featured || p.is_featured);
    p.is_featured = !!(p.is_featured || p.featured);
    p.is_star = !!(p.is_star || p.star);
    p.star = !!(p.star || p.is_star);
  });
  if (!MD.star_product) {
    MD.star_product = MD.products.find(function (p) { return p.is_star || p.star; }) || MD.products[0] || null;
  }
  if (MD.star_product) {
    MD.star_product.handle = MD.star_product.handle || MD.star_product.slug;
    MD.star_product.title = MD.star_product.title || MD.star_product.name;
    MD.star_product.is_star = true;
    MD.star_product.star = true;
    MD.star_product.featured = true;
    MD.star_product.is_featured = true;
  }
  var anyFeat = MD.products.some(function (p) { return p.featured; });
  if (!anyFeat) {
    MD.products.forEach(function (p, i) {
      if (i < 12) { p.featured = true; p.is_featured = true; }
    });
  }
  if (MD.product) {
    MD.product.handle = MD.product.handle || MD.product.slug;
    MD.product.title = MD.product.title || MD.product.name;
    MD.product.featured = !!(MD.product.featured || MD.product.is_featured);
    MD.product.is_star = !!(MD.product.is_star || MD.product.star);
    MD.product.star = !!(MD.product.star || MD.product.is_star);
  }

  // Bind hero / bloque estrella: [data-md-star-product] + data-md-bind="star.*"
  (function bindStarBlocks() {
    var star = MD.star_product;
    if (!star) return;
    document.querySelectorAll('[data-md-star-product]').forEach(function (root) {
      root.querySelectorAll('[data-md-bind]').forEach(function (el) {
        var key = el.getAttribute('data-md-bind') || '';
        var field = key.indexOf('star.') === 0 ? key.slice(5) : (key.indexOf('product.') === 0 ? key.slice(8) : key);
        var val = star[field];
        if (field === 'price_formatted' && !val) {
          val = star.price != null ? ('$' + Number(star.price).toLocaleString('es-MX')) : '';
        }
        if (field === 'name' && !val) val = star.title;
        if (field === 'image' && !val && star.images && star.images[0]) val = star.images[0];
        if (el.tagName === 'IMG') {
          if (val) { el.src = val; el.alt = star.name || star.title || ''; }
          return;
        }
        if (el.tagName === 'A' && (field === 'url' || field === 'href')) {
          if (val) el.href = val;
          return;
        }
        if (val != null && val !== '') el.textContent = String(val);
      });
      var atc = root.querySelector('[data-md-add-to-cart]:not([data-product-id]):not([data-id])');
      if (atc && star.id) {
        atc.setAttribute('data-product-id', String(star.id));
        atc.setAttribute('data-id', String(star.id));
      }
    });
  })();
  if (!MD.cart || typeof MD.cart !== 'object' || Array.isArray(MD.cart)) {
    MD.cart = { items: Array.isArray(MD.cart) ? MD.cart : [] };
  }
  MD.modules = MD.modules || {};
  function setModuleEnabled(el, enabled) {
    if (!el) return;
    el.classList.toggle('md-hide', !enabled);
    if (enabled) {
      el.removeAttribute('hidden');
      el.setAttribute('aria-hidden', 'false');
      if (el.style && el.style.display === 'none') el.style.display = '';
    } else {
      el.setAttribute('hidden', 'hidden');
      el.setAttribute('aria-hidden', 'true');
    }
  }
  document.querySelectorAll('[data-md-module]').forEach(function (el) {
    var key = el.getAttribute('data-md-module');
    if (!key) return;
    var on = MD.modules[key];
    var enabled = on === true || on === 1 || on === '1';
    if (MD.sandbox) {
      setModuleEnabled(el, enabled);
      return;
    }
    // Tienda real: ocultar si el plugin está explícitamente off; mostrar si on
    if (typeof on === 'undefined') return;
    setModuleEnabled(el, enabled);
  });

  function mdCanonicalUrgency() {
    return document.querySelector('#md-sandbox-modules [data-md-module="urgency"]')
      || document.querySelector('[data-md-module="urgency"].md-mod-bar, [data-md-module="urgency"].md-mod-urgency')
      || document.querySelector('[data-md-module="urgency"]');
  }
  function mdDedupeUrgency(enabled) {
    var keep = mdCanonicalUrgency();
    document.querySelectorAll('[data-md-module="urgency"]').forEach(function (el) {
      setModuleEnabled(el, enabled && el === keep);
    });
    return keep;
  }
  var urgencyFlag = MD.modules.urgency === true || MD.modules.urgency === 1 || MD.modules.urgency === '1';
  mdDedupeUrgency(!!urgencyFlag);

  // Evita que ruleta se encime con prueba social (misma esquina)
  function mdLiftRouletteFab() {
    var wrap = document.getElementById('md-roulette-fab-wrap');
    if (!wrap) return;
    var social = document.getElementById('md-social-proof');
    var base = 16;
    var gap = 10;
    var bottom = base;
    var socialVisible = social
      && !social.classList.contains('md-hide')
      && social.classList.contains('md-sp-visible')
      && social.classList.contains('md-sp-left');
    if (socialVisible) {
      var h = Math.ceil(social.getBoundingClientRect().height || social.offsetHeight || 0);
      bottom = Math.max(base, 12 + h + gap);
    }
    wrap.style.bottom = bottom + 'px';
  }
  window.mdLiftRouletteFab = mdLiftRouletteFab;
  window.addEventListener('resize', function () { mdLiftRouletteFab(); });

  // Prueba social (sandbox + tienda)
  (function () {
    var mods = MD.modules || {};
    if (!(mods.social_proof === true || mods.social_proof === 1 || mods.social_proof === '1')) return;
    var box = document.getElementById('md-social-proof');
    if (!box) return;
    box.classList.remove('md-hide');

    var cfg = MD.social_proof || {};
    var intervalMs = Math.max(4000, (parseInt(cfg.interval_seconds, 10) || 9) * 1000);
    var displayMs = Math.max(3000, (parseInt(cfg.display_seconds, 10) || 5) * 1000);
    var pos = (cfg.position === 'bottom-right') ? 'right' : 'left';
    box.classList.remove('md-sp-left', 'md-sp-right');
    box.classList.add(pos === 'right' ? 'md-sp-right' : 'md-sp-left');

    var people = [
      { name: 'Emma', country: 'Estados Unidos', cc: 'us' },
      { name: 'Liam', country: 'Canadá', cc: 'ca' },
      { name: 'Sophie', country: 'Reino Unido', cc: 'gb' },
      { name: 'Jonas', country: 'Alemania', cc: 'de' },
      { name: 'Camille', country: 'Francia', cc: 'fr' },
      { name: 'Luca', country: 'Italia', cc: 'it' },
      { name: 'Laura', country: 'España', cc: 'es' },
      { name: 'Noah', country: 'Países Bajos', cc: 'nl' },
      { name: 'Freja', country: 'Suecia', cc: 'se' },
      { name: 'Oliver', country: 'Noruega', cc: 'no' },
      { name: 'Elena', country: 'Suiza', cc: 'ch' },
      { name: 'James', country: 'Australia', cc: 'au' },
      { name: 'Chloe', country: 'Nueva Zelanda', cc: 'nz' },
      { name: 'Yuki', country: 'Japón', cc: 'jp' },
      { name: 'Minji', country: 'Corea del Sur', cc: 'kr' },
      { name: 'Ava', country: 'Irlanda', cc: 'ie' },
      { name: 'Lucas', country: 'Bélgica', cc: 'be' },
      { name: 'Maja', country: 'Dinamarca', cc: 'dk' }
    ];
    var minsOpts = [1, 2, 3, 4, 5, 7, 8, 12, 15, 18, 22, 28, 35, 42];
    var lastKey = '';

    function pickProduct() {
      var list = Array.isArray(MD.products) ? MD.products.slice() : [];
      var star = MD.star_product || null;
      // ~70% del tiempo menciona el producto estrella (eje de la mini-tienda)
      if (star && (star.name || star.title) && Math.random() < 0.7) {
        return {
          name: star.name || star.title || 'este producto',
          image: star.image || (Array.isArray(star.images) && star.images[0]) || '',
          url: star.url || '#'
        };
      }
      if (MD.product && (MD.product.name || MD.product.title)) {
        var curId = MD.product.id || MD.product.slug || MD.product.handle;
        var hasCur = list.some(function (p) {
          return (p.id || p.slug || p.handle) === curId;
        });
        if (!hasCur) list.unshift(MD.product);
      }
      if (!list.length) {
        if (star) {
          return {
            name: star.name || star.title || 'un producto destacado',
            image: star.image || '',
            url: star.url || ((MD.urls && MD.urls.catalog) || '#')
          };
        }
        return { name: 'un producto destacado', image: '', url: (MD.urls && MD.urls.catalog) || '#' };
      }
      var p = list[Math.floor(Math.random() * list.length)];
      var img = p.image || (Array.isArray(p.images) && p.images[0]) || '';
      var url = p.url || '#';
      if ((!url || url === '#') && p.slug && MD.urls && MD.urls.home) {
        url = String(MD.urls.home).replace(/\/?$/, '/') + 'pages/' + p.slug;
      }
      return {
        name: p.name || p.title || 'este producto',
        image: img,
        url: url || '#'
      };
    }

    function whenText(mins) {
      if (mins <= 1) return mdT('social.min_one');
      if (mins < 60) return mdT('social.mins', { n: mins });
      return mdT('social.hour_one');
    }

    function pick() {
      var person = people[Math.floor(Math.random() * people.length)];
      var mins = minsOpts[Math.floor(Math.random() * minsOpts.length)];
      var prod = pickProduct();
      var key = person.name + '|' + prod.name + '|' + mins;
      if (key === lastKey) return pick();
      lastKey = key;
      return { person: person, mins: mins, prod: prod };
    }

    var hideTimer = null;
    function hideToast() {
      box.classList.remove('md-sp-visible');
      if (typeof window.mdLiftRouletteFab === 'function') window.mdLiftRouletteFab();
    }
    function showToast() {
      var sale = pick();
      var n = document.getElementById('md-sp-name');
      var c = document.getElementById('md-sp-country');
      var f = document.getElementById('md-sp-flag');
      var p = document.getElementById('md-sp-product');
      var w = document.getElementById('md-sp-when');
      var img = document.getElementById('md-sp-img');
      var thumb = box.querySelector('.md-sp-thumb');
      var links = box.querySelectorAll('[data-md-sp-link]');
      if (n) n.textContent = sale.person.name;
      if (c) c.textContent = sale.person.country;
      if (f) {
        var cc = (sale.person.cc || 'us').toLowerCase();
        f.src = 'https://flagcdn.com/w40/' + cc + '.png';
        f.alt = '';
        f.title = sale.person.country;
      }
      if (p) {
        p.textContent = sale.prod.name;
        p.setAttribute('href', sale.prod.url || '#');
        p.setAttribute('title', sale.prod.name);
      }
      if (img) {
        if (sale.prod.image) {
          img.src = sale.prod.image;
          img.alt = sale.prod.name;
          img.classList.remove('md-hide');
          if (thumb) thumb.classList.remove('is-empty');
        } else {
          img.removeAttribute('src');
          img.alt = '';
          img.classList.add('md-hide');
          if (thumb) thumb.classList.add('is-empty');
        }
      }
      links.forEach(function (a) {
        a.setAttribute('href', sale.prod.url || '#');
        if (sale.prod.url && sale.prod.url !== '#') {
          a.removeAttribute('aria-disabled');
        } else {
          a.setAttribute('aria-disabled', 'true');
        }
      });
      if (w) w.textContent = whenText(sale.mins);
      box.classList.add('md-sp-visible');
      requestAnimationFrame(function () {
        if (typeof window.mdLiftRouletteFab === 'function') window.mdLiftRouletteFab();
      });
      if (hideTimer) clearTimeout(hideTimer);
      hideTimer = setTimeout(hideToast, displayMs);
    }

    var closeBtn = document.getElementById('md-sp-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        hideToast();
      });
    }

    box.addEventListener('click', function (e) {
      var link = e.target.closest('[data-md-sp-link]');
      if (!link) return;
      var href = link.getAttribute('href') || '';
      if (!href || href === '#') {
        e.preventDefault();
      }
    });

    setTimeout(showToast, 1800);
    setInterval(showToast, intervalMs);
    if (typeof ResizeObserver !== 'undefined') {
      try {
        new ResizeObserver(function () {
          if (typeof window.mdLiftRouletteFab === 'function') window.mdLiftRouletteFab();
        }).observe(box);
      } catch (e) {}
    }
  })();

  // Newsletter: checkbox bajo el email de Contact (sin popup)
  (function () {
    var mods = MD.modules || {};
    if (!(mods.newsletter === true || mods.newsletter === 1 || mods.newsletter === '1')) return;
    var cfg = MD.newsletter || {};

    function injectCheckoutOptIn() {
      if (cfg.checkout_enabled === false) return;

      var labelHtml = cfg.checkout_label_display || cfg.checkout_label ||
        ('Quiero recibir ofertas y ganar un cupón de <strong>' + (cfg.coupon_hint || 'X') + '</strong> para mi próxima compra');

      var checkoutForm = document.querySelector('[data-md-checkout-form], form.md-checkout-form');
      if (!checkoutForm) return;

      function placeWrap(wrap) {
        var slot = checkoutForm.querySelector('[data-md-newsletter-slot], .md-newsletter-slot');
        if (slot) {
          if (wrap.parentNode !== slot) slot.appendChild(wrap);
          return;
        }
        var emailInput = checkoutForm.querySelector('#email, input[name="email"], input[type="email"][autocomplete="email"], input[type="email"]');
        var emailField = emailInput ? (emailInput.closest('.md-field') || emailInput.parentNode) : null;
        var row = emailField ? emailField.closest('.md-row2, .md-row3, .md-checkout-form-grid--2') : null;
        if (row && row.parentNode) {
          if (wrap.parentNode === row) {
            if (row.nextSibling) row.parentNode.insertBefore(wrap, row.nextSibling);
            else row.parentNode.appendChild(wrap);
          } else if (!row.contains(wrap)) {
            if (row.nextSibling) row.parentNode.insertBefore(wrap, row.nextSibling);
            else row.parentNode.appendChild(wrap);
          }
          return;
        }
        var fieldset = emailField ? emailField.closest('fieldset, .md-fieldset, .md-checkout-section') : null;
        if (fieldset && fieldset.parentNode && wrap.parentNode !== fieldset.parentNode) {
          if (fieldset.nextSibling) fieldset.parentNode.insertBefore(wrap, fieldset.nextSibling);
          else fieldset.parentNode.appendChild(wrap);
          return;
        }
        if (emailField && emailField.parentNode && wrap.parentNode !== emailField.parentNode) {
          if (emailField.nextSibling) emailField.parentNode.insertBefore(wrap, emailField.nextSibling);
          else emailField.parentNode.appendChild(wrap);
        }
      }

      var existing = document.getElementById('md-newsletter-opt-in');
      if (existing) {
        var existingWrap = existing.closest('.md-mod-newsletter-checkout') || existing.parentNode;
        var span = existingWrap ? existingWrap.querySelector('span') : null;
        if (span && labelHtml) span.innerHTML = labelHtml;
        if (existingWrap) placeWrap(existingWrap);
        return;
      }

      var wrap = document.createElement('label');
      wrap.className = 'md-mod-newsletter-checkout';
      wrap.setAttribute('for', 'md-newsletter-opt-in');
      wrap.setAttribute('data-md-module', 'newsletter');
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.name = 'newsletter_opt_in';
      cb.id = 'md-newsletter-opt-in';
      cb.value = '1';
      var text = document.createElement('span');
      text.innerHTML = labelHtml;
      wrap.appendChild(cb);
      wrap.appendChild(text);

      var contactSection = null;
      checkoutForm.querySelectorAll('.md-checkout-section, section').forEach(function (sec) {
        if (contactSection) return;
        var h = sec.querySelector('h2, h3, .md-h3, legend');
        var t = ((h && h.textContent) || '').toLowerCase();
        if (t.indexOf('contact') !== -1 || t.indexOf('contacto') !== -1 || t.indexOf('tus datos') !== -1) contactSection = sec;
      });
      placeWrap(wrap);
      if (!wrap.parentNode && contactSection) {
        contactSection.appendChild(wrap);
      }
      if (!wrap.parentNode) checkoutForm.appendChild(wrap);
    }

    injectCheckoutOptIn();
    setTimeout(injectCheckoutOptIn, 400);
    setTimeout(injectCheckoutOptIn, 1200);
  })();

  // Cookies UE: banner + preferencias; no carga GA/Pixel hasta consentimiento
  (function () {
    var mods = MD.modules || {};
    if (!(mods.cookies === true || mods.cookies === 1 || mods.cookies === '1')) return;

    var cfg = MD.cookies || {};
    var pixels = MD.pixels || {};
    var storeId = (MD.store && MD.store.id) ? String(MD.store.id) : '0';
    var storageKey = 'md_cookie_consent_' + storeId;
    var version = parseInt(cfg.version || 1, 10) || 1;
    var root = document.getElementById('md-cookies');
    if (!root) return;

    var bar = document.getElementById('md-cookies-bar');
    var overlay = document.getElementById('md-cookies-overlay');
    var analyticsCb = document.getElementById('md-cookies-analytics');
    var marketingCb = document.getElementById('md-cookies-marketing');
    var analyticsRow = root.querySelector('[data-md-cookies-cat="analytics"]');
    var marketingRow = root.querySelector('[data-md-cookies-cat="marketing"]');
    var showAnalytics = cfg.analytics_enabled !== false && !!pixels.ga;
    var showMarketing = cfg.marketing_enabled !== false && !!pixels.meta;
    var gaLoaded = false;
    var metaLoaded = false;

    function showNode(el) {
      if (!el) return;
      el.hidden = false;
      el.classList.remove('md-hide');
    }
    function hideNode(el) {
      if (!el) return;
      el.hidden = true;
      el.classList.add('md-hide');
    }
    function applyCopy() {
      var map = [
        ['md-cookies-title', cfg.title || mdT('cookies.title')],
        ['md-cookies-body', cfg.body || mdT('cookies.body')],
        ['md-cookies-necessary-label', cfg.necessary_label || mdT('cookies.necessary')],
        ['md-cookies-analytics-label', cfg.analytics_label || mdT('cookies.analytics')],
        ['md-cookies-marketing-label', cfg.marketing_label || mdT('cookies.marketing')],
        ['md-cookies-prefs-title', cfg.configure_label || mdT('cookies.configure')]
      ];
      map.forEach(function (row) {
        var el = document.getElementById(row[0]);
        if (el && row[1]) el.textContent = row[1];
      });
      var policy = document.getElementById('md-cookies-policy');
      if (policy) {
        if (cfg.policy_url) {
          policy.href = cfg.policy_url;
          policy.hidden = false;
          policy.textContent = mdT('cookies.policy');
        } else {
          policy.hidden = true;
        }
      }
      var acceptBtn = root.querySelector('[data-md-cookies-accept]');
      var rejectBtn = root.querySelector('[data-md-cookies-reject]');
      var configureBtn = root.querySelector('[data-md-cookies-configure]');
      var saveBtn = root.querySelector('[data-md-cookies-save]');
      if (acceptBtn) acceptBtn.textContent = cfg.accept_label || mdT('cookies.accept');
      if (rejectBtn) rejectBtn.textContent = cfg.reject_label || mdT('cookies.reject');
      if (configureBtn) configureBtn.textContent = cfg.configure_label || mdT('cookies.configure');
      if (saveBtn) saveBtn.textContent = cfg.save_label || mdT('cookies.save');
      if (analyticsRow) analyticsRow.hidden = !showAnalytics;
      if (marketingRow) marketingRow.hidden = !showMarketing;
    }
    function readConsent() {
      try {
        var raw = localStorage.getItem(storageKey);
        if (!raw) return null;
        var data = JSON.parse(raw);
        if (!data || parseInt(data.v, 10) !== version) return null;
        return {
          analytics: !!data.analytics,
          marketing: !!data.marketing,
          at: data.at || ''
        };
      } catch (e) {
        return null;
      }
    }
    function writeConsent(flags) {
      var payload = {
        v: version,
        analytics: !!flags.analytics,
        marketing: !!flags.marketing,
        at: new Date().toISOString()
      };
      try { localStorage.setItem(storageKey, JSON.stringify(payload)); } catch (e) {}
      return payload;
    }
    function loadGa(id) {
      if (gaLoaded || !id) return;
      gaLoaded = true;
      window.dataLayer = window.dataLayer || [];
      if (typeof window.gtag !== 'function') {
        window.gtag = function () { window.dataLayer.push(arguments); };
      }
      if (!document.querySelector('script[data-md-gtag]')) {
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
        s.setAttribute('data-md-gtag', '1');
        document.head.appendChild(s);
      }
      window.gtag('js', new Date());
      window.gtag('config', id);
    }
    function loadMeta(id) {
      if (metaLoaded || !id || typeof window.fbq === 'function') {
        if (typeof window.fbq === 'function' && !metaLoaded && id) {
          metaLoaded = true;
          window.fbq('init', id);
          window.fbq('track', 'PageView');
        }
        return;
      }
      metaLoaded = true;
      !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
      n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;t.setAttribute('data-md-fbq','1');s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window, document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');
      window.fbq('init', id);
      window.fbq('track', 'PageView');
    }
    function applyPixels(consent) {
      if (!consent) return;
      if (consent.analytics && showAnalytics && pixels.ga) loadGa(pixels.ga);
      if (consent.marketing && showMarketing && pixels.meta) loadMeta(pixels.meta);
    }
    function hideUi() {
      hideNode(bar);
      hideNode(overlay);
      hideNode(root);
    }
    function showBanner() {
      showNode(root);
      showNode(bar);
      hideNode(overlay);
    }
    function showPrefs(fromBanner) {
      var current = readConsent();
      if (analyticsCb) analyticsCb.checked = !!(current && current.analytics);
      if (marketingCb) marketingCb.checked = !!(current && current.marketing);
      showNode(root);
      if (!fromBanner) hideNode(bar);
      showNode(overlay);
    }
    function saveFlags(flags) {
      var consent = writeConsent({
        analytics: !!(flags.analytics && showAnalytics),
        marketing: !!(flags.marketing && showMarketing)
      });
      applyPixels(consent);
      hideUi();
    }
    function ensureFooterLink() {
      if (document.querySelector('[data-md-cookies-open]')) return;
      var a = document.createElement('a');
      a.href = '#cookies';
      a.setAttribute('data-md-cookies-open', '');
      a.textContent = mdT('cookies.link') || 'Cookies';
      var list = document.querySelector('.md-footer ul:last-of-type');
      if (list) {
        var li = document.createElement('li');
        li.appendChild(a);
        list.appendChild(li);
        return;
      }
      var bottom = document.querySelector('.md-footer__bottom, footer');
      if (bottom) bottom.appendChild(a);
    }

    applyCopy();
    ensureFooterLink();

    var existing = readConsent();
    if (existing) {
      applyPixels(existing);
      hideUi();
    } else {
      showBanner();
    }

    root.addEventListener('click', function (e) {
      if (e.target.closest('[data-md-cookies-accept]')) {
        e.preventDefault();
        saveFlags({ analytics: true, marketing: true });
        return;
      }
      if (e.target.closest('[data-md-cookies-reject]')) {
        e.preventDefault();
        saveFlags({ analytics: false, marketing: false });
        return;
      }
      if (e.target.closest('[data-md-cookies-configure]')) {
        e.preventDefault();
        showPrefs(true);
        return;
      }
      if (e.target.closest('[data-md-cookies-save]')) {
        e.preventDefault();
        saveFlags({
          analytics: !!(analyticsCb && analyticsCb.checked),
          marketing: !!(marketingCb && marketingCb.checked)
        });
        return;
      }
      if (e.target.closest('[data-md-cookies-close]')) {
        e.preventDefault();
        if (readConsent()) hideUi();
        else { hideNode(overlay); showNode(bar); }
      }
    });

    document.addEventListener('click', function (e) {
      var open = e.target.closest('[data-md-cookies-open]');
      if (!open) return;
      e.preventDefault();
      showPrefs(false);
    });
  })();

  // Ruleta fullscreen (sandbox + tienda) — 1 giro por día (hasta mañana)
  (function () {
    var mods = MD.modules || {};
    if (!(mods.roulette === true || mods.roulette === 1 || mods.roulette === '1')) return;
    var root = document.getElementById('md-roulette-root');
    if (!root) return;
    root.classList.remove('md-hide');

    var cfg = MD.roulette || {};
    var prizes = Array.isArray(cfg.prizes) ? cfg.prizes.slice() : [];
    function localizePrizeLabel(label) {
      var t = String(label || '').trim().toLowerCase();
      if (/env[ií]o\s*gratis|free\s*ship|frete\s*gr[aá]tis/i.test(t)) return mdT('roulette.free_ship');
      if (/intenta\s*otra|try\s*again|tente\s*de\s*novo|tente\s*outra/i.test(t)) return mdT('roulette.try_again');
      return label;
    }
    if (prizes.length < 2) {
      prizes = [
        { label: '5% OFF', color: '#14b8a6', weight: 25, code: 'SAVE5' },
        { label: '10% OFF', color: '#f59e0b', weight: 20, code: 'SAVE10' },
        { label: mdT('roulette.free_ship'), color: '#8b5cf6', weight: 15, code: 'FREESHIP' },
        { label: '15% OFF', color: '#ef4444', weight: 10, code: 'SAVE15' },
        { label: mdT('roulette.try_again'), color: '#64748b', weight: 20 },
        { label: 'DEMO10', color: '#0ea5e9', weight: 10, code: 'DEMO10' }
      ];
    } else {
      prizes = prizes.map(function (p) {
        var row = Object.assign({}, p);
        row.label = localizePrizeLabel(row.label);
        return row;
      });
    }

    var titleEl = document.getElementById('md-roulette-title');
    var subEl = document.getElementById('md-roulette-subtitle');
    var headline = cfg.headline && !/^[¡!]?\s*gira/i.test(String(cfg.headline))
      ? String(cfg.headline)
      : mdT('roulette.headline');
    var subtitle = cfg.subtitle && !/prueba tu suerte|sandbox · prueba/i.test(String(cfg.subtitle))
      ? String(cfg.subtitle)
      : mdT('roulette.subtitle');
    if (titleEl) titleEl.textContent = headline;
    if (subEl) subEl.textContent = subtitle;
    var fabBtn = document.querySelector('[data-md-roulette-fab]');
    if (fabBtn) fabBtn.textContent = mdT('roulette.fab');
    document.querySelectorAll('[data-md-roulette-won-kicker]').forEach(function (el) {
      el.textContent = mdT('roulette.won_kicker');
    });
    document.querySelectorAll('[data-md-roulette-next-spin]').forEach(function (el) {
      el.textContent = mdT('roulette.next_spin');
    });
    document.querySelectorAll('[data-md-roulette-miss-title]').forEach(function (el) {
      el.textContent = mdT('roulette.miss_title');
    });
    document.querySelectorAll('[data-md-roulette-miss-body]').forEach(function (el) {
      el.textContent = mdT('roulette.miss_body');
    });
    document.querySelectorAll('[data-md-roulette-copy]').forEach(function (el) {
      el.textContent = mdT('roulette.copy');
    });
    document.querySelectorAll('[data-md-roulette-copy-coupon]').forEach(function (el) {
      el.textContent = mdT('roulette.copy_coupon');
    });
    if (document.getElementById('md-roulette-close')) {
      document.getElementById('md-roulette-close').setAttribute('aria-label', mdT('roulette.close'));
    }
    if (document.getElementById('md-roulette-retry')) {
      document.getElementById('md-roulette-retry').textContent = mdT('roulette.spin_again');
    }
    if (document.getElementById('md-roulette-spin')) {
      document.getElementById('md-roulette-spin').textContent = mdT('roulette.spin');
    }

    var wheel = document.getElementById('md-roulette-wheel');
    var overlay = document.getElementById('md-roulette-overlay');
    var openBtn = document.getElementById('md-roulette-open');
    var closeBtn = document.getElementById('md-roulette-close');
    var spinBtn = document.getElementById('md-roulette-spin');
    var resultPanel = document.getElementById('md-roulette-result-panel');
    var resultLabel = document.getElementById('md-roulette-result-label');
    var resultExtra = document.getElementById('md-roulette-result-extra');
    var resultCopyRow = document.getElementById('md-roulette-result-copy-row');
    var resultCodeEl = document.getElementById('md-roulette-result-code');
    var resultCopyBtn = document.getElementById('md-roulette-result-copy');
    var confettiBox = document.getElementById('md-roulette-confetti');
    var wonPanel = document.getElementById('md-roulette-won');
    var wonLabel = document.getElementById('md-roulette-won-label');
    var wonCodeRow = document.getElementById('md-roulette-won-code-row');
    var wonCodeEl = document.getElementById('md-roulette-won-code');
    var copyBtn = document.getElementById('md-roulette-copy');
    var cooldownEl = document.getElementById('md-roulette-cooldown');
    var missPanel = document.getElementById('md-roulette-miss');
    var retryBtn = document.getElementById('md-roulette-retry');
    if (!wheel || !overlay || !spinBtn) return;

    var storeKey = 'md_roulette_' + ((MD.store && (MD.store.id || MD.store.slug)) || 'sandbox');
    var rotation = 0;
    var spinning = false;
    var lockState = null;
    var showingMiss = false;
    var cooldownTimer = null;

    function isMissPrize(prize) {
      if (!prize) return false;
      if (prize.miss === true || prize.is_miss === true) return true;
      var label = String(prize.label || '');
      return /intenta\s*otra|sin\s*premio|try\s*again|no\s*prize/i.test(label);
    }
    function resolvePrizeCode(prize) {
      if (!prize || isMissPrize(prize)) return null;
      var code = String(prize.code || '').trim();
      if (code) return code.toUpperCase();
      var label = String(prize.label || '');
      var m = label.match(/(\d+)\s*%/i);
      if (m) return 'SAVE' + m[1];
      if (/env[ií]o\s*gratis|free\s*ship/i.test(label)) return 'FREESHIP';
      var fallback = label.replace(/[^a-zA-Z0-9]+/g, '').toUpperCase();
      return fallback || null;
    }
    function nextLocalMidnight() {
      var d = new Date();
      d.setHours(24, 0, 0, 0);
      return d.getTime();
    }
    function readLock() {
      try {
        var raw = localStorage.getItem(storeKey);
        if (!raw) return null;
        var data = JSON.parse(raw);
        if (!data || !data.unlock_at) return null;
        // Locks antiguos de “Intenta otra vez” no cuentan
        if (isMissPrize(data) || (!data.code && /intenta/i.test(String(data.label || '')))) {
          localStorage.removeItem(storeKey);
          return null;
        }
        if (Date.now() >= Number(data.unlock_at)) {
          localStorage.removeItem(storeKey);
          return null;
        }
        // Premios viejos sin code: recuperar cupón desde el label
        if (!data.code) {
          data.code = resolvePrizeCode(data);
          if (data.code) {
            try { localStorage.setItem(storeKey, JSON.stringify(data)); } catch (eSave) {}
          }
        }
        return data;
      } catch (e) {
        return null;
      }
    }
    function writeLock(prize) {
      var data = {
        label: prize.label || mdT('roulette.prize'),
        code: resolvePrizeCode(prize),
        unlock_at: nextLocalMidnight(),
        at: Date.now()
      };
      try { localStorage.setItem(storeKey, JSON.stringify(data)); } catch (e) {}
      return data;
    }
    function formatRemain(ms) {
      var s = Math.max(0, Math.floor(ms / 1000));
      var h = Math.floor(s / 3600);
      var m = Math.floor((s % 3600) / 60);
      var sec = s % 60;
      return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }
    function copyText(text, btn) {
      if (!text) return;
      function ok() {
        if (!btn) return;
        var prev = btn.textContent;
        btn.textContent = mdT('roulette.copied');
        btn.classList.add('is-copied');
        setTimeout(function () {
          btn.textContent = prev;
          btn.classList.remove('is-copied');
        }, 1600);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(ok).catch(function () {
          var ta = document.createElement('textarea');
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); ok(); } catch (e) {}
          document.body.removeChild(ta);
        });
      } else {
        var ta2 = document.createElement('textarea');
        ta2.value = text;
        document.body.appendChild(ta2);
        ta2.select();
        try { document.execCommand('copy'); ok(); } catch (e2) {}
        document.body.removeChild(ta2);
      }
    }
    function tickCooldown() {
      if (!lockState || !cooldownEl) return;
      var left = Number(lockState.unlock_at) - Date.now();
      if (left <= 0) {
        lockState = null;
        try { localStorage.removeItem(storeKey); } catch (e) {}
        renderFabState();
        return;
      }
      cooldownEl.textContent = formatRemain(left);
    }
    function renderFabState() {
      lockState = readLock();
      if (lockState) {
        showingMiss = false;
        if (openBtn) openBtn.classList.add('md-hide');
        if (missPanel) missPanel.classList.add('md-hide');
        if (wonPanel) wonPanel.classList.remove('md-hide');
        if (wonLabel) wonLabel.textContent = localizePrizeLabel(lockState.label || '') || mdT('roulette.prize');
        if (wonCodeRow && wonCodeEl) {
          var showCode = lockState.code || resolvePrizeCode(lockState);
          if (showCode) {
            wonCodeEl.textContent = showCode;
            wonCodeRow.classList.remove('md-hide');
          } else {
            wonCodeEl.textContent = '';
            wonCodeRow.classList.add('md-hide');
          }
        }
        spinBtn.disabled = true;
        spinBtn.textContent = mdT('roulette.tomorrow');
        tickCooldown();
        if (cooldownTimer) clearInterval(cooldownTimer);
        cooldownTimer = setInterval(tickCooldown, 1000);
      } else if (showingMiss) {
        if (openBtn) openBtn.classList.add('md-hide');
        if (wonPanel) wonPanel.classList.add('md-hide');
        if (missPanel) missPanel.classList.remove('md-hide');
        spinBtn.disabled = false;
        spinBtn.textContent = mdT('roulette.spin_again');
        if (cooldownTimer) {
          clearInterval(cooldownTimer);
          cooldownTimer = null;
        }
      } else {
        if (openBtn) openBtn.classList.remove('md-hide');
        if (wonPanel) wonPanel.classList.add('md-hide');
        if (missPanel) missPanel.classList.add('md-hide');
        spinBtn.disabled = false;
        spinBtn.textContent = mdT('roulette.spin');
        if (cooldownTimer) {
          clearInterval(cooldownTimer);
          cooldownTimer = null;
        }
      }
      if (typeof window.mdLiftRouletteFab === 'function') {
        requestAnimationFrame(window.mdLiftRouletteFab);
      }
    }

    var n = prizes.length;
    var seg = 360 / n;
    var stops = [];
    var acc = 0;
    prizes.forEach(function (p) {
      var c = p.color || '#0f766e';
      stops.push(c + ' ' + acc + 'deg ' + (acc + seg) + 'deg');
      acc += seg;
    });
    wheel.style.background = 'conic-gradient(from 0deg, ' + stops.join(', ') + ')';
    wheel.style.setProperty('--md-roulette-n', String(n));
    wheel.innerHTML = '';
    prizes.forEach(function (p, i) {
      var lab = document.createElement('div');
      lab.className = 'md-mod-roulette-seg-label';
      var midFromTop = (i + 0.5) * seg;
      var rad = midFromTop * Math.PI / 180;
      var dist = 34;
      var x = 50 + Math.sin(rad) * dist;
      var y = 50 - Math.cos(rad) * dist;
      var rot = midFromTop - 90;
      lab.style.left = x + '%';
      lab.style.top = y + '%';
      lab.style.transform = 'translate(-50%, -50%) rotate(' + rot + 'deg)';
      var span = document.createElement('span');
      span.textContent = p.label || ('#' + (i + 1));
      lab.appendChild(span);
      wheel.appendChild(lab);
    });

    function openOverlay() {
      if (readLock()) return;
      overlay.classList.remove('md-hide');
      document.body.style.overflow = 'hidden';
    }
    function closeOverlay() {
      if (spinning) return;
      overlay.classList.add('md-hide');
      document.body.style.overflow = '';
    }
    if (openBtn) openBtn.addEventListener('click', openOverlay);
    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeOverlay();
    });
    if (retryBtn) {
      retryBtn.addEventListener('click', function () {
        showingMiss = false;
        renderFabState();
        openOverlay();
      });
    }
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        copyText((lockState && lockState.code) || (wonCodeEl && wonCodeEl.textContent) || '', copyBtn);
      });
    }
    if (resultCopyBtn) {
      resultCopyBtn.addEventListener('click', function () {
        copyText((resultCodeEl && resultCodeEl.textContent) || '', resultCopyBtn);
      });
    }

    function pickIndex() {
      var total = 0;
      prizes.forEach(function (p) { total += Math.max(1, parseInt(p.weight, 10) || 1); });
      var r = Math.random() * total;
      var run = 0;
      for (var i = 0; i < prizes.length; i++) {
        run += Math.max(1, parseInt(prizes[i].weight, 10) || 1);
        if (r <= run) return i;
      }
      return prizes.length - 1;
    }

    function burstConfetti() {
      if (!confettiBox) return;
      confettiBox.innerHTML = '';
      var colors = prizes.map(function (p) { return p.color || '#f59e0b'; });
      for (var i = 0; i < 48; i++) {
        var el = document.createElement('i');
        el.style.left = (Math.random() * 100) + '%';
        el.style.background = colors[i % colors.length];
        el.style.animationDelay = (Math.random() * 0.4) + 's';
        el.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
        confettiBox.appendChild(el);
      }
      setTimeout(function () { confettiBox.innerHTML = ''; }, 2600);
    }

    function applyCoupon(code) {
      if (!code || !MD.urls || !MD.urls.cart_coupon) return;
      var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
      if (MD.csrf) headers['X-CSRF-TOKEN'] = MD.csrf;
      fetch(MD.urls.cart_coupon, {
        method: 'POST',
        headers: headers,
        credentials: 'same-origin',
        body: JSON.stringify({ code: code })
      }).catch(function () {});
    }

    spinBtn.addEventListener('click', function () {
      if (spinning || readLock()) return;
      spinning = true;
      spinBtn.disabled = true;
      if (resultPanel) resultPanel.classList.add('md-hide');
      if (resultCopyRow) resultCopyRow.classList.add('md-hide');
      var idx = pickIndex();
      var prize = prizes[idx];
      var targetCenter = (idx + 0.5) * seg;
      var spins = 5 + Math.floor(Math.random() * 3);
      var current = ((rotation % 360) + 360) % 360;
      var delta = (360 - targetCenter - current + 360) % 360;
      rotation = rotation + spins * 360 + delta;
      var ms = parseInt(cfg.spin_ms, 10) || 4800;
      wheel.style.transitionDuration = (ms / 1000) + 's';
      wheel.classList.add('is-spinning');
      wheel.style.transform = 'rotate(' + rotation + 'deg)';

      setTimeout(function () {
        spinning = false;
        wheel.classList.remove('is-spinning');
        var miss = isMissPrize(prize);
        if (miss) {
          showingMiss = true;
          renderFabState();
        } else {
          showingMiss = false;
          lockState = writeLock(prize);
          renderFabState();
          burstConfetti();
        }
        if (resultPanel) resultPanel.classList.remove('md-hide');
        if (resultLabel) resultLabel.textContent = miss ? mdT('roulette.no_prize_label') : (prize.label || mdT('roulette.prize'));
        if (resultExtra) {
          if (miss) {
            resultExtra.textContent = mdT('roulette.miss');
            spinBtn.disabled = false;
            spinBtn.textContent = mdT('roulette.spin_again');
          } else {
            var winCode = resolvePrizeCode(prize);
            if (winCode) {
              resultExtra.textContent = mdT('roulette.won_code', { code: winCode });
              applyCoupon(winCode);
              if (resultCodeEl) resultCodeEl.textContent = winCode;
              if (resultCopyRow) resultCopyRow.classList.remove('md-hide');
            } else {
              resultExtra.textContent = mdT('roulette.won');
            }
          }
        }
      }, ms + 80);
    });

    renderFabState();

    if (cfg.auto_open !== false && !readLock()) {
      var delay = parseInt(cfg.auto_open_delay_ms, 10) || 1500;
      setTimeout(function () {
        if (!readLock()) openOverlay();
      }, delay);
    }
  })();

  // Urgencia: tienda real y preview/sandbox
  (function initUrgency() {
    var mods = MD.modules || {};
    var urgencyOn = mods.urgency === true || mods.urgency === 1 || mods.urgency === '1';
    if (!urgencyOn && !MD.sandbox && !MD.preview) return;
    if (!urgencyOn && !(MD.sandbox || MD.preview)) return;

    var cfg = MD.urgency || {};
    var left = 14 * 60 + 59;
    if (cfg.ends_at) {
      var end = Date.parse(cfg.ends_at);
      if (!isNaN(end)) left = Math.max(0, Math.floor((end - Date.now()) / 1000));
    }
    var stock = parseInt(cfg.stock, 10) || 7;
    var starName = (MD.star_product && (MD.star_product.name || MD.star_product.title))
      ? String(MD.star_product.name || MD.star_product.title)
      : '';
    var safeName = String(starName || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
    var urgencyLabel = cfg.bar_text
      ? String(cfg.bar_text)
      : (starName ? mdT('urgency.left_star', { name: safeName }) : mdT('urgency.left_units'));

    if (MD.engine !== 'twig') {
      var box = typeof mdDedupeUrgency === 'function' ? mdDedupeUrgency(true) : mdCanonicalUrgency();
      if (!box) return;
      var copy = box.querySelector('[data-md-urgency-copy]');
      var title = box.querySelector('[data-md-urgency-title]');
      var note = box.querySelector('[data-md-urgency-note]');
      if (title) title.remove();
      if (note) note.remove();
      box.querySelectorAll('h1,h2,h3,h4,.md-mod-title,.md-urgency__title').forEach(function (el) {
        if (/urgency\s*\(demo\)/i.test(el.textContent || '')) el.remove();
      });
      Array.prototype.slice.call(box.childNodes).forEach(function (node) {
        if (node.nodeType === 3 && /urgency\s*\(demo\)/i.test(node.textContent || '')) node.textContent = '';
      });
      box.querySelectorAll('*').forEach(function (el) {
        if (el.children.length) return;
        if (/^\s*⏱?\s*Urgency\s*\(demo\)\s*$/i.test(el.textContent || '')) el.remove();
      });
      if (copy) {
        copy.innerHTML = urgencyLabel;
      } else if (!box.querySelector('[data-md-urgency-stock], #md-urgency-stock, [data-md-urgency-timer], #md-urgency-timer')) {
        box.innerHTML = urgencyLabel;
      }
      setModuleEnabled(box, true);
    }

    var stockEls = document.querySelectorAll('#md-urgency-stock, [data-md-urgency-stock]');
    var timerEls = document.querySelectorAll('#md-urgency-timer, [data-md-urgency-timer]');

    function paintUrgency() {
      var m = Math.floor(left / 60);
      var s = left % 60;
      var clock = m + ':' + (s < 10 ? '0' : '') + s;
      timerEls.forEach(function (el) { el.textContent = clock; });
      stockEls.forEach(function (el) { el.textContent = String(stock); });
    }
    paintUrgency();
    setInterval(function () {
      if (left > 0) left -= 1;
      paintUrgency();
    }, 1000);
  })();

  (function bindStorefrontUpsell() {
    var mods = MD.modules || {};
    if (!(mods.upsell === true || mods.upsell === 1 || mods.upsell === '1')) return;
    var box = document.getElementById('md-upsell-demo') || document.querySelector('[data-md-module="upsell"]');
    if (!box) return;
    var close = document.getElementById('md-upsell-close');
    var accept = document.getElementById('md-upsell-accept');
    var offer = MD.upsell || {};
    var prod = offer.offer_product || null;
    var pct = Number(offer.discount_percent || 20);
    if (!prod) {
      var src = MD.star_product || (MD.products && MD.products[0]) || null;
      if (src && src.id) {
        var price = Number(src.price || 0);
        var sale = Math.round(price * (1 - (pct / 100)) * 100) / 100;
        prod = {
          id: src.id,
          name: src.name || src.title || 'Combo',
          image: src.image || '',
          price: price,
          price_formatted: src.price_formatted || ('$' + price.toFixed(2)),
          sale_price: sale,
          sale_price_formatted: '$' + sale.toFixed(2)
        };
        if (!offer.copy) {
          offer.copy = 'Lleva «' + prod.name + '» en combo con ' + pct + '% OFF.';
        }
        if (!offer.offer_product_id) offer.offer_product_id = prod.id;
      }
    }
    var copyEl = document.getElementById('md-upsell-copy');
    var prodWrap = document.getElementById('md-upsell-product');
    var msgEl = document.getElementById('md-upsell-msg');
    var headEl = box.querySelector('.md-mod-upsell-head strong');
    if (headEl && offer.headline) headEl.textContent = String(offer.headline);
    if (copyEl && offer.copy) copyEl.textContent = String(offer.copy);
    if (accept && offer.cta) accept.textContent = offer.cta;
    if (prod && prodWrap) {
      var img = document.getElementById('md-upsell-img');
      var name = document.getElementById('md-upsell-name');
      var was = document.getElementById('md-upsell-was');
      var now = document.getElementById('md-upsell-now');
      if (name) name.textContent = prod.name || 'Combo';
      if (was) was.textContent = prod.price_formatted || '';
      if (now) now.textContent = prod.sale_price_formatted || '';
      if (img) {
        if (prod.image) {
          img.src = prod.image;
          img.alt = prod.name || '';
          img.style.display = '';
        } else {
          img.removeAttribute('src');
          img.style.display = 'none';
        }
      }
      prodWrap.hidden = false;
      prodWrap.removeAttribute('hidden');
    }
    setModuleEnabled(box, true);
    if (close && box) close.addEventListener('click', function () { box.classList.add('md-hide'); });
    if (accept) accept.setAttribute('data-md-upsell-accept', '');
    if (box._mdUpsellBound) return;
    box._mdUpsellBound = true;
    box.addEventListener('click', function (e) {
      var btn = e.target.closest('#md-upsell-accept, [data-md-upsell-accept]');
      if (!btn || !box.contains(btn)) return;
      e.preventDefault();
      e.stopPropagation();
      if (btn.disabled) return;
      btn.disabled = true;
      var prev = btn.textContent;
      btn.textContent = mdT('upsell.adding');
      if (msgEl) { msgEl.hidden = true; msgEl.textContent = ''; }

      function goToCart(redirect) {
        var dest = redirect || (Multidrop.urls && Multidrop.urls.cart) || '';
        if (dest) {
          window.location.assign(dest);
          return true;
        }
        return false;
      }

      function done(ok, message, cart, redirect) {
        if (cart && window.Multidrop) {
          Multidrop.cart = cart;
          try {
            document.dispatchEvent(new CustomEvent('md:cart:sync', { detail: { cart: cart } }));
          } catch (errSync) {}
        }
        if (ok) {
          btn.textContent = mdT('upsell.added');
          if (goToCart(redirect)) return;
          if (msgEl) {
            msgEl.hidden = false;
            msgEl.textContent = message || mdT('upsell.added');
          }
          setTimeout(function () {
            box.classList.add('md-hide');
            box.setAttribute('hidden', 'hidden');
          }, 800);
          return;
        }
        btn.disabled = false;
        btn.textContent = prev;
        if (msgEl) {
          msgEl.hidden = false;
          msgEl.textContent = message || mdT('upsell.error');
        }
      }

      var url = Multidrop.urls && Multidrop.urls.cart_upsell;
      var pid = (offer.offer_product_id || (prod && prod.id) || 0);
      if (!url) {
        done(false, mdT('upsell.no_route'), null, '');
        return;
      }
      fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': Multidrop.csrf || (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
        },
        credentials: 'same-origin',
        body: JSON.stringify(pid ? { product_id: pid } : {})
      }).then(function (r) {
        return r.text().then(function (t) {
          var j = {};
          try { j = t ? JSON.parse(t) : {}; } catch (errParse) { j = { ok: false, message: mdT('upsell.error') }; }
          return { okHttp: r.ok, j: j };
        });
      }).then(function (res) {
        var j = res.j || {};
        done(!!(res.okHttp && j.ok), j.message || '', j.cart || null, j.redirect || '');
      }).catch(function () {
        done(false, mdT('upsell.network'), null, '');
      });
    });
  })();
})();
</script>
@if(trim($js) !== '')
<script>{!! $js !!}</script>
@endif
<script>
(function () {
  var MD = window.Multidrop;
  if (!MD || !MD.Cart || typeof MD.Cart.add !== 'function') return;
  if (MD.Cart._mdAtcWrapped) return;
  var orig = MD.Cart.add;
  var show = MD.ui && typeof MD.ui.showAddedToCartModal === 'function'
    ? MD.ui.showAddedToCartModal
    : null;
  MD.Cart.add = function (id, qty, opts) {
    opts = opts || {};
    var inner = {};
    for (var k in opts) if (Object.prototype.hasOwnProperty.call(opts, k)) inner[k] = opts[k];
    inner.silent = true;
    var result;
    try { result = orig.call(MD.Cart, id, qty, inner); }
    catch (err) { return Promise.reject(err); }
    return Promise.resolve(result).then(function (cart) {
      if (!opts.silent && show) show(id, qty, cart || MD.cart);
      return cart;
    });
  };
  MD.Cart._mdAtcWrapped = true;
})();
</script>
</body>
</html>

