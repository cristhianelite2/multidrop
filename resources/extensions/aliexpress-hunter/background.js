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
    image_import_path: d.image_import_path || '/admin/lab/cj/plugin-import-image',
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

/**
 * Lee la pestaña activa y devuelve payload compacto (sin runParams gigante).
 */
async function readPagePayload(tabId, sections) {
  sections = sections || [];
  var injected = await chrome.scripting.executeScript({
    target: { tabId: tabId },
    world: 'MAIN',
    args: [sections],
    func: async function (sections) {
      sections = Array.isArray(sections) ? sections : [];
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
      function compactRunModules(data) {
        if (!data || typeof data !== 'object') return null;
        var mods = {
          imageModule: data.imageModule || null,
          imagePathList: data.imagePathList || null,
          skuModule: data.skuModule || null,
          titleModule: data.titleModule || null
        };
        if (sections.indexOf('description') >= 0 || sections.length === 0) {
          mods.descriptionModule = data.descriptionModule || null;
          mods.productDescModule = data.productDescModule || null;
        }
        if (sections.indexOf('reviews') >= 0 || sections.length === 0) {
          mods.feedbackModule = data.feedbackModule || null;
        }
        if (sections.indexOf('details') >= 0 || sections.length === 0) {
          mods.specsModule = data.specsModule || null;
          mods.productPropModule = data.productPropModule || null;
        }
        return { data: mods };
      }

      var isCj = /cjdropshipping\.com/i.test(location.href);
      var videoOnly = sections.length === 1 && sections[0] === 'videos';
      var mediaOnly = sections.length > 0
        && sections.indexOf('reviews') < 0 && sections.indexOf('description') < 0 && sections.indexOf('details') < 0;
      var fullCapture = sections.length === 0;

      if (!isCj && fullCapture) {
        try {
          var toc = document.querySelector('a[href="#nav-description"], a.comet-v2-anchor-link[title*="escrip" i]');
          if (toc) { try { toc.click(); } catch (e1) {} }
          var nav = document.getElementById('nav-description')
            || document.querySelector('[data-pl="product-description"], [id*="description"]');
          if (nav && nav.scrollIntoView) nav.scrollIntoView({ behavior: 'instant', block: 'center' });
          await sleep(600);
        } catch (eScroll) {}
      } else if (!isCj && videoOnly) {
        try {
          var gallery = document.querySelector('video, [class*="image-view"], [class*="slider--wrap"]');
          if (gallery && gallery.scrollIntoView) gallery.scrollIntoView({ behavior: 'instant', block: 'center' });
          await sleep(300);
        } catch (eVid) {}
      }

      var pageVideos = extractPageVideos();
      var rp = (typeof window.runParams === 'object' && window.runParams) ? window.runParams : null;
      var rpData = rp && (rp.data || rp);
      var compactRp = (!isCj && rpData) ? compactRunModules(rpData) : null;
      var hasVideoData = pageVideos.length > 0 || (compactRp && compactRp.data && compactRp.data.imageModule);

      var descriptionHtml = '';
      if (!isCj && !videoOnly) {
        var descRoot = document.querySelector(
          '#nav-description .detail-desc-decorate-richtext, #nav-description [class*="detail-desc"], ' +
          '[data-pl="product-description"] .detail-desc-decorate-richtext, .detail-desc-decorate-richtext'
        );
        if (descRoot && textLen(descRoot) > 40) descriptionHtml = descRoot.innerHTML || '';
      }
      var descriptionUrl = '';
      try {
        var dm = rpData && (rpData.descriptionModule || rpData.productDescModule || {});
        descriptionUrl = String((dm && (dm.descriptionUrl || dm.descUrl || dm.productDescUrl || dm.descriptionPCUrl)) || (rpData && rpData.descriptionUrl) || '');
      } catch (eUrl) {}

      var html = '';
      if (fullCapture) {
        try { html = document.documentElement ? document.documentElement.outerHTML : ''; } catch (e) { html = ''; }
        if (html.length > 1200000) html = html.slice(0, 1200000);
      } else if (videoOnly && hasVideoData) {
        html = '';
      } else {
        try { html = document.documentElement ? document.documentElement.outerHTML : ''; } catch (e) { html = ''; }
        if (html.length > 400000) html = html.slice(0, 400000);
      }

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
          runParams: compactRp,
          h1: h1el ? String(h1el.innerText || '').trim() : '',
          ogTitle: mt ? (mt.getAttribute('content') || '') : '',
          ogImage: mi ? (mi.getAttribute('content') || '') : '',
          priceText: priceEl ? String(priceEl.innerText || priceEl.getAttribute('content') || '') : '',
          shippingText: shipEl ? String(shipEl.innerText || '').replace(/\s+/g, ' ').trim() : '',
          descriptionHtml: descriptionHtml,
          descriptionUrl: descriptionUrl,
          isCj: isCj,
          pageVideos: pageVideos
        }
      };
    }
  });
  return injected && injected[0] && injected[0].result;
}

