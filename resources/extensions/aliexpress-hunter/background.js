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

function keepServiceWorkerAlive() {
  return setInterval(function () {
    chrome.runtime.getPlatformInfo(function () {});
  }, 12000);
}

function compactSnapshot(snapshot, sections) {
  snapshot = snapshot || {};
  sections = sections || [];
  var out = {
    productId: snapshot.productId || '',
    h1: snapshot.h1 || '',
    ogTitle: snapshot.ogTitle || '',
    ogImage: snapshot.ogImage || '',
    priceText: snapshot.priceText || '',
    shippingText: snapshot.shippingText || '',
    descriptionHtml: snapshot.descriptionHtml || '',
    descriptionUrl: snapshot.descriptionUrl || '',
    isCj: !!snapshot.isCj,
    pageVideos: Array.isArray(snapshot.pageVideos) ? snapshot.pageVideos : []
  };
  var needRun = sections.indexOf('videos') >= 0 || sections.indexOf('images') >= 0 || sections.length === 0;
  if (needRun && snapshot.runParams) {
    var data = snapshot.runParams.data || snapshot.runParams;
    if (data && typeof data === 'object') {
      out.runParams = {
        data: {
          imageModule: data.imageModule || null,
          imagePathList: data.imagePathList || null,
          skuModule: data.skuModule || null,
          titleModule: data.titleModule || null,
          descriptionModule: data.descriptionModule || null,
          productDescModule: data.productDescModule || null,
          feedbackModule: data.feedbackModule || null,
          specsModule: data.specsModule || null,
          productPropModule: data.productPropModule || null
        }
      };
    }
  }
  if (sections.indexOf('reviews') >= 0 && snapshot.dcData) {
    out.dcData = snapshot.dcData;
  }
  return out;
}

function trimHtmlForExtract(html, snapshot, sections) {
  html = String(html || '');
  var snap = compactSnapshot(snapshot, sections);
  var hasVideoData = (snap.pageVideos && snap.pageVideos.length)
    || (snap.runParams && snap.runParams.data && snap.runParams.data.imageModule);
  if (hasVideoData && html.length > 450000) {
    return html.slice(0, 450000);
  }
  if (html.length > 1200000) {
    return html.slice(0, 1200000);
  }
  return html;
}

async function activeTabId(sender) {
  var tabId = sender.tab && sender.tab.id;
  if (!tabId) {
    var tabs = await chrome.tabs.query({ active: true, currentWindow: true });
    tabId = tabs[0] && tabs[0].id;
  }
  return tabId;
}

