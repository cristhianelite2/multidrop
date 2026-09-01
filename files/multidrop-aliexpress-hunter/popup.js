(function () {
  var d = window.MULTIDROP_DEFAULTS || {};
  var originEl = document.getElementById('origin');
  var tokenEl = document.getElementById('token');
  var storeEl = document.getElementById('store');
  var status = document.getElementById('status');
  var setupBlock = document.getElementById('setup-block');
  var readyBlock = document.getElementById('ready-block');
  var tokenWrap = document.getElementById('token-wrap');
  var originLabel = document.getElementById('origin-label');
  var storeMeta = document.getElementById('store-meta');
  var productSkuEl = document.getElementById('product-sku');
  var productFound = document.getElementById('product-found');
  var extractWrap = document.getElementById('extract-wrap');
  var extractToggle = document.getElementById('extract-toggle');
  var extractSummary = document.getElementById('extract-summary');
  var selectedProduct = null;

  function setExtractOpen(open, persist) {
    if (!extractWrap || !extractToggle) return;
    extractWrap.classList.toggle('is-open', !!open);
    extractToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (persist !== false) {
      chrome.storage.sync.set({ extract_panel_open: !!open });
    }
    updateExtractSummary();
  }

  function updateExtractSummary() {
    if (!extractSummary) return;
    var open = extractWrap && extractWrap.classList.contains('is-open');
    if (open) {
      extractSummary.textContent = selectedProduct
        ? ('Destino #' + selectedProduct.id + (selectedProduct.sku ? (' · ' + selectedProduct.sku) : ''))
        : 'Expandido';
      return;
    }
    if (selectedProduct) {
      extractSummary.textContent = 'Destino #' + selectedProduct.id + (selectedProduct.sku ? (' · SKU ' + selectedProduct.sku) : '') + ' · expandir';
      return;
    }
    extractSummary.textContent = 'Comprimido · pulsa para expandir';
  }

  function setStatus(text, kind) {
    status.textContent = text || '';
    status.className = kind === 'error' ? 'is-error' : (kind === 'ok' ? 'is-ok' : '');
  }

  function defaults() { return d; }

  function apiPath(origin, key) {
    return String(origin || '').replace(/\/+$/, '') + (d[key] || '');
  }

  function fillStores(stores, selectedId) {
    storeEl.innerHTML = '';
    (stores || []).forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = String(s.id);
      opt.textContent = s.name + (s.market ? (' · ' + s.market) : '');
      storeEl.appendChild(opt);
    });
    if (selectedId) storeEl.value = String(selectedId);
    if (!storeEl.value && storeEl.options.length) storeEl.selectedIndex = 0;
    storeMeta.textContent = storeEl.options.length
      ? (storeEl.options.length + ' tienda(s) disponible(s)')
      : 'No hay tiendas en Multidrop';
  }

  function showReady(origin, stores, storeId) {
    setupBlock.classList.add('hidden');
    readyBlock.classList.remove('hidden');
    tokenWrap.classList.add('hidden');
    originLabel.textContent = 'Conectado a ' + origin;
    fillStores(stores, storeId);
  }

  function showSetup(keepToken) {
    readyBlock.classList.add('hidden');
    setupBlock.classList.remove('hidden');
    tokenWrap.classList.remove('hidden');
    if (!keepToken) tokenEl.value = '';
  }

  function showSelectedProduct(product) {
    selectedProduct = product || null;
    if (!product) {
      productFound.classList.add('hidden');
      productFound.textContent = '';
      chrome.storage.sync.remove(['selected_product_id', 'selected_product_sku']);
      updateExtractSummary();
      return;
    }
    productFound.classList.remove('hidden');
    productFound.textContent = 'Destino: #' + product.id + ' · ' + product.name + (product.sku ? (' · SKU ' + product.sku) : '');
    chrome.storage.sync.set({
      selected_product_id: product.id,
      selected_product_sku: product.sku || ''
    });
    updateExtractSummary();
  }

  async function requestHosts(origin) {
    if (!origin || !chrome.permissions || !chrome.permissions.request) return;
    try { await chrome.permissions.request({ origins: [origin + '/*'] }); } catch (e) {}
  }

  async function validateAndLoad(origin, token) {
    var res = await fetch(apiPath(origin, 'bootstrap_path'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Multidrop-Token': token
      },
      body: JSON.stringify({ token: token })
    });
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok || !json.success) throw new Error(json.error || ('HTTP ' + res.status));
    return json.stores || [];
  }

  function extractSections() {
    var sections = [];
    document.querySelectorAll('.ext-section:checked').forEach(function (el) {
      sections.push(String(el.value || ''));
    });
    return sections;
  }

  chrome.storage.sync.get(['origin', 'token', 'store_id', 'token_ok', 'selected_product_id', 'selected_product_sku', 'extract_panel_open'], function (cfg) {
    originEl.value = cfg.origin || d.origin || '';
    tokenEl.value = cfg.token || '';
    if (cfg.selected_product_sku) productSkuEl.value = cfg.selected_product_sku;
    setExtractOpen(!!cfg.extract_panel_open, false);
    if (cfg.token_ok && cfg.origin && cfg.token) {
      setStatus('Validando…');
      requestHosts(String(cfg.origin).replace(/\/+$/, '')).then(function () {
        return validateAndLoad(String(cfg.origin).replace(/\/+$/, ''), cfg.token);
      }).then(function (stores) {
        showReady(String(cfg.origin).replace(/\/+$/, ''), stores, cfg.store_id);
        setStatus('Listo', 'ok');
        if (cfg.selected_product_id) {
          showSelectedProduct({
            id: cfg.selected_product_id,
            name: 'Producto #' + cfg.selected_product_id,
            sku: cfg.selected_product_sku || ''
          });
        }
      }).catch(function () {
        chrome.storage.sync.set({ token_ok: false });
        showSetup(true);
        setStatus('Vuelve a validar el token', 'error');
      });
    } else {
      showSetup(true);
    }
  });

  document.getElementById('save').addEventListener('click', async function () {
    var origin = String(originEl.value || '').replace(/\/+$/, '');
    var token = String(tokenEl.value || '').trim();
    if (!origin || !token) {
      setStatus('Indica URL y token', 'error');
      return;
    }
    setStatus('Validando token…');
    document.getElementById('save').disabled = true;
    try {
      await requestHosts(origin);
      var stores = await validateAndLoad(origin, token);
      if (!stores.length) {
        setStatus('Token válido, pero no hay tiendas.', 'error');
        document.getElementById('save').disabled = false;
        return;
      }
      chrome.storage.sync.set({ origin: origin, token: token, token_ok: true, store_id: stores[0].id }, function () {
        showReady(origin, stores, stores[0].id);
        setStatus('Token válido.', 'ok');
        document.getElementById('save').disabled = false;
      });
    } catch (e) {
      chrome.storage.sync.set({ token_ok: false });
      setStatus(String(e && e.message ? e.message : e), 'error');
      document.getElementById('save').disabled = false;
    }
  });

  storeEl.addEventListener('change', function () {
    var id = parseInt(storeEl.value, 10) || 0;
    if (id) chrome.storage.sync.set({ store_id: id });
    showSelectedProduct(null);
  });

  document.getElementById('change-token').addEventListener('click', function () {
    chrome.storage.sync.set({ token_ok: false }, function () {
      showSetup(true);
      setStatus('Pega el nuevo token y valida');
    });
  });

  if (extractToggle) {
    extractToggle.addEventListener('click', function () {
      var open = !extractWrap.classList.contains('is-open');
      setExtractOpen(open);
    });
  }

  document.getElementById('capture').addEventListener('click', function () {
    var btn = document.getElementById('capture');
    btn.disabled = true;
    setStatus('Capturando ficha…');
    var storeId = parseInt(storeEl.value, 10) || 0;
    chrome.storage.sync.set({ store_id: storeId }, function () {
      chrome.runtime.sendMessage({ type: 'MULTIDROP_RUN_CAPTURE', store_id: storeId }, function (res) {
        btn.disabled = false;
        if (res && res.ok) setStatus(res.message || 'Enviado a borrador', 'ok');
        else setStatus((res && res.error) || 'Error al capturar', 'error');
      });
    });
  });

  document.getElementById('search-sku').addEventListener('click', async function () {
    var sku = String(productSkuEl.value || '').trim();
    var storeId = parseInt(storeEl.value, 10) || 0;
    if (!sku || !storeId) {
      setStatus('Indica SKU y tienda', 'error');
      return;
    }
    var cfg = await chrome.storage.sync.get(['origin', 'token']);
    var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
    var token = String(cfg.token || '');
    setStatus('Buscando producto…');
  document.getElementById('search-sku').disabled = true;
    try {
      var res = await fetch(apiPath(origin, 'product_search_path'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Multidrop-Token': token
        },
        body: JSON.stringify({ token: token, store_id: storeId, sku: sku })
      });
      var json = await res.json().catch(function () { return {}; });
      if (!res.ok || !json.success) throw new Error(json.error || ('HTTP ' + res.status));
      showSelectedProduct(json.product);
      setStatus('Producto encontrado. Abre AE/CJ y pulsa Extraer.', 'ok');
    } catch (e) {
      showSelectedProduct(null);
      setStatus(String(e && e.message ? e.message : e), 'error');
    }
    document.getElementById('search-sku').disabled = false;
  });

  document.getElementById('extract').addEventListener('click', function () {
    if (!selectedProduct || !selectedProduct.id) {
      setStatus('Busca primero el SKU del producto destino', 'error');
      return;
    }
    var sections = extractSections();
    if (!sections.length) {
      setStatus('Marca al menos una sección', 'error');
      return;
    }
    var btn = document.getElementById('extract');
    btn.disabled = true;
    setStatus('Extrayendo de la página activa…');
    var storeId = parseInt(storeEl.value, 10) || 0;
    chrome.runtime.sendMessage({
      type: 'MULTIDROP_RUN_EXTRACT',
      store_id: storeId,
      product_id: selectedProduct.id,
      sections: sections,
      replace: document.getElementById('extract-replace').checked
    }, function (res) {
      btn.disabled = false;
      if (res && res.ok) {
        setStatus(res.message || 'Importado al producto', 'ok');
      } else {
        setStatus((res && res.error) || 'Error al extraer', 'error');
      }
    });
  });
})();
