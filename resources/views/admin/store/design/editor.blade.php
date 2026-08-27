@extends('layouts.admin')

@section('title', 'Editor visual — '.$pageTitle)
@section('heading', 'Editor visual')
@section('subheading', $pageTypeLabel.' · '.$pageTitle.' · '.$store->name)

@section('content')
<link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.13/dist/css/grapes.min.css">
<style>
  .gjs-editor-wrap { min-height: calc(100vh - 180px); border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; background: #fff; }
  #gjs { min-height: calc(100vh - 180px); }
  .gjs-one-bg { background: #f8fafc; }
  .gjs-two-color { color: #0f172a; }
  .md-editor-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
</style>

@php
    $editorBackUrl = $editorBackUrl ?? route('admin.store.design.edit');
    $editorCodeUrl = $editorCodeUrl ?? route('admin.store.design.pages.edit', $pageId);
    $editorPreviewUrl = $editorPreviewUrl ?? route('admin.store.design.preview', ['page' => $pageId]);
@endphp
<div class="md-editor-toolbar">
    <a href="{{ $editorBackUrl }}" class="admin-btn-secondary">← Diseños</a>
    <a href="{{ $editorCodeUrl }}" class="admin-btn-secondary">Código</a>
    <a href="{{ $editorPreviewUrl }}" target="_blank" class="admin-btn-secondary">Preview</a>
    <button type="button" class="admin-btn" id="gjs-save">Guardar</button>
    <span id="gjs-status" class="text-xs text-ink-soft/60"></span>
</div>

<div class="gjs-editor-wrap">
    <div id="gjs"></div>
</div>

<script type="application/json" id="gjs-html">@json($editorHtml)</script>
<script type="application/json" id="gjs-css">@json($editorCss)</script>
<script type="application/json" id="gjs-canvas-styles">@json($editorCanvasStyles ?? [])</script>
<script type="application/json" id="gjs-body-attrs">@json($editorBodyAttrs ?? ['class'=>'','id'=>'','style'=>''])</script>
<script type="application/json" id="gjs-products-url">@json($productsJsonUrl)</script>
<script type="application/json" id="gjs-save-url">@json($editorSaveUrl)</script>
<script type="application/json" id="gjs-csrf">@json(csrf_token())</script>
<script type="application/json" id="gjs-products">@json($editorProducts)</script>
@endsection

@push('scripts')
<script src="https://unpkg.com/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script>
(function () {
  function readJson(id, fallback) {
    try { return JSON.parse(document.getElementById(id).textContent || 'null') ?? fallback; }
    catch (e) { return fallback; }
  }
  var html = readJson('gjs-html', '');
  var css = readJson('gjs-css', '');
  var extraStyles = readJson('gjs-canvas-styles', []);
  var bodyAttrs = readJson('gjs-body-attrs', { class: '', id: '', style: '' });
  var products = readJson('gjs-products', []);
  var saveUrl = readJson('gjs-save-url', '');
  var csrf = readJson('gjs-csrf', '');

  var themeCssUrl = '';
  if (css) {
    try { themeCssUrl = URL.createObjectURL(new Blob([css], { type: 'text/css' })); } catch (e) {}
  }
  var canvasStyles = ['https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap']
    .concat(Array.isArray(extraStyles) ? extraStyles : [])
    .concat(themeCssUrl ? [themeCssUrl] : []);

  var editor = grapesjs.init({
    container: '#gjs',
    height: 'calc(100vh - 180px)',
    fromElement: false,
    storageManager: false,
    noticeOnUnload: false,
    canvas: {
      styles: canvasStyles
    },
    deviceManager: {
      devices: [
        { name: 'Desktop', width: '' },
        { name: 'Tablet', width: '768px' },
        { name: 'Mobile', width: '375px' }
      ]
    },
    blockManager: { appendTo: undefined },
    styleManager: { sectors: [
      { name: 'Dimensiones', open: false, buildProps: ['width', 'height', 'padding', 'margin'] },
      { name: 'Tipografía', open: false, buildProps: ['font-family', 'font-size', 'font-weight', 'color', 'text-align'] },
      { name: 'Decoración', open: false, buildProps: ['background-color', 'border', 'border-radius', 'box-shadow'] }
    ]}
  });

  editor.setComponents(html || '<section class="md-section"><h1>Nueva página</h1></section>');

  function applyThemeToFrame() {
    var doc;
    try { doc = editor.Canvas.getDocument(); } catch (e) { return; }
    if (!doc || !doc.head) return;

    var style = doc.getElementById('md-theme-css');
    if (!style) {
      style = doc.createElement('style');
      style.id = 'md-theme-css';
      doc.head.appendChild(style);
    }
    style.textContent = css || '';

    var body = doc.body;
    if (!body) return;
    if (bodyAttrs && bodyAttrs.class) {
      String(bodyAttrs.class).split(/\s+/).forEach(function (c) {
        if (c) body.classList.add(c);
      });
    }
    if (bodyAttrs && bodyAttrs.id && !body.id) body.id = bodyAttrs.id;
    if (bodyAttrs && bodyAttrs.style) {
      body.setAttribute('style', ((body.getAttribute('style') || '') + ';' + bodyAttrs.style).replace(/^;/, ''));
    }
  }
  editor.on('load', applyThemeToFrame);
  editor.on('canvas:frame:load', applyThemeToFrame);

  var bm = editor.BlockManager;
  bm.add('md-hero', {
    label: 'Hero',
    category: 'Multidrop',
    content: '<section class="md-hero"><h1>@{{store.name}}</h1><p>Landing principal</p><a class="md-btn" href="@{{urls.catalog}}">Ver catálogo</a></section>'
  });
  bm.add('md-products', {
    label: 'Grid productos',
    category: 'Catálogo',
    content: '<section class="md-section"><h2>Productos</h2><div data-md-products data-md-limit="8" class="md-grid"></div></section>'
  });
  bm.add('md-featured', {
    label: 'Destacados',
    category: 'Catálogo',
    content: '<section class="md-section"><h2>Destacados</h2><div data-md-products data-md-featured="1" data-md-limit="4" class="md-grid"></div></section>'
  });
  bm.add('md-pdp', {
    label: 'Ficha producto',
    category: 'Catálogo',
    content: '<section class="md-section md-pdp" data-md-product><div class="md-pdp-media"><img data-md-bind="product.image" alt=""></div><div class="md-pdp-info"><p class="md-badge" data-md-bind="product.badge"></p><h1 data-md-bind="product.name">Producto</h1><p class="md-price" data-md-bind="product.price_formatted"></p><div data-md-bind="product.description"></div><button type="button" class="md-btn" data-md-add-to-cart>Agregar al carrito</button></div></section>'
  });
  bm.add('md-add-cart', {
    label: 'Add to cart',
    category: 'Comercio',
    content: '<button type="button" class="md-btn" data-md-add-to-cart>Agregar al carrito</button>'
  });
  bm.add('md-cart', {
    label: 'Carrito',
    category: 'Comercio',
    content: '<div><span data-md-cart-count>0</span> items</div><div data-md-cart class="md-cart"></div>'
  });
  bm.add('md-coupon', {
    label: 'Cupón',
    category: 'Comercio',
    content: '<form data-md-coupon-form class="md-coupon"><input name="code" placeholder="Cupón" required><button type="submit" class="md-btn">Aplicar</button><p data-md-coupon-msg></p></form>'
  });
  bm.add('md-checkout', {
    label: 'Checkout invitado',
    category: 'Comercio',
    content: '<form data-md-checkout-form class="md-checkout"><h2>Checkout</h2><input name="name" placeholder="Nombre" required><input name="email" type="email" placeholder="Email" required><input name="phone" placeholder="Teléfono"><input name="address" placeholder="Dirección" required><input name="city" placeholder="Ciudad" required><input name="state" placeholder="Estado"><input name="zip" placeholder="CP"><input name="country" placeholder="País (MX)" value="MX"><div data-md-cart></div><p data-md-checkout-totals></p><button type="submit" class="md-btn">Pagar</button><p data-md-checkout-msg></p></form>'
  });
  bm.add('md-nav', {
    label: 'Nav tienda',
    category: 'Multidrop',
    content: '<header class="md-nav"><a href="@{{urls.home}}" class="md-logo">@{{store.name}}</a><nav><a href="@{{urls.home}}">Inicio</a><a href="@{{urls.catalog}}">Catálogo</a><a href="@{{urls.cart}}">Carrito <span data-md-cart-count>0</span></a></nav></header>'
  });

  products.forEach(function (p) {
    bm.add('md-product-' + p.id, {
      label: (p.name || 'Producto').slice(0, 28),
      category: 'Productos tienda',
      media: p.image ? '<img src="'+p.image+'" style="width:48px;height:48px;object-fit:cover;border-radius:8px">' : '',
      content: '<a class="md-card" href="'+(p.url || ('@{{urls.catalog}}'))+'">' +
        (p.image ? '<img src="'+p.image+'" alt="">' : '<div style="aspect-ratio:1;background:#eee"></div>') +
        '<div class="meta"><h3>'+(p.name||'')+'</h3><div class="price">'+(p.price_formatted||'')+'</div></div></a>'
    });
  });

  function paintProductsInFrame() {
    try {
      var doc = editor.Canvas.getDocument();
      if (!doc) return;
      doc.querySelectorAll('[data-md-products]').forEach(function (root) {
        if (root.children.length) return;
        var list = products.slice();
        if (root.hasAttribute('data-md-featured')) {
          var feat = list.filter(function (p) { return p.featured; });
          if (feat.length) list = feat;
        }
        var limit = parseInt(root.getAttribute('data-md-limit') || '0', 10);
        if (limit > 0) list = list.slice(0, limit);
        root.innerHTML = list.map(function (p) {
          return '<a class="md-card" href="#"><img src="'+(p.image||'')+'" alt=""><div class="meta"><h3>'+(p.name||'')+'</h3><div class="price">'+(p.price_formatted||'')+'</div></div></a>';
        }).join('') || '<p>No hay productos en esta tienda.</p>';
      });
    } catch (e) {}
  }
  editor.on('load', paintProductsInFrame);
  editor.on('component:add', function () { setTimeout(paintProductsInFrame, 50); });

  $('#gjs-save').on('click', function () {
    var $btn = $(this);
    var $st = $('#gjs-status');
    $btn.prop('disabled', true);
    $st.text('Guardando…');
    $.ajax({
      url: saveUrl,
      method: 'POST',
      data: {
        _token: csrf,
        html: editor.getHtml()
      }
    }).done(function (res) {
      $st.text(res.message || 'Guardado');
      if (window.AdminToast) AdminToast.success(res.message || 'Guardado');
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Error al guardar';
      $st.text(msg);
      if (window.AdminToast) AdminToast.error(msg);
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });
})();
</script>
@endpush