async function readPagePayload(tabId, sections) {
  sections = sections || [];
  var injected = await chrome.scripting.executeScript({
    target: { tabId: tabId },
    world: 'MAIN',
    args: [sections],
    func: async function (sections) {
      function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
      function textLen(el) { return el ? String(el.innerText || '').replace(/\s+/g, ' ').trim().length : 0; }
      function absUrl(url) {
        url = String(url || '').trim();
        if (!url) return '';
        if (/^https?:\/\//i.test(url)) return url;
        if (url.indexOf('//') === 0) return location.protocol + url;
        if (url.charAt(0) === '/') return location.origin + url;
        return url;
      }
      function looksLikeVideo(url) {
        url = String(url || '');
        if (!url) return false;
        if (/\.(mp4|m3u8|webm|mov)(\?|$)/i.test(url)) return true;
        return /video|videocdn|aliexpress-media|alicdn\.com/i.test(url);
      }
      function pushVideo(list, url, cover) {
        url = absUrl(url);
        if (!looksLikeVideo(url)) return;
        for (var i = 0; i < list.length; i++) {
          if (list[i].url === url) return;
        }
        list.push({ url: url, cover: absUrl(cover || '') });
      }
      function extractPageVideos() {
        var out = [];
        try {
          var rp = (typeof window.runParams === 'object' && window.runParams) ? window.runParams : null;
          var data = rp && (rp.data || rp);
          var im = data && data.imageModule;
          if (im) {
            ['videoUrl', 'aliVideoUrl', 'videoPath', 'playUrl', 'video_url', 'videoSrc'].forEach(function (k) {
              pushVideo(out, im[k], im.videoCover || im.videoPoster || '');
            });
            ['videoList', 'videos', 'mediaElements'].forEach(function (k) {
              var list = im[k];
              if (!Array.isArray(list)) return;
              list.forEach(function (item) {
                if (typeof item === 'string') pushVideo(out, item, '');
                else if (item && typeof item === 'object') {
                  pushVideo(out, item.url || item.videoUrl || item.playUrl || item.src || '', item.cover || item.poster || item.videoCover || '');
                }
              });
            });
          }
        } catch (e) {}
        try {
          document.querySelectorAll('video[src], video source[src]').forEach(function (el) {
            pushVideo(out, el.getAttribute('src') || '', '');
          });
        } catch (e2) {}
        return out;
      }

      var isCj = /cjdropshipping\.com/i.test(location.href);
      var videoOnly = Array.isArray(sections) && sections.length === 1 && sections[0] === 'videos';
      var mediaOnly = Array.isArray(sections) && sections.length > 0
        && sections.indexOf('reviews') < 0 && sections.indexOf('description') < 0 && sections.indexOf('details') < 0;

      if (!isCj && !videoOnly && !mediaOnly) {
        try {
          var toc = document.querySelector('a[href="#nav-description"], a.comet-v2-anchor-link[title*="escrip" i]');
          if (toc) { try { toc.click(); } catch (e1) {} }
          var nav = document.getElementById('nav-description')
            || document.querySelector('[data-pl="product-description"], [id*="description"]');
          if (nav && nav.scrollIntoView) nav.scrollIntoView({ behavior: 'instant', block: 'center' });
          else window.scrollTo(0, Math.max(document.body.scrollHeight * 0.55, window.innerHeight * 2));
          await sleep(700);
          window.scrollBy(0, 300);
          await sleep(400);
        } catch (eScroll) {}
      } else if (!isCj && videoOnly) {
        try {
          var gallery = document.querySelector('[class*="image-view"], [class*="slider--wrap"], .images-view-item, video');
          if (gallery && gallery.scrollIntoView) gallery.scrollIntoView({ behavior: 'instant', block: 'center' });
          await sleep(500);
        } catch (eVid) {}
      }

      var descriptionHtml = '';
      if (!isCj && !videoOnly) {
        var descRoot = document.querySelector(
          '#nav-description .detail-desc-decorate-richtext, #nav-description [class*="detail-desc"], ' +
          '#nav-description [class*="description--wrap"], #nav-description, ' +
          '[data-pl="product-description"] .detail-desc-decorate-richtext, .detail-desc-decorate-richtext'
        );
        if (descRoot && textLen(descRoot) > 40) descriptionHtml = descRoot.innerHTML || '';
      }
      var descriptionUrl = '';
      try {
        var rp2 = (typeof window.runParams === 'object' && window.runParams) ? window.runParams : null;
        var data2 = rp2 && (rp2.data || rp2);
        var dm = data2 && (data2.descriptionModule || data2.productDescModule || {});
        descriptionUrl = String((dm && (dm.descriptionUrl || dm.descUrl || dm.productDescUrl || dm.descriptionPCUrl)) || (data2 && data2.descriptionUrl) || '');
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
          h1: h1el ? String(h1el.innerText || '').trim() : '',
          ogTitle: mt ? (mt.getAttribute('content') || '') : '',
          ogImage: mi ? (mi.getAttribute('content') || '') : '',
          priceText: priceEl ? String(priceEl.innerText || priceEl.getAttribute('content') || '') : '',
          shippingText: shipEl ? String(shipEl.innerText || '').replace(/\s+/g, ' ').trim() : '',
          title: document.title || '',
          descriptionHtml: descriptionHtml,
          descriptionUrl: descriptionUrl,
          isCj: isCj,
          pageVideos: extractPageVideos()
        }
      };
    }
  });
  return injected && injected[0] && injected[0].result;
}

