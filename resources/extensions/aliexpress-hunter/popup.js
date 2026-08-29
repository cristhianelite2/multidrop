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

  function setStatus(text, kind) {
    status.textContent = text || '';
    status.className = kind === 'error' ? 'is-error' : (kind === 'ok' ? 'is-ok' : '');
  }

  function bootstrapPath(origin) {
    return String(origin || '').replace(/\/+$/, '') + (d.bootstrap_path || '/admin/lab/cj/plugin-bootstrap');
  }

  function fillStores(stores, selectedId) {
    storeEl.innerHTML = '';
    (stores || []).forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = String(s.id);
      opt.textContent = s.name + (s.market ? (' · ' + s.market) : '');
      storeEl.appendChild(opt);
    });
    if (selectedId) {
      storeEl.value = String(selectedId);
    }
    if (!storeEl.value && storeEl.options.length) {
      storeEl.selectedIndex = 0;
    }
    var n = storeEl.options.length;
    storeMeta.textContent = n ? (n + ' tienda(s) disponible(s)') : 'No hay tiendas en Multidrop';
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

  async function requestHosts(origin) {
    if (!origin || !chrome.permissions || !chrome.permissions.request) return;
    try {
      await chrome.permissions.request({ origins: [origin + '/*'] });
    } catch (e) {}
  }

  async function validateAndLoad(origin, token) {
    var res = await fetch(bootstrapPath(origin), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Multidrop-Token': token
      },
      body: JSON.stringify({ token: token })
    });
    var json = await res.json().catch(function () { return {}; });
    if (!res.ok || !json.success) {
      throw new Error(json.error || ('HTTP ' + res.status));
    }
    return json.stores || [];
  }

  chrome.storage.sync.get(['origin', 'token', 'store_id', 'token_ok'], function (cfg) {
    originEl.value = cfg.origin || d.origin || '';
    tokenEl.value = cfg.token || '';
    if (cfg.token_ok && cfg.origin && cfg.token) {
      setStatus('Validando…');
      requestHosts(String(cfg.origin).replace(/\/+$/, '')).then(function () {
        return validateAndLoad(String(cfg.origin).replace(/\/+$/, ''), cfg.token);
      }).then(function (stores) {
        showReady(String(cfg.origin).replace(/\/+$/, ''), stores, cfg.store_id);
        setStatus('Listo para capturar a borrador', 'ok');
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
        setStatus('Token válido, pero no hay tiendas. Crea una en Multidrop.', 'error');
        document.getElementById('save').disabled = false;
        return;
      }
      chrome.storage.sync.set({
        origin: origin,
        token: token,
        token_ok: true,
        store_id: stores[0].id
      }, function () {
        showReady(origin, stores, stores[0].id);
        setStatus('Token válido. Elige tienda y captura.', 'ok');
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
  });

  document.getElementById('change-token').addEventListener('click', function () {
    chrome.storage.sync.set({ token_ok: false }, function () {
      showSetup(true);
      setStatus('Pega el nuevo token y valida');
    });
  });

  document.getElementById('capture').addEventListener('click', function () {
    var btn = document.getElementById('capture');
    btn.disabled = true;
    setStatus('Capturando y enviando a borrador…');
    var storeId = parseInt(storeEl.value, 10) || 0;
    chrome.storage.sync.set({ store_id: storeId }, function () {
      chrome.runtime.sendMessage({ type: 'MULTIDROP_RUN_CAPTURE', store_id: storeId }, function (res) {
        btn.disabled = false;
        if (res && res.ok) {
          setStatus(res.message || ('Producto enviado a borrador' + (res.store_name ? ' en «' + res.store_name + '»' : '')), 'ok');
        } else {
          setStatus((res && res.error) || 'Error al capturar', 'error');
        }
      });
    });
  });
})();