async function postPlugin(origin, path, token, body) {
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
}

chrome.runtime.onMessage.addListener(function (msg, sender, sendResponse) {
  if (!msg || !msg.type) return;

  if (msg.type === 'MULTIDROP_READ_PAGE') {
    (async function () {
      try {
        var tabId = await activeTabId(sender);
        if (!tabId) { sendResponse({ ok: false, error: 'No hay pestaña activa' }); return; }
        var payload = await readPagePayload(tabId, msg.sections || []);
        if (!payload || !payload.url) {
          sendResponse({ ok: false, error: 'Abre una ficha de producto AE o CJ' });
          return;
        }
        sendResponse({
          ok: true,
          url: payload.url,
          html: payload.html || '',
          snapshot: payload.snapshot || {}
        });
      } catch (e) {
        sendResponse({ ok: false, error: String(e && e.message ? e.message : e) });
      }
    })();
    return true;
  }

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
});

function normalizeImageUrl(url) {
  url = String(url || '').trim();
  if (!url) return '';
  if (/^\/\//.test(url)) url = 'https:' + url;
  url = url.replace(/\.(jpg|jpeg|png|webp|avif)_\.(avif|webp)$/i, '.$1');
  url = url.replace(/\.(jpg|jpeg|png|webp)_[0-9]+x[0-9]+\.(jpg|jpeg|png|webp)(\?.*)?$/i, '.$1$3');
  url = url.replace(/_(?:[0-9]+x[0-9]+q?[0-9]*|summ)\.(jpg|jpeg|png|webp|avif)(\?.*)?$/i, '.$1$2');
  return url;
}

/**
 * En la pestaña AE/CJ, localiza la misma imagen del thumb en el carrusel / runParams
 * y devuelve la URL en tamaño grande.
 */
async function resolveFullImageUrl(tabId, thumbUrl) {
  if (!tabId) return normalizeImageUrl(thumbUrl);
  var injected = await chrome.scripting.executeScript({
    target: { tabId: tabId },
    world: 'MAIN',
    args: [thumbUrl],
    func: function (thumbUrl) {
      function absUrl(url) {
        url = String(url || '').trim();
        if (!url) return '';
        if (/^https?:\/\//i.test(url)) return url;
        if (url.indexOf('//') === 0) return location.protocol + url;
        if (url.charAt(0) === '/') return location.origin + url;
        return url;
      }
      function normalize(url) {
        url = absUrl(url);
        if (!url) return '';
        url = url.replace(/\.(jpg|jpeg|png|webp|avif)_\.(avif|webp)$/i, '.$1');
        url = url.replace(/\.(jpg|jpeg|png|webp)_[0-9]+x[0-9]+\.(jpg|jpeg|png|webp)(\?.*)?$/i, '.$1$3');
        url = url.replace(/_(?:[0-9]+x[0-9]+q?[0-9]*|summ)\.(jpg|jpeg|png|webp|avif)(\?.*)?$/i, '.$1$2');
        if (/^https?:\/\/[^/]+\/kf\/S[a-zA-Z0-9]+/i.test(url)) {
          var km = url.match(/^(https?:\/\/[^/]+\/kf\/S[a-zA-Z0-9]+)/i);
          if (km) return km[1] + '.jpg';
        }
        return url;
      }
      function key(url) {
        url = normalize(url);
        var m = url.match(/\/(kf\/[A-Za-z0-9._-]+)/i);
        if (m) return m[1].replace(/_\d+x\d+.*$/, '');
        return url.split('?')[0];
      }
      function score(url) {
        var s = 0;
        url = String(url || '');
        if (!/_\d+x\d+/i.test(url)) s += 1000;
        var m = url.match(/_(\d+)x(\d+)/i);
        if (m) s += Math.max(parseInt(m[1], 10) || 0, parseInt(m[2], 10) || 0);
        if (/\.(jpe?g|png|webp)(\?|$)/i.test(url) && !/\.(jpe?g|png|webp)_/i.test(url)) s += 100;
        return s;
      }
      function pick(urls) {
        var best = '';
        var bestScore = -1;
        (urls || []).forEach(function (u) {
          u = normalize(u);
          if (!u) return;
          var sc = score(u);
          if (sc > bestScore) {
            bestScore = sc;
            best = u;
          }
        });
        return best;
      }
      function pushUnique(list, url) {
        url = normalize(url);
        if (!url || !/^https?:\/\//i.test(url)) return;
        if (/shipping--|sku-item|review--|avatar|favicon|logo|icon/i.test(url)) return;
        if (url.indexOf('/kf/') < 0 && url.indexOf('aliexpress-media') < 0) return;
        for (var i = 0; i < list.length; i++) {
          if (key(list[i]) === key(url)) return;
        }
        list.push(url);
      }

      var thumbKey = key(thumbUrl);
      if (!thumbKey) return normalize(thumbUrl);

      var candidates = [];

      try {
        var rp = (typeof window.runParams === 'object' && window.runParams) ? window.runParams : null;
        var data = rp && (rp.data || rp);
        var im = data && data.imageModule;
        ['imagePathList', 'summImagePathList', 'imageList'].forEach(function (listKey) {
          var lists = [im && im[listKey], data && data[listKey]];
          lists.forEach(function (list) {
            if (!Array.isArray(list)) return;
            list.forEach(function (item) {
              var u = typeof item === 'string' ? item : (item && (item.url || item.imageUrl || item.imgUrl || item.src)) || '';
              pushUnique(candidates, u);
            });
          });
        });
      } catch (eRp) {}

      var domSelectors = [
        '[class*="image-view"] img',
        '[class*="slider--item"] img',
        '[class*="slider--thumb"] img',
        '[class*="magnifier"] img',
        '[class*="gallery"] img',
        '[class*="product-image"] img',
        'img[src*="/kf/"]',
        'img[data-src*="/kf/"]'
      ];
      domSelectors.forEach(function (sel) {
        try {
          document.querySelectorAll(sel).forEach(function (img) {
            pushUnique(candidates, img.currentSrc || img.src || img.getAttribute('data-src') || '');
          });
        } catch (eDom) {}
      });

      var same = candidates.filter(function (u) { return key(u) === thumbKey; });
      var resolved = pick(same);
      if (resolved) return resolved;

      var thumbEl = null;
      try {
        document.querySelectorAll('img[src], img[data-src]').forEach(function (img) {
          if (thumbEl) return;
          var src = img.currentSrc || img.src || img.getAttribute('data-src') || '';
          if (key(src) === thumbKey) thumbEl = img;
        });
      } catch (eFind) {}

      if (thumbEl) {
        var thumbWrap = thumbEl.closest('[class*="slider--item"], [class*="thumb"], li, button, a');
        var idx = -1;
        if (thumbWrap && thumbWrap.parentElement) {
          var siblings = thumbWrap.parentElement.querySelectorAll('[class*="slider--item"], [class*="thumb"], li, button, a');
          for (var si = 0; si < siblings.length; si++) {
            if (siblings[si] === thumbWrap) { idx = si; break; }
          }
        }
        var mainSelectors = [
          '[class*="image-view--wrap"] img',
          '[class*="image-view-magnifier"] img',
          '[class*="main-image"] img',
          '[class*="slider--main"] img',
          '[class*="image-view"] img'
        ];
        for (var mi = 0; mi < mainSelectors.length; mi++) {
          var mains = document.querySelectorAll(mainSelectors[mi]);
          if (!mains.length) continue;
          if (idx >= 0 && idx < mains.length) {
            var mainSrc = mains[idx].currentSrc || mains[idx].src || mains[idx].getAttribute('data-src') || '';
            if (key(mainSrc) === thumbKey) {
              resolved = normalize(mainSrc);
              if (resolved) return resolved;
            }
          }
          for (var mj = 0; mj < mains.length; mj++) {
            var ms = mains[mj].currentSrc || mains[mj].src || mains[mj].getAttribute('data-src') || '';
            if (key(ms) === thumbKey) pushUnique(same, ms);
          }
        }
        resolved = pick(same);
        if (resolved) return resolved;
      }

      return normalize(thumbUrl);
    }
  });
  var resolved = injected && injected[0] && injected[0].result;
  return resolved || normalizeImageUrl(thumbUrl);
}

function notifyUser(title, message) {
  if (!chrome.notifications || !chrome.notifications.create) return;
  chrome.notifications.create({
    type: 'basic',
    iconUrl: 'icons/icon48.png',
    title: title || 'Multidrop Hunter',
    message: message || ''
  });
}

function setupContextMenus() {
  if (!chrome.contextMenus || !chrome.contextMenus.create) return;
  chrome.contextMenus.removeAll(function () {
    chrome.contextMenus.create({
      id: 'multidrop-extract-image',
      title: 'Extraer imagen a Multidrop',
      contexts: ['image'],
      documentUrlPatterns: [
        '*://*.aliexpress.com/*',
        '*://*.aliexpress.us/*',
        '*://*.aliexpress.ru/*',
        '*://*.cjdropshipping.com/*'
      ]
    });
  });
}

chrome.runtime.onInstalled.addListener(setupContextMenus);
chrome.runtime.onStartup.addListener(setupContextMenus);
setupContextMenus();

chrome.contextMenus.onClicked.addListener(function (info, tab) {
  if (!info || info.menuItemId !== 'multidrop-extract-image') return;
  (async function () {
    var thumbUrl = normalizeImageUrl(info.srcUrl || info.linkUrl || '');
    if (!thumbUrl || !/^https?:\/\//i.test(thumbUrl)) {
      notifyUser('Multidrop Hunter', 'No pude leer la URL de la imagen.');
      return;
    }
    var tabId = tab && tab.id;
    var imageUrl = thumbUrl;
    try {
      imageUrl = await resolveFullImageUrl(tabId, thumbUrl);
    } catch (eResolve) {
      imageUrl = thumbUrl;
    }
    if (!imageUrl || !/^https?:\/\//i.test(imageUrl)) {
      notifyUser('Multidrop Hunter', 'No pude resolver la imagen grande del carrusel.');
      return;
    }
    var cfg = await chrome.storage.sync.get([
      'origin', 'token', 'token_ok', 'store_id',
      'selected_product_id', 'selected_product_sku', 'selected_product_name'
    ]);
    var d = defaults();
    var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
    var token = String(cfg.token || '');
    var storeId = parseInt(cfg.store_id, 10) || 0;
    var productId = parseInt(cfg.selected_product_id, 10) || 0;
    if (!cfg.token_ok || !origin || !token) {
      notifyUser('Multidrop Hunter', 'Abre el plugin, configura el token y busca el producto destino por SKU.');
      return;
    }
    if (!storeId || !productId) {
      notifyUser('Multidrop Hunter', 'Busca primero el SKU del producto destino en el plugin.');
      return;
    }
    try {
      var out = await postPlugin(origin, d.image_import_path, token, {
        token: token,
        store_id: storeId,
        product_id: productId,
        image_url: imageUrl
      });
      if (!out.res.ok || !out.json.success) {
        notifyUser('Multidrop Hunter', out.json.error || out.json.message || ('HTTP ' + out.res.status));
        return;
      }
      var label = cfg.selected_product_sku
        ? ('#' + productId + ' · SKU ' + cfg.selected_product_sku)
        : ('#' + productId);
      notifyUser('Multidrop Hunter', (out.json.message || 'Imagen añadida') + ' → ' + label);
    } catch (e) {
      notifyUser('Multidrop Hunter', String(e && e.message ? e.message : e));
    }
  })();
});
