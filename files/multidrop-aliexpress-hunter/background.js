try {
  importScripts('config.js');
} catch (e) {
  self.MULTIDROP_DEFAULTS = self.MULTIDROP_DEFAULTS || {};
}

function defaults() {
  var d = self.MULTIDROP_DEFAULTS || {};
  return {
    origin: d.origin || '',
    capture_path: d.capture_path || '/admin/lab/cj/plugin-capture',
    extract_path: d.extract_path || '/admin/lab/cj/plugin-extract',
    product_search_path: d.product_search_path || '/admin/lab/cj/plugin-product-search',
    bootstrap_path: d.bootstrap_path || '/admin/lab/cj/plugin-bootstrap',
    hunter_path: d.hunter_path || '/admin/lab/cj'
  };
}

async function activeTabId(sender) {
  var tabId = sender.tab && sender.tab.id;
  if (!tabId) {
    var tabs = await chrome.tabs.query({ active: true, currentWindow: true });
    tabId = tabs[0] && tabs[0].id;
  }
  return tabId;
}

async function readPagePayload(tabId) {
  var injected = await chrome.scripting.executeScript({
    target: { tabId: tabId },
    world: 'MAIN',
    func: async function () {
      function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
      function textLen(el) { return el ? String(el.innerText || '').replace(/\s+/g, ' ').trim().length : 0; }
      var isCj = /cjdropshipping\.com/i.test(location.href);
      if (!isCj) {
        try {
          var toc = document.querySelector('a[href="#nav-description"], a.comet-v2-anchor-link[title*="escrip" i]');
          if (toc) { try { toc.click(); } catch (e1) {} }
          var nav = document.getElementById('nav-description')
            || document.querySelector('[data-pl="product-description"], [id*="description"]');
          if (nav && nav.scrollIntoView) nav.scrollIntoView({ behavior: 'instant', block: 'center' });
          else window.scrollTo(0, Math.max(document.body.scrollHeight * 0.55, window.innerHeight * 2));
          await sleep(900);
          window.scrollBy(0, 400);
          await sleep(700);
        } catch (eScroll) {}
      }
      var descriptionHtml = '';
      if (!isCj) {
        var descRoot = document.querySelector(
          '#nav-description .detail-desc-decorate-richtext, #nav-description [class*="detail-desc"], ' +
          '#nav-description [class*="description--wrap"], #nav-description, ' +
          '[data-pl="product-description"] .detail-desc-decorate-richtext, .detail-desc-decorate-richtext'
        );
        if (descRoot && textLen(descRoot) > 40) descriptionHtml = descRoot.innerHTML || '';
      }
      var descriptionUrl = '';
      try {
        var rp = (typeof window.runParams === 'object' && window.runParams) ? window.runParams : null;
        var data = rp && (rp.data || rp);
        var dm = data && (data.descriptionModule || data.productDescModule || {});
        descriptionUrl = String((dm && (dm.descriptionUrl || dm.descUrl || dm.productDescUrl || dm.descriptionPCUrl)) || (data && data.descriptionUrl) || '');
      } catch (eUrl) {}
      var html = '';
      try { html = document.documentElement ? document.documentElement.outerHTML : ''; }
      catch (e) { html = document.body ? document.body.innerHTML : ''; }
      if (html.length > 1800000) html = html.slice(0, 1800000);
      var h1el = document.querySelector('h1');
      var mt = document.querySelector('meta[property="og:title"]');
      var mi = document.querySelector('meta[property="og:image"]');
      var priceEl = document.querySelector('[class*="price-default--current"], [class*="price--current"], [itemprop="price"]');
      var shipEl = document.querySelector('[class*="dynamic-shipping"]');
      var productId = '';
      var m = String(location.href).match(/(?:item|i)\/(\d{10,20})/i);
      if (m) productId = m[1];
      return {
        url: location.href,
        html: html,
        snapshot: {
          productId: productId,
          runParams: (typeof window.runParams === 'object' && window.runParams) ? window.runParams : null,
          dcData: (window._d_c_ && window._d_c_.DCData) ? window._d_c_.DCData : null,
          initData: (typeof window.__INIT_DATA__ === 'object') ? window.__INIT_DATA__ : null,
          h1: h1el ? String(h1el.innerText || '').trim() : '',
          ogTitle: mt ? (mt.getAttribute('content') || '') : '',
          ogImage: mi ? (mi.getAttribute('content') || '') : '',
          priceText: priceEl ? String(priceEl.innerText || priceEl.getAttribute('content') || '') : '',
          shippingText: shipEl ? String(shipEl.innerText || '').replace(/\s+/g, ' ').trim() : '',
          title: document.title || '',
          descriptionHtml: descriptionHtml,
          descriptionUrl: descriptionUrl,
          isCj: isCj
        }
      };
    }
  });
  return injected && injected[0] && injected[0].result;
}

chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
  if (!msg || !msg.type) return;
  if (msg.type === 'MULTIDROP_RUN_CAPTURE') {
    (async function () {
      try {
        var tabId = await activeTabId(sender);
        if (!tabId) { sendResponse({ ok: false, error: 'No hay pestaña activa' }); return; }
        var payload = await readPagePayload(tabId);
        if (!payload || !payload.url) { sendResponse({ ok: false, error: 'No pude leer la página' }); return; }
        var cfg = await chrome.storage.sync.get(['origin', 'token', 'store_id']);
        var d = defaults();
        var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
        var token = String(cfg.token || '');
        var storeId = parseInt(msg.store_id != null ? msg.store_id : cfg.store_id, 10) || 0;
        if (!origin || !token) { sendResponse({ ok: false, error: 'Configura URL y token' }); return; }
        if (!storeId) { sendResponse({ ok: false, error: 'Elige una tienda' }); return; }
        var res = await fetch(origin + d.capture_path, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Multidrop-Token': token },
          body: JSON.stringify({ token: token, store_id: storeId, url: payload.url, html: payload.html, snapshot: payload.snapshot || {} })
        });
        var json = await res.json().catch(function () { return {}; });
        if (!res.ok || !json.success) { sendResponse({ ok: false, error: json.error || ('HTTP ' + res.status) }); return; }
        sendResponse({ ok: true, message: json.message || 'Producto enviado a borrador', product_id: json.product_id, edit_url: json.edit_url });
      } catch (e) {
        sendResponse({ ok: false, error: String(e && e.message ? e.message : e) });
      }
    })();
    return true;
  }
  if (msg.type === 'MULTIDROP_RUN_EXTRACT') {
    (async function () {
      try {
        var tabId = await activeTabId(sender);
        if (!tabId) { sendResponse({ ok: false, error: 'No hay pestaña activa' }); return; }
        var payload = await readPagePayload(tabId);
        if (!payload || !payload.url) { sendResponse({ ok: false, error: 'Abre una ficha de producto AE o CJ' }); return; }
        var cfg = await chrome.storage.sync.get(['origin', 'token', 'store_id']);
        var d = defaults();
        var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
        var token = String(cfg.token || '');
        var storeId = parseInt(msg.store_id != null ? msg.store_id : cfg.store_id, 10) || 0;
        var productId = parseInt(msg.product_id, 10) || 0;
        if (!origin || !token) { sendResponse({ ok: false, error: 'Configura URL y token' }); return; }
        if (!storeId || !productId) { sendResponse({ ok: false, error: 'Busca el SKU del producto destino' }); return; }
        var res = await fetch(origin + d.extract_path, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Multidrop-Token': token },
          body: JSON.stringify({
            token: token,
            store_id: storeId,
            product_id: productId,
            sections: msg.sections || [],
            replace: !!msg.replace,
            url: payload.url,
            html: payload.html,
            snapshot: payload.snapshot || {}
          })
        });
        var json = await res.json().catch(function () { return {}; });
        if (!res.ok || !json.success) { sendResponse({ ok: false, error: json.error || json.message || ('HTTP ' + res.status) }); return; }
        sendResponse({ ok: true, message: json.message || 'Importado al producto', edit_url: json.edit_url });
      } catch (e) {
        sendResponse({ ok: false, error: String(e && e.message ? e.message : e) });
      }
    })();
    return true;
  }
});
