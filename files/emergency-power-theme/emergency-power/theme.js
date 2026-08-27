/* ==========================================================================
   Emergency Power — theme.js
   Shared runtime for every template page. Reads window.Multidrop (injected
   server-side) and wires the data-md-* hooks. Safe to load on any page;
   each init function no-ops if its markup isn't present.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var CART_STORAGE_PREFIX = 'md_cart__';

  /* ------------------------------------------------------------------ */
  /* Data source                                                         */
  /* Reads the store-injected window.Multidrop, and fills in safe empty  */
  /* defaults so every page can render even before real data is present  */
  /* (e.g. static preview outside the platform).                         */
  /* ------------------------------------------------------------------ */
  var MD = window.Multidrop || {};
  MD.store = MD.store || { name: 'Emergency Power', slug: 'emergency-power', id: null };
  MD.products = Array.isArray(MD.products) ? MD.products : [];
  MD.product = MD.product || null;
  MD.cart = MD.cart || null;
  MD.page = MD.page || null;
  MD.checkout = MD.checkout || null;
  MD.urls = MD.urls || {};
  MD.csrf = MD.csrf || null;
  window.Multidrop = MD;

  /* ------------------------------------------------------------------ */
  /* Utilities                                                           */
  /* ------------------------------------------------------------------ */
  function qs(sel, scope) { return (scope || document).querySelector(sel); }
  function qsa(sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); }

  function formatPrice(value, currency) {
    if (value === null || value === undefined || isNaN(Number(value))) return '';
    var amount = Number(value);
    var code = currency || (window.Multidrop && Multidrop.currency) || 'USD';
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).format(amount);
    } catch (e) {
      return '$' + amount.toFixed(2);
    }
  }

  function fieldValue(product, field) {
    if (!product) return '';
    switch (field) {
      case 'name': return product.name || product.title || '';
      case 'price_formatted': return product.price_formatted || formatPrice(product.price, product.currency);
      case 'compare_at_formatted': return product.on_sale ? (product.compare_at_formatted || formatPrice(product.compare_at_price, product.currency)) : '';
      case 'save_percent': return product.on_sale && product.save_percent ? ('−' + product.save_percent + '%') : '';
      case 'image': return (product.image || (product.images && product.images[0])) || '';
      case 'badge': return product.badge || '';
      case 'description': return product.description || product.summary || '';
      default: return product[field] != null ? product[field] : '';
    }
  }

  /* Applies data-md-bind="field" to every matching descendant of `scope`,
     reading values from `product`. Centralised so product cards, the PDP
     and any future bound region all share one mapping implementation. */
  function bindFields(scope, product) {
    qsa('[data-md-bind]', scope).forEach(function (el) {
      var field = el.getAttribute('data-md-bind');
      var value = fieldValue(product, field);

      if (field === 'image') {
        if (el.tagName === 'IMG') {
          el.src = value;
          el.alt = fieldValue(product, 'name');
        } else {
          el.style.backgroundImage = value ? 'url(' + value + ')' : '';
        }
        return;
      }

      if (field === 'badge' || field === 'compare_at_formatted' || field === 'save_percent') {
        el.textContent = value;
        el.classList.toggle('md-hide', !value);
        return;
      }

      if (el.getAttribute('data-md-bind-as') === 'html') {
        el.innerHTML = value;
      } else {
        el.textContent = value;
      }
    });
  }

  /* ------------------------------------------------------------------ */
  /* Product card template                                               */
  /* ------------------------------------------------------------------ */
  function buildProductCard(product) {
    var el = document.createElement('article');
    el.className = 'md-card md-bracket';
    el.setAttribute('data-md-product', product.id != null ? product.id : product.handle || '');

    var chargePct = product.charge_pct != null ? product.charge_pct : null;

    el.innerHTML =
      '<a class="md-card__media" href="' + (product.url || '#') + '">' +
        '<span class="md-card__badge" data-md-bind="badge"></span>' +
        '<img data-md-bind="image" loading="lazy" alt="">' +
      '</a>' +
      '<div class="md-card__body">' +
        '<h3 class="md-card__name" data-md-bind="name"></h3>' +
        '<p class="md-card__desc" data-md-bind="description"></p>' +
        (chargePct != null ?
          '<div class="md-charge-bar" aria-hidden="true">' +
            '<span class="md-charge-bar__track"><span class="md-charge-bar__fill" style="width:' + chargePct + '%"></span></span>' +
            '<span class="md-charge-bar__label">' + chargePct + '%</span>' +
          '</div>' : '') +
        '<div class="md-card__footer">' +
          salePriceHtml(product) +
          '<button type="button" class="md-btn md-btn--primary" data-md-add-to-cart data-product-id="' + (product.id != null ? product.id : product.handle || '') + '">Add</button>' +
        '</div>' +
      '</div>';

    bindFields(el, product);
    return el;
  }

  function salePriceHtml(product) {
    var now = fieldValue(product, 'price_formatted');
    var was = fieldValue(product, 'compare_at_formatted');
    var save = fieldValue(product, 'save_percent');
    return '<span class="md-card__price md-price-row">' +
      (was ? '<s class="md-price-was">' + was + '</s>' : '') +
      '<span class="md-price" data-md-bind="price_formatted">' + now + '</span>' +
      (save ? '<span class="md-price-save">' + save + '</span>' : '') +
    '</span>';
  }

  function emptyStateHtml(title, message) {
    return '<div class="md-empty"><p class="md-h3">' + title + '</p><p>' + message + '</p></div>';
  }

  /* ------------------------------------------------------------------ */
  /* [data-md-products] — auto-populated product grids                   */
  /* Supports: data-md-limit="8" data-md-featured data-md-manual="h1,h2"  */
  /* ------------------------------------------------------------------ */
  function renderProductGrids() {
    qsa('[data-md-products]').forEach(function (container) {
      var products = MD.products.slice();

      if (container.hasAttribute('data-md-featured')) {
        products = products.filter(function (p) { return !!p.featured; });
      }

      var manual = container.getAttribute('data-md-manual');
      if (manual) {
        var keys = manual.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        products = keys.map(function (key) {
          return products.filter(function (p) { return p.handle === key || String(p.id) === key; })[0];
        }).filter(Boolean);
      }

      var limit = parseInt(container.getAttribute('data-md-limit'), 10);
      if (!isNaN(limit) && limit > 0) products = products.slice(0, limit);

      container.innerHTML = '';

      if (!products.length) {
        container.innerHTML = emptyStateHtml('No products yet', 'Add products in the store admin to fill this grid.');
        return;
      }

      if (!container.classList.contains('md-grid')) container.classList.add('md-grid');
      products.forEach(function (product) {
        container.appendChild(buildProductCard(product));
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Product detail page — binds window.Multidrop.product into           */
  /* [data-md-product] scope(s) already present in the page markup       */
  /* ------------------------------------------------------------------ */
  function initProductDetail() {
    if (!MD.product) return;
    qsa('[data-md-product]').forEach(function (scope) {
      // Skip cards generated by renderProductGrids (they set their own product).
      if (scope.closest('[data-md-products]')) return;
      bindFields(scope, MD.product);
    });
  }

  /* ------------------------------------------------------------------ */
  /* Cart                                                                 */
  /* Prefers a backend-provided API (window.Multidrop.api.*) when present */
  /* so the real platform can own persistence; falls back to localStorage */
  /* so templates are fully functional in preview/standalone contexts.    */
  /* ------------------------------------------------------------------ */
  var Cart = (function () {
    var storageKey = CART_STORAGE_PREFIX + (MD.store.slug || 'store');
    var hasBackendApi = !!(MD.api && typeof MD.api.addToCart === 'function');

    function apiEnabled() {
      return !!(MD.urls && (MD.urls.cart_add || MD.urls.cart_items));
    }

    function apiHeaders() {
      return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': MD.csrf || '',
        'X-Requested-With': 'XMLHttpRequest'
      };
    }

    function apiCall(url, method, body) {
      return fetch(url, {
        method: method || 'GET',
        headers: apiHeaders(),
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined
      }).then(function (r) {
        return r.json().then(function (j) {
          j._status = r.status;
          return j;
        });
      });
    }

    function applyServerCart(res) {
      if (res && res.cart) {
        MD.cart = res.cart;
        notify();
        return res.cart;
      }
      notify();
      return get();
    }

    function readLocal() {
      try {
        var raw = window.localStorage.getItem(storageKey);
        return raw ? JSON.parse(raw) : { items: [] };
      } catch (e) {
        return { items: [] };
      }
    }

    function writeLocal(cart) {
      try { window.localStorage.setItem(storageKey, JSON.stringify(cart)); } catch (e) { /* storage unavailable */ }
    }

    function findProduct(productId) {
      return MD.products.filter(function (p) { return String(p.id) === String(productId) || p.handle === productId; })[0]
        || (MD.product && (String(MD.product.id) === String(productId) || MD.product.handle === productId) ? MD.product : null);
    }

    function get() {
      if (MD.cart && Array.isArray(MD.cart.items)) return MD.cart;
      return readLocal();
    }

    function notify() {
      document.dispatchEvent(new CustomEvent('md:cart:change', { detail: get() }));
    }

    function add(productId, qty) {
      qty = qty || 1;
      if (hasBackendApi) {
        return MD.api.addToCart(productId, qty).then(function (cart) {
          MD.cart = cart;
          notify();
          return cart;
        });
      }
      if (apiEnabled() && MD.urls.cart_add) {
        return apiCall(MD.urls.cart_add, 'POST', { product_id: Number(productId) || productId, qty: qty })
          .then(applyServerCart);
      }
      var cart = readLocal();
      var line = cart.items.filter(function (i) { return String(i.product_id) === String(productId); })[0];
      if (line) {
        line.qty += qty;
      } else {
        var product = findProduct(productId);
        cart.items.push({
          product_id: productId,
          name: product ? fieldValue(product, 'name') : 'Product',
          image: product ? fieldValue(product, 'image') : '',
          price: product ? Number(product.price || 0) : 0,
          qty: qty
        });
      }
      writeLocal(cart);
      MD.cart = cart;
      notify();
      return Promise.resolve(cart);
    }

    function updateQty(productId, qty) {
      qty = Math.max(0, parseInt(qty, 10) || 0);
      if (hasBackendApi && typeof MD.api.updateCartItem === 'function') {
        return MD.api.updateCartItem(productId, qty).then(function (cart) { MD.cart = cart; notify(); return cart; });
      }
      if (apiEnabled() && MD.urls.cart_items) {
        if (qty <= 0) {
          return apiCall(MD.urls.cart_items + '/' + productId, 'DELETE').then(applyServerCart);
        }
        return apiCall(MD.urls.cart_items + '/' + productId, 'PATCH', { qty: qty }).then(applyServerCart);
      }
      var cart = (MD.cart && Array.isArray(MD.cart.items))
        ? { items: MD.cart.items.slice(), coupon: MD.cart.coupon, shipping_country: MD.cart.shipping_country }
        : readLocal();
      cart.items = (cart.items || [])
        .map(function (i) { return String(i.product_id) === String(productId) ? Object.assign({}, i, { qty: qty }) : i; })
        .filter(function (i) { return i.qty > 0; });
      writeLocal(cart);
      MD.cart = Object.assign({}, MD.cart || {}, cart);
      notify();
      return Promise.resolve(cart);
    }

    function remove(productId) { return updateQty(productId, 0); }

    function count(cart) {
      cart = cart || get();
      return (cart.items || []).reduce(function (sum, i) { return sum + (i.qty || 0); }, 0);
    }

    function subtotal(cart) {
      cart = cart || get();
      return (cart.items || []).reduce(function (sum, i) { return sum + (i.qty || 0) * (Number(i.price) || 0); }, 0);
    }

    return { get: get, add: add, updateQty: updateQty, remove: remove, count: count, subtotal: subtotal };
  })();
  MD.Cart = Cart;

  /* ------------------------------------------------------------------ */
  /* Add-to-cart buttons: [data-md-add-to-cart]                          */
  /* ------------------------------------------------------------------ */
  function wireAddToCart() {
    document.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-md-add-to-cart]');
      if (!btn) return;
      if (event._mdAddToCartHandled) return;
      event._mdAddToCartHandled = true;
      event.preventDefault();

      var scope = btn.closest('[data-md-product]');
      var productId = btn.getAttribute('data-product-id') ||
        (scope && scope.getAttribute('data-md-product')) ||
        (MD.product && MD.product.id);
      if (!productId) return;

      var qtyInput = scope && qs('[data-md-qty]', scope);
      var qty = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

      var originalLabel = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Adding…';
      Cart.add(productId, qty).then(function () {
        btn.textContent = 'Added';
        setTimeout(function () { btn.textContent = originalLabel; btn.disabled = false; }, 900);
      }).catch(function () {
        btn.textContent = originalLabel;
        btn.disabled = false;
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /* Cart page rendering: [data-md-cart]                                 */
  /* ------------------------------------------------------------------ */
  function buildCartRow(item) {
    var row = document.createElement('div');
    row.className = 'md-cart-row';
    row.setAttribute('data-cart-row', item.product_id);
    var href = String(item.url || '').trim();
    if (!href && item.slug && window.Multidrop && Multidrop.urls && Multidrop.urls.home) {
      href = String(Multidrop.urls.home).replace(/\/$/, '') + '/pages/' + encodeURIComponent(item.slug);
    }
    var mediaInner = '<img src="' + (item.image || '') + '" alt="" loading="lazy">';
    var media = href
      ? '<a class="md-cart-row__media md-cart-row__link" href="' + href.replace(/"/g, '&quot;') + '">' + mediaInner + '</a>'
      : '<div class="md-cart-row__media">' + mediaInner + '</div>';
    var name = href
      ? '<a class="md-cart-row__link" href="' + href.replace(/"/g, '&quot;') + '"><p class="md-cart-row__name">' + item.name + '</p></a>'
      : '<p class="md-cart-row__name">' + item.name + '</p>';
    row.innerHTML =
      media +
      '<div class="md-cart-row__info">' +
        name +
        '<p class="md-cart-row__price md-mono text-muted">' + formatPrice(item.price) + '</p>' +
      '</div>' +
      '<div class="md-cart-row__qty">' +
        '<button type="button" class="md-stepper" data-cart-decr aria-label="Decrease quantity">–</button>' +
        '<input type="number" min="1" value="' + item.qty + '" data-cart-qty aria-label="Quantity">' +
        '<button type="button" class="md-stepper" data-cart-incr aria-label="Increase quantity">+</button>' +
      '</div>' +
      '<p class="md-cart-row__line-total md-price">' + formatPrice(item.qty * item.price) + '</p>' +
      '<button type="button" class="md-cart-row__remove" data-cart-remove aria-label="Remove item">✕</button>';
    return row;
  }

  function renderCart() {
    var container = qs('[data-md-cart]');
    if (!container) return;

    var cart = Cart.get();
    var itemsEl = qs('[data-md-cart-items]', container) || container;
    var emptyEl = qs('[data-md-cart-empty]');
    var summaryEl = qs('[data-md-cart-summary]');

    itemsEl.querySelectorAll('[data-cart-row]').forEach(function (n) { n.remove(); });

    var hasItems = cart.items && cart.items.length;
    if (emptyEl) {
      emptyEl.classList.toggle('md-hide', !!hasItems);
      if (hasItems) emptyEl.setAttribute('hidden', 'hidden');
      else emptyEl.removeAttribute('hidden');
    }
    container.classList.toggle('md-hide', !hasItems);
    if (!hasItems) container.setAttribute('hidden', 'hidden');
    else container.removeAttribute('hidden');

    if (hasItems) {
      cart.items.forEach(function (item) { itemsEl.appendChild(buildCartRow(item)); });
    }

    if (summaryEl) {
      var subtotalEl = qs('[data-md-cart-subtotal]', summaryEl);
      if (subtotalEl) subtotalEl.textContent = formatPrice(Cart.subtotal(cart));
      var totalEl = qs('[data-md-cart-total]', summaryEl);
      if (totalEl) totalEl.textContent = formatPrice(Cart.subtotal(cart)); // no shipping calc in-theme; platform can override
      var checkoutBtn = qs('[data-md-cart-checkout]', summaryEl);
      if (checkoutBtn) checkoutBtn.classList.toggle('md-hide', !hasItems);
    }

    updateHeaderCartCount(cart);
  }

  function wireCartControls() {
    var container = qs('[data-md-cart]');
    if (!container) return;

    document.addEventListener('click', function (event) {
      var row = event.target.closest('[data-cart-row]');
      if (!row || !container.contains(row)) return;
      if (event._mdCartHandled) return;
      var productId = row.getAttribute('data-cart-row');
      var input = qs('[data-cart-qty]', row);

      if (event.target.closest('[data-cart-incr]')) {
        event._mdCartHandled = true;
        Cart.updateQty(productId, parseInt(input.value, 10) + 1);
      } else if (event.target.closest('[data-cart-decr]')) {
        event._mdCartHandled = true;
        Cart.updateQty(productId, Math.max(1, parseInt(input.value, 10) - 1));
      } else if (event.target.closest('[data-cart-remove]')) {
        event._mdCartHandled = true;
        event.preventDefault();
        Cart.remove(productId);
      }
    });

    document.addEventListener('change', function (event) {
      if (!event.target.matches('[data-cart-qty]')) return;
      var row = event.target.closest('[data-cart-row]');
      if (!row || !container.contains(row)) return;
      var qty = Math.max(1, parseInt(event.target.value, 10) || 1);
      Cart.updateQty(row.getAttribute('data-cart-row'), qty);
    });
  }

  /* ------------------------------------------------------------------ */
  /* Header cart badge — kept in sync on every page                      */
  /* ------------------------------------------------------------------ */
  function updateHeaderCartCount(cart) {
    var badge = qs('[data-md-cart-count]');
    if (!badge) return;
    var n = Cart.count(cart);
    badge.textContent = n;
    badge.classList.toggle('md-hide', n === 0);
  }

  /* ------------------------------------------------------------------ */
  /* Mobile nav toggle                                                   */
  /* ------------------------------------------------------------------ */
  function wireMobileNav() {
    /* El toggle lo cablea custom.blade.php (captura) para Twig y HTML. */
  }

  /* ------------------------------------------------------------------ */
  /* Power gauge sweep-in on scroll                                      */
  /* ------------------------------------------------------------------ */
  function wireGauges() {
    var gauges = qsa('.md-gauge');
    if (!gauges.length || !('IntersectionObserver' in window)) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-active');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.4 });
    gauges.forEach(function (g) { io.observe(g); });
  }

  function setFooterYear() {
    qsa('[data-year]').forEach(function (el) { el.textContent = new Date().getFullYear(); });
  }

  /* ------------------------------------------------------------------ */
  /* Boot                                                                 */
  /* ------------------------------------------------------------------ */
  document.addEventListener('DOMContentLoaded', function () {
    setFooterYear();
    renderProductGrids();
    initProductDetail();
    wireAddToCart();
    renderCart();
    wireCartControls();
    wireMobileNav();
    wireGauges();
    updateHeaderCartCount();
  });

  document.addEventListener('md:cart:change', function () {
    renderCart();
  });

  /* Exposed so page-specific scripts (e.g. catalog sorting) can reuse the
     same card markup / binding logic instead of duplicating it. */
  MD.Theme = {
    buildProductCard: buildProductCard,
    bindFields: bindFields,
    formatPrice: formatPrice,
    renderProductGrids: renderProductGrids,
    emptyStateHtml: emptyStateHtml
  };

})(window, document);