async function postPlugin(origin, path, token, body) {
  var keepAlive = keepServiceWorkerAlive();
  try {
    var res = await fetch(origin + path, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Multidrop-Token': token
      },
      body: JSON.stringify(body)
    });
    var json = await res.json().catch(function () { return {}; });
    return { res: res, json: json };
  } finally {
    clearInterval(keepAlive);
  }
}

chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
  if (!msg || !msg.type) return;
  if (msg.type === 'MULTIDROP_RUN_CAPTURE') {
    (async function () {
      try {
        var tabId = await activeTabId(sender);
        if (!tabId) { sendResponse({ ok: false, error: 'No hay pestaña activa' }); return; }
        var payload = await readPagePayload(tabId, []);
        if (!payload || !payload.url) { sendResponse({ ok: false, error: 'No pude leer la página' }); return; }
        var cfg = await chrome.storage.sync.get(['origin', 'token', 'store_id']);
        var d = defaults();
        var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
        var token = String(cfg.token || '');
        var storeId = parseInt(msg.store_id != null ? msg.store_id : cfg.store_id, 10) || 0;
        if (!origin || !token) { sendResponse({ ok: false, error: 'Configura URL y token' }); return; }
        if (!storeId) { sendResponse({ ok: false, error: 'Elige una tienda' }); return; }
        var out = await postPlugin(origin, d.capture_path, token, {
          token: token,
          store_id: storeId,
          url: payload.url,
          html: payload.html,
          snapshot: payload.snapshot || {}
        });
        if (!out.res.ok || !out.json.success) {
          sendResponse({ ok: false, error: out.json.error || ('HTTP ' + out.res.status) });
          return;
        }
        sendResponse({ ok: true, message: out.json.message || 'Producto enviado a borrador', product_id: out.json.product_id, edit_url: out.json.edit_url });
      } catch (e) {
        sendResponse({ ok: false, error: String(e && e.message ? e.message : e) });
      }
    })();
    return true;
  }
  if (msg.type === 'MULTIDROP_RUN_EXTRACT') {
    (async function () {
      try {
        var sections = msg.sections || [];
        var tabId = await activeTabId(sender);
        if (!tabId) { sendResponse({ ok: false, error: 'No hay pestaña activa' }); return; }
        var payload = await readPagePayload(tabId, sections);
        if (!payload || !payload.url) { sendResponse({ ok: false, error: 'Abre una ficha de producto AE o CJ' }); return; }
        var cfg = await chrome.storage.sync.get(['origin', 'token', 'store_id']);
        var d = defaults();
        var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
        var token = String(cfg.token || '');
        var storeId = parseInt(msg.store_id != null ? msg.store_id : cfg.store_id, 10) || 0;
        var productId = parseInt(msg.product_id, 10) || 0;
        if (!origin || !token) { sendResponse({ ok: false, error: 'Configura URL y token' }); return; }
        if (!storeId || !productId) { sendResponse({ ok: false, error: 'Busca el SKU del producto destino' }); return; }
        var snap = compactSnapshot(payload.snapshot || {}, sections);
        var out = await postPlugin(origin, d.extract_path, token, {
          token: token,
          store_id: storeId,
          product_id: productId,
          sections: sections,
          replace: !!msg.replace,
          url: payload.url,
          html: trimHtmlForExtract(payload.html, payload.snapshot, sections),
          snapshot: snap
        });
        if (!out.res.ok || !out.json.success) {
          sendResponse({ ok: false, error: out.json.error || out.json.message || ('HTTP ' + out.res.status) });
          return;
        }
        sendResponse({ ok: true, message: out.json.message || 'Importado al producto', edit_url: out.json.edit_url });
      } catch (e) {
        sendResponse({ ok: false, error: String(e && e.message ? e.message : e) });
      }
    })();
    return true;
  }
});
