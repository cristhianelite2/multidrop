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
    hunter_path: d.hunter_path || '/admin/lab/cj'
  };
}

chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
  if (!msg || msg.type !== 'MULTIDROP_RUN_CAPTURE') return;
  (async function () {
    try {
      var tabId = sender.tab && sender.tab.id;
      if (!tabId) {
        var tabs = await chrome.tabs.query({ active: true, currentWindow: true });
        tabId = tabs[0] && tabs[0].id;
      }
      if (!tabId) {
        sendResponse({ ok: false, error: 'No hay pestaña activa' });
        return;
      }
      var injected = await chrome.scripting.executeScript({
        target: { tabId: tabId },
        world: 'MAIN',
        func: async function () {
          function sleep(ms) {
            return new Promise(function (r) { setTimeout(r, ms); });
          }
          function textLen(el) {
            return el ? String(el.innerText || '').replace(/\s+/g, ' ').trim().length : 0;
          }
          // Forzar carga de la descripción (AE la trae lazy al hacer scroll / click en el ancla)
          try {
            var toc = document.querySelector('a[href="#nav-description"], a.comet-v2-anchor-link[title*="escrip" i]');
            if (toc) {
              try { toc.click(); } catch (e1) {}
            }
            var nav = document.getElementById('nav-description')
              || document.querySelector('[data-pl="product-description"], [id*="description"]');
            if (nav && nav.scrollIntoView) {
              nav.scrollIntoView({ behavior: 'instant', block: 'center' });
            } else {
              window.scrollTo(0, Math.max(document.body.scrollHeight * 0.55, window.innerHeight * 2));
            }
            await sleep(900);
            window.scrollBy(0, 400);
            await sleep(700);
          } catch (eScroll) {}

          var descRoot = document.querySelector(
            '#nav-description .detail-desc-decorate-richtext, ' +
            '#nav-description [class*="detail-desc"], ' +
            '#nav-description [class*="description--wrap"], ' +
            '#nav-description, ' +
            '[data-pl="product-description"] .detail-desc-decorate-richtext, ' +
            '.detail-desc-decorate-richtext'
          );
          var descriptionHtml = '';
          if (descRoot && textLen(descRoot) > 40) {
            descriptionHtml = descRoot.innerHTML || '';
          }

          var descriptionUrl = '';
          try {
            var rp = (typeof window.runParams === 'object' && window.runParams) ? window.runParams : null;
            var data = rp && (rp.data || rp);
            var dm = data && (data.descriptionModule || data.productDescModule || {});
            descriptionUrl = String(
              (dm && (dm.descriptionUrl || dm.descUrl || dm.productDescUrl || dm.descriptionPCUrl)) ||
              (data && data.descriptionUrl) ||
              ''
            );
          } catch (eUrl) {}
          if (!descriptionUrl) {
            try {
              var htmlProbe = document.documentElement ? document.documentElement.innerHTML : '';
              var mUrl = htmlProbe.match(/"(?:descriptionUrl|descUrl|productDescUrl)"\s*:\s*"(https?:[^"]+)"/i);
              if (mUrl) descriptionUrl = mUrl[1].replace(/\\\//g, '/');
            } catch (e2) {}
          }

          var html = '';
          try {
            html = document.documentElement ? document.documentElement.outerHTML : '';
          } catch (e) {
            html = document.body ? document.body.innerHTML : '';
          }
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
              descriptionUrl: descriptionUrl
            }
          };
        }
      });
      var payload = injected && injected[0] && injected[0].result;
      if (!payload || !payload.html) {
        sendResponse({ ok: false, error: 'No pude leer la página' });
        return;
      }
      var cfg = await chrome.storage.sync.get(['origin', 'token']);
      var d = defaults();
      var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
      var token = String(cfg.token || '');
      if (!origin || !token) {
        sendResponse({ ok: false, error: 'Configura URL y token en el popup' });
        return;
      }
      var res = await fetch(origin + d.capture_path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Multidrop-Token': token
        },
        body: JSON.stringify({
          token: token,
          url: payload.url,
          html: payload.html,
          snapshot: payload.snapshot || {}
        })
      });
      var json = await res.json().catch(function () { return {}; });
      if (!res.ok || !json.success) {
        sendResponse({ ok: false, error: json.error || ('HTTP ' + res.status) });
        return;
      }
      if (json.open_url) {
        await chrome.tabs.create({ url: json.open_url });
      }
      sendResponse({ ok: true, title: json.title || '' });
    } catch (e) {
      sendResponse({ ok: false, error: String(e && e.message ? e.message : e) });
    }
  })();
  return true;
});
