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
        return /video|videocdn|cdn.*video/i.test(url);
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
            ['videoUrl', 'videoPath', 'playUrl', 'video_url', 'videoSrc'].forEach(function (k) {
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
      function parsePriceNum(txt) {
        txt = String(txt == null ? '' : txt);
        var n = txt.replace(/[^\d.,]/g, '');
        if (!n) return null;
        var lastComma = n.lastIndexOf(',');
        var lastDot = n.lastIndexOf('.');
        if (lastComma >= 0 && lastDot >= 0) {
          if (lastComma > lastDot) { n = n.replace(/\./g, '').replace(',', '.'); }
          else { n = n.replace(/,/g, ''); }
        } else if (lastComma >= 0) {
          var frac = n.slice(lastComma + 1);
          n = (frac.length === 3 && lastComma > 0) ? n.replace(/,/g, '') : n.replace(',', '.');
        }
        var v = parseFloat(n);
        return isNaN(v) ? null : v;
      }
      function detectCurrencyTxt(txt) {
        txt = String(txt || '');
        if (/MXN|MX\s*\$|Mex\s*\$/i.test(txt)) return 'MXN';
        if (/USD|US\s*\$/i.test(txt)) return 'USD';
        if (/EUR|€/.test(txt)) return 'EUR';
        if (/GBP|£/.test(txt)) return 'GBP';
        if (/BRL|R\s*\$/i.test(txt)) return 'BRL';
        if (/CAD|CA\s*\$/i.test(txt)) return 'CAD';
        if (/AUD|AU\s*\$/i.test(txt)) return 'AUD';
        if (/CNY|RMB|¥/.test(txt)) return 'CNY';
        return '';
      }
      // querySelector que además perfora shadow roots (la PDP CSR los usa).
      function queryDeep(sel, sub) {
        try {
          var direct = document.querySelector(sel);
          if (direct) return direct;
        } catch (eD) {}
        if (!sub) return null;
        try {
          var stack = [document.body || document.documentElement];
          var count = 0;
          while (stack.length && count < 4000) {
            var node = stack.pop();
            count++;
            if (!node) continue;
            var kids = (node.children && node.children.length) ? node.children : (node.childNodes || []);
            if (!kids || !kids.length) continue;
            for (var i = 0; i < kids.length; i++) {
              var k = kids[i];
              if (!k || k.nodeType === 3) continue;
              try {
                if (String(k.className || '').indexOf(sub) >= 0) return k;
              } catch (eC) {}
              try {
                if (k.shadowRoot) stack.push(k.shadowRoot);
              } catch (eS) {}
              stack.push(k);
            }
          }
        } catch (eT) {}
        return null;
      }
      function readCurrentPrice() {
        var cur = queryDeep(
          '[class*="price-default--current"], [class*="price--current"], [itemprop="price"]',
          'price-default--current'
        );
        if (!cur) cur = queryDeep('[class*="price--current"]', 'price--current');
        var orig = queryDeep('[class*="price-default--original"]', 'price-default--original');
        var curText = cur ? String(cur.innerText || (cur.getAttribute && cur.getAttribute('content')) || '').replace(/\s+/g, ' ').trim() : '';
        var origText = orig ? String(orig.innerText || '').replace(/\s+/g, ' ').trim() : '';
        return {
          curText: curText,
          price: parsePriceNum(curText),
          currency: detectCurrencyTxt(curText),
          origText: origText,
          original: parsePriceNum(origText)
        };
      }
      function isSoldOutEl(el) {
        if (!el) return false;
        var cls = '';
        try { cls = String(el.className || ''); }
        catch (eC) { cls = String((el.getAttribute && el.getAttribute('class')) || ''); }
        if (/sold\s*-?out/i.test(cls)) return true;
        if (/out[\s_-]*of[\s_-]*stock/i.test(cls)) return true;
        if (/unavailable/i.test(cls)) return true;
        return false;
      }
      function skuOptName(el) {
        try {
          var img = el.querySelector ? el.querySelector('img') : null;
          var alt = img ? String(img.getAttribute('alt') || '').trim() : '';
          if (alt) return alt;
        } catch (eA) {}
        try {
          var aria = String((el.getAttribute && el.getAttribute('aria-label')) || '').replace(/\s+/g, ' ').trim();
          if (aria) return aria;
        } catch (eA2) {}
        return String(el.innerText || '').replace(/\s+/g, ' ').trim();
      }
      function skuOptImg(el) {
        try {
          var img = el.querySelector ? el.querySelector('img') : null;
          if (!img) return '';
          return absUrl(img.currentSrc || img.src || img.getAttribute('data-src') || '');
        } catch (eI) { return ''; }
      }
      // Estado soldOut del DOM: [data-sku-col="14-175"] con clase ...soldOut...
      function extractDomSkuState() {
        var byCol = {};
        var soldOutCols = [];
        try {
          document.querySelectorAll('[data-sku-col]').forEach(function (el) {
            var col = String(el.getAttribute('data-sku-col') || '').trim();
            if (!col || byCol[col]) return;
            var sold = isSoldOutEl(el);
            byCol[col] = { col: col, name: skuOptName(el), image: skuOptImg(el), soldOut: sold };
            if (sold && soldOutCols.indexOf(col) < 0) soldOutCols.push(col);
          });
        } catch (e1) {}
        var soldOutTexts = [];
        try {
          document.querySelectorAll('[class*="sku-item--text"]').forEach(function (el) {
            if (!isSoldOutEl(el)) return;
            var nm = String(el.innerText || '').replace(/\s+/g, ' ').trim();
            if (nm && soldOutTexts.indexOf(nm) < 0) soldOutTexts.push(nm);
          });
        } catch (e2) {}
        return { byCol: byCol, soldOutCols: soldOutCols, soldOutTexts: soldOutTexts };
      }
      function isComboSelected(el) {
        try {
          var cls = String(el.className || '');
          if (/selected/i.test(cls)) return true;
          if (el.getAttribute) {
            if (el.getAttribute('aria-checked') === 'true') return true;
            if (el.getAttribute('aria-selected') === 'true') return true;
          }
        } catch (eS) {}
        return false;
      }
      function optRef(el) {
        var col = '';
        try { col = String(el.getAttribute('data-sku-col') || ''); } catch (eR) {}
        return { el: el, col: col, name: skuOptName(el) };
      }
      // La PDP re-renderiza las opciones al seleccionar: re-resolver el nodo
      // vivo antes de cada clic/lectura para no operar sobre nodos sueltos.
      function resolveOpt(ref) {
        try {
          if (ref.col) {
            var live = document.querySelector('[data-sku-col="' + ref.col + '"]');
            if (live) return live;
          }
        } catch (eL) {}
        if (ref.el && ref.el.isConnected !== false) return ref.el;
        try {
          var found = null;
          document.querySelectorAll('[class*="sku-item--text"]').forEach(function (el) {
            if (!found && skuOptName(el) === ref.name) found = el;
          });
          if (found) return found;
        } catch (eL2) {}
        return ref.el;
      }
      // Recorre cada variación (o combinación) clicándola y lee su precio visible
      // en .price-default--current (más .price-default--original si existe).
      async function walkVariantPrices() {
        var out = [];
        var groupCount = 0;
        try {
          var groups = [];
          var propBlocks = document.querySelectorAll('[class*="sku-item--property"]');
          propBlocks.forEach(function (block) {
            var opts = [];
            block.querySelectorAll('[data-sku-col]').forEach(function (el) { opts.push(optRef(el)); });
            if (!opts.length) {
              block.querySelectorAll('[class*="sku-item--text"]').forEach(function (el) { opts.push(optRef(el)); });
            }
            var seen = {};
            opts = opts.filter(function (ref) {
              var k = ref.col || ref.name;
              if (!k || seen[k]) return false;
              seen[k] = true;
              return true;
            });
            if (opts.length) groups.push(opts);
          });
          if (!groups.length) {
            var global = [];
            var seenG = {};
            document.querySelectorAll('[data-sku-col]').forEach(function (el) {
              var k = String(el.getAttribute('data-sku-col') || '');
              if (!k || seenG[k]) return;
              seenG[k] = true;
              global.push(optRef(el));
            });
            if (global.length) groups.push(global);
          }
          if (!groups.length) return { prices: [], walked: false, groups: 0 };
          groupCount = groups.length;
          var MAX = 40;
          var total = groups.reduce(function (a, g) { return a * g.length; }, 1);
          var combos = [];
          if (total <= MAX) {
            combos = [[]];
            groups.forEach(function (opts) {
              var next = [];
              combos.forEach(function (prefix) {
                opts.forEach(function (ref) { next.push(prefix.concat([ref])); });
              });
              combos = next;
            });
          } else {
            // Demasiadas combinaciones: caminar opción por opción.
            groups.forEach(function (opts) {
              opts.forEach(function (ref) {
                if (combos.length < MAX) combos.push([ref]);
              });
            });
          }
          for (var ci = 0; ci < combos.length; ci++) {
            var combo = combos[ci];
            for (var k = 0; k < combo.length; k++) {
              try {
                var target = resolveOpt(combo[k]);
                if (target.scrollIntoView) target.scrollIntoView({ behavior: 'instant', block: 'center' });
                target.click();
                await sleep(300);
              } catch (eClick) {}
            }
            await sleep(450);
            var pr = readCurrentPrice();
            var cols = combo.map(function (ref) { return ref.col; }).filter(function (c) { return !!c; });
            var names = combo.map(function (ref) { return ref.name; });
            var liveEls = combo.map(function (ref) { return resolveOpt(ref); });
            var selected = liveEls.map(function (el) { return isComboSelected(el); });
            var avail = true;
            liveEls.forEach(function (el) { if (isSoldOutEl(el)) avail = false; });
            try {
              document.querySelectorAll('[class*="sku-item--selected"]').forEach(function (sel) {
                if (isSoldOutEl(sel)) avail = false;
              });
            } catch (eSel) {}
            var allSelected = selected.length === 0 || selected.every(function (s) { return s; });
            if (!allSelected) avail = false;
            out.push({
              cols: cols,
              names: names,
              // full=false: caminata parcial (una opción con 2+ dimensiones):
              // el precio NO es del combo, solo la no-disponibilidad es válida.
              full: combo.length === groupCount,
              price: allSelected ? pr.price : null,
              priceText: allSelected ? pr.curText : '',
              original: allSelected ? pr.original : null,
              originalText: allSelected ? pr.origText : '',
              currency: pr.currency,
              available: avail
            });
          }
        } catch (eWalk) {}
        return { prices: out, walked: out.length > 0, groups: groupCount };
      }
      // La PDP CSR no siempre llena window.runParams: fusionar las fuentes
      // disponibles (runParams, _dida_config_._init_data_, __INIT_DATA__).
      function mergedPageData() {
        var sources = [];
        try {
          if (typeof window.runParams === 'object' && window.runParams) sources.push(window.runParams);
        } catch (e1) {}
        try {
          var dida = window._dida_config_ && window._dida_config_._init_data_;
          if (dida && typeof dida === 'object') sources.push(dida);
        } catch (e2) {}
        try {
          if (typeof window.__INIT_DATA__ === 'object' && window.__INIT_DATA__) sources.push(window.__INIT_DATA__);
        } catch (e3) {}
        if (!sources.length) return null;
        var merged = {};
        sources.forEach(function (s) {
          var d = (s && typeof s === 'object' && (s.data || s)) || null;
          if (!d || typeof d !== 'object') return;
          Object.keys(d).forEach(function (k) {
            if (merged[k] === undefined || merged[k] === null) merged[k] = d[k];
          });
        });
        return Object.keys(merged).length ? merged : null;
      }
      // Mapa precio-por-SKU de la PDP nueva (PRICE.skuIdStrPriceInfoMap), adelgazado.
      function findSkuPriceMap(data) {
        var found = null;
        var scanned = 0;
        var seen = [];
        function visit(node, depth) {
          if (found || node == null || depth > 6 || scanned > 4000) return;
          if (typeof node !== 'object') return;
          if (seen.indexOf(node) >= 0) return;
          seen.push(node);
          scanned++;
          if (!Array.isArray(node) && node.skuIdStrPriceInfoMap && typeof node.skuIdStrPriceInfoMap === 'object') {
            found = node.skuIdStrPriceInfoMap;
            return;
          }
          if (Array.isArray(node)) {
            for (var i = 0; i < node.length && !found; i++) visit(node[i], depth + 1);
            return;
          }
          for (var k in node) {
            if (!Object.prototype.hasOwnProperty.call(node, k) || found) break;
            if (k === 'skuIdStrPriceInfoMap' && node[k] && typeof node[k] === 'object') { found = node[k]; break; }
            visit(node[k], depth + 1);
          }
        }
        try { visit(data, 0); } catch (eF) {}
        if (!found) return null;
        var slim = {};
        var n = 0;
        Object.keys(found).forEach(function (skuId) {
          if (n >= 200) return;
          var row = found[skuId];
          if (!row || typeof row !== 'object') return;
          var price = row.salePrice !== undefined ? row.salePrice
            : (row.currentPrice !== undefined ? row.currentPrice : row.price);
          var original = null;
          if (row.originalPrice !== undefined) {
            original = (row.originalPrice && typeof row.originalPrice === 'object')
              ? (row.originalPrice.value !== undefined ? row.originalPrice.value : null)
              : row.originalPrice;
          } else if (row.compare_at_price !== undefined) {
            original = row.compare_at_price;
          }
          if (price == null && row.salePriceString !== undefined) {
            price = String(row.salePriceString).split('|')[0];
          }
          if (price == null && original == null) return;
          slim[skuId] = { salePrice: price };
          if (original != null) slim[skuId].originalPrice = original;
          n++;
        });
        return n ? slim : null;
      }
      function compactRunModules(data) {
        if (!data || typeof data !== 'object') return null;
        var skuModule = data.skuModule || null;
        if (!skuModule && (Array.isArray(data.skuPropertyList) || Array.isArray(data.productSKUPropertyList)
          || Array.isArray(data.skuList) || Array.isArray(data.skuPriceList) || Array.isArray(data.linkSkuList))) {
          skuModule = {
            productSKUPropertyList: data.skuPropertyList || data.productSKUPropertyList || [],
            skuPriceList: data.skuPriceList || data.skuList || data.productSKUPriceList || [],
            linkSkuList: data.linkSkuList || [],
            linkSkuPropertyList: data.linkSkuPropertyList || []
          };
        }
        var mods = {
          imageModule: data.imageModule || null,
          imagePathList: data.imagePathList || null,
          skuModule: skuModule,
          titleModule: data.titleModule || null,
          priceModule: data.priceModule || null
        };
        var priceMap = findSkuPriceMap(data);
        if (priceMap) mods.PRICE = { skuIdStrPriceInfoMap: priceMap };
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

      var videoOnly = sections.length === 1 && sections[0] === 'videos';
      var mediaOnly = sections.length > 0
        && sections.indexOf('reviews') < 0 && sections.indexOf('description') < 0 && sections.indexOf('details') < 0;
      var fullCapture = sections.length === 0;

      if (fullCapture) {
        try {
          var toc = document.querySelector('a[href="#nav-description"], a.comet-v2-anchor-link[title*="escrip" i]');
          if (toc) { try { toc.click(); } catch (e1) {} }
          var nav = document.getElementById('nav-description')
            || document.querySelector('[data-pl="product-description"], [id*="description"]');
          if (nav && nav.scrollIntoView) nav.scrollIntoView({ behavior: 'instant', block: 'center' });
          await sleep(600);
        } catch (eScroll) {}
      } else if (videoOnly) {
        try {
          var gallery = document.querySelector('video, [class*="image-view"], [class*="slider--wrap"]');
          if (gallery && gallery.scrollIntoView) gallery.scrollIntoView({ behavior: 'instant', block: 'center' });
          await sleep(300);
        } catch (eVid) {}
      }

      var pageVideos = extractPageVideos();
      var rpData = mergedPageData();
      var compactRp = rpData ? compactRunModules(rpData) : null;
      var hasVideoData = pageVideos.length > 0 || (compactRp && compactRp.data && compactRp.data.imageModule);

      var descriptionHtml = '';
      if (!videoOnly) {
        var descRoot = document.querySelector(
          '#nav-description .detail-desc-decorate-richtext, #nav-description [class*="detail-desc"], ' +
          '[data-pl="product-description"] .detail-desc-decorate-richtext, .detail-desc-decorate-richtext, ' +
          '[itemprop="description"], [data-testid*="description" i], article [class*="description" i]'
        );
        if (descRoot && textLen(descRoot) > 20) descriptionHtml = descRoot.innerHTML || '';
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
      var md = document.querySelector('meta[name="description"], meta[property="og:description"]');
      var mi = document.querySelector('meta[property="og:image"]');
      var priceEl = queryDeep(
        '[class*="price-default--current"], [class*="price--current"], [itemprop="price"]',
        'price-default--current'
      );
      if (!priceEl) priceEl = queryDeep('[class*="price--current"]', 'price--current');
      var shipEl = document.querySelector('[class*="dynamic-shipping"]');
      var productId = '';
      var m = String(location.href).match(/(?:item|i)\/(\d{10,20})/i);
      if (m) productId = m[1];

      var jsonProducts = [];
      document.querySelectorAll('script[type="application/ld+json"]').forEach(function (script) {
        try {
          var parsed = JSON.parse(script.textContent || 'null');
          var rows = Array.isArray(parsed) ? parsed : [parsed];
          for (var ji = 0; ji < rows.length; ji++) {
            var row = rows[ji];
            if (!row || typeof row !== 'object') continue;
            if (Array.isArray(row['@graph'])) rows = rows.concat(row['@graph']);
            if (String(row['@type'] || '').toLowerCase().indexOf('product') >= 0) jsonProducts.push(row);
          }
        } catch (eJson) {}
      });
      var jsonProduct = jsonProducts[0] || {};
      var extractedImages = [];
      var productImages = Array.isArray(jsonProduct.image) ? jsonProduct.image : [jsonProduct.image];
      productImages.forEach(function (image) {
        var imageUrl = typeof image === 'string' ? image : (image && (image.url || image.contentUrl)) || '';
        imageUrl = absUrl(imageUrl);
        if (/^https?:\/\//i.test(imageUrl) && !extractedImages.includes(imageUrl)) extractedImages.push(imageUrl);
      });
      document.querySelectorAll('meta[property="og:image"], [itemprop="image"], main img, [class*="gallery" i] img').forEach(function (el) {
        var imageUrl = absUrl(el.content || el.currentSrc || el.src || el.getAttribute('data-src') || '');
        if (!/^https?:\/\//i.test(imageUrl) || /logo|icon|avatar|sprite/i.test(imageUrl)) return;
        if (el.tagName === 'IMG' && el.naturalWidth && el.naturalWidth < 120) return;
        if (!extractedImages.includes(imageUrl)) extractedImages.push(imageUrl);
      });
      extractedImages = extractedImages.slice(0, 40);
      var jsonDescription = typeof jsonProduct.description === 'string' ? jsonProduct.description : '';
      var aiSummary = '';
      var aiSelectors = [
        '[class*="ai-summary" i]', '[id*="ai-summary" i]', '[data-testid*="summary" i]',
        '[class*="product-summary" i]', '[class*="summary" i][class*="product" i]',
        '[itemprop="abstract"]', 'meta[name="description"]'
      ];
      for (var asi = 0; asi < aiSelectors.length && !aiSummary; asi++) {
        var summaryEl = document.querySelector(aiSelectors[asi]);
        if (summaryEl) aiSummary = String(summaryEl.content || summaryEl.innerText || '').replace(/\\s+/g, ' ').trim();
      }
      if (aiSummary && md && aiSummary === String(md.content || '').trim()) aiSummary = '';
      var extractedVariants = [];
      var offers = jsonProduct.offers || {};
      var offerRows = Array.isArray(offers) ? offers : (Array.isArray(offers.offers) ? offers.offers : [offers]);
      var structuredVariants = jsonProduct.hasVariant || jsonProduct.variant || [];
      if (!Array.isArray(structuredVariants)) structuredVariants = [structuredVariants];
      offerRows = offerRows.concat(structuredVariants);
      offerRows.forEach(function (offer, index) {
        if (!offer || typeof offer !== 'object') return;
        var nestedOffer = offer.offers && typeof offer.offers === 'object' ? offer.offers : offer;
        var sku = String(offer.sku || offer.mpn || nestedOffer.sku || '').trim();
        var name = String(offer.name || (jsonProduct.name ? jsonProduct.name + (index ? ' ' + (index + 1) : '') : '')).trim();
        if (!name && !sku) return;
        var variantSalePrice = nestedOffer.price || nestedOffer.lowPrice || null;
        extractedVariants.push({ sku: sku, name: name || sku, price: variantSalePrice, sale_price: variantSalePrice, currency: nestedOffer.priceCurrency || '', vid: String(offer.productID || offer.sku || offer.mpn || index), stock: nestedOffer.inventoryLevel || null });
      });
      document.querySelectorAll('[itemprop="offers"] [itemprop="sku"], [data-variant-id], [data-sku]').forEach(function (el, index) {
        var sku = String(el.getAttribute('content') || el.getAttribute('data-sku') || '').trim();
        var vid = String(el.getAttribute('data-variant-id') || el.getAttribute('data-sku') || sku || index).trim();
        var name = String(el.innerText || el.getAttribute('aria-label') || sku || '').replace(/\s+/g, ' ').trim();
        if (!name && !sku) return;
        if (!extractedVariants.some(function (variant) { return (variant.vid && variant.vid === vid) || (sku && variant.sku === sku); })) {
          extractedVariants.push({ sku: sku, name: name || sku, vid: vid, price: null, currency: '', stock: null });
        }
      });

      // 1) Marcar agotados del DOM (clase soldOut en [data-sku-col]) para omitirlos.
      // 2) Caminar cada variación/combinación clicándola y leer su precio visible.
      var domSku = extractDomSkuState();
      var wantWalk = fullCapture || sections.indexOf('variants') >= 0;
      var variantWalk = { prices: [], walked: false, groups: 0 };
      if (wantWalk) {
        try { variantWalk = await walkVariantPrices(); }
        catch (eW) { variantWalk = { prices: [], walked: false, groups: 0 }; }
      }
      extractedVariants.forEach(function (v) {
        var keys = [v.vid, v.sku].map(function (x) { return String(x || ''); }).filter(Boolean);
        for (var i = 0; i < keys.length; i++) {
          var st = domSku.byCol[keys[i]];
          if (st && st.soldOut) { v.sold_out = true; v.available = false; v.stock = 0; break; }
        }
        if (!v.sold_out && domSku.soldOutTexts.length) {
          var nm = String(v.name || '').toLowerCase();
          for (var t = 0; t < domSku.soldOutTexts.length; t++) {
            var s = String(domSku.soldOutTexts[t]).toLowerCase();
            if (s && (nm === s || nm.slice(-s.length - 2) === ': ' + s)) {
              v.sold_out = true; v.available = false; v.stock = 0; break;
            }
          }
        }
        if (v.available == null) v.available = !v.sold_out;
      });
      // Precio por variación clicada (una sola dimensión): completa variantes sin precio.
      // Con 2+ dimensiones, el backend cruza variantPrices con la clave de cada SKU.
      if (variantWalk.prices.length && variantWalk.groups === 1) {
        variantWalk.prices.forEach(function (wp) {
          if (!wp.cols || !wp.cols.length || !(wp.price > 0)) return;
          var col = wp.cols[0];
          extractedVariants.forEach(function (v) {
            if (String(v.vid) === col || String(v.sku) === col) {
              if (v.price == null) v.price = wp.price;
              v.sale_price = wp.price;
              if (!v.currency && wp.currency) v.currency = wp.currency;
              if (wp.available === false) { v.sold_out = true; v.available = false; v.stock = 0; }
            }
          });
        });
      }

      return {
        url: location.href,
        html: html,
        snapshot: {
          productId: productId,
          runParams: compactRp,
          h1: h1el ? String(h1el.innerText || '').trim() : '',
          ogTitle: mt ? (mt.getAttribute('content') || '') : '',
          title: String(jsonProduct.name || '').trim(),
          descriptionText: (md ? String(md.content || '').trim() : '') || jsonDescription,
          aiSummary: aiSummary,
          ogImage: mi ? (mi.getAttribute('content') || '') : '',
          images: extractedImages,
          priceText: priceEl ? String(priceEl.innerText || priceEl.getAttribute('content') || '') : '',
          shippingText: shipEl ? String(shipEl.innerText || '').replace(/\s+/g, ' ').trim() : '',
          descriptionHtml: descriptionHtml,
          descriptionUrl: descriptionUrl,
          variants: extractedVariants,
          soldOutSkus: domSku.soldOutCols,
          variantPrices: variantWalk.prices,
          domSkuOptions: Object.keys(domSku.byCol).map(function (k) { return domSku.byCol[k]; }),
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
          sendResponse({ ok: false, error: 'No pude leer la página activa.' });
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
        if (!payload || !payload.url) { sendResponse({ ok: false, error: 'No pude leer la página activa.' }); return; }
        var cfg = await chrome.storage.sync.get(['origin', 'token', 'store_id']);
        var d = defaults();
        var origin = String(cfg.origin || d.origin || '').replace(/\/+$/, '');
        var token = String(cfg.token || '');
        var storeId = parseInt(msg.store_id != null ? msg.store_id : cfg.store_id, 10) || 0;
        if (!origin || !token) { sendResponse({ ok: false, error: 'Configura la URL y el token del plugin.' }); return; }
        if (!storeId) { sendResponse({ ok: false, error: 'Elige una tienda antes de enviar.' }); return; }
        var out = await postPlugin(origin, d.capture_path, token, {
          token: token,
          store_id: storeId,
          url: payload.url,
          html: payload.html || '',
          snapshot: payload.snapshot || {}
        });
        if (!out.res.ok || !out.json.success) {
          sendResponse({ ok: false, error: out.json.error || out.json.message || ('HTTP ' + out.res.status) });
          return;
        }
        sendResponse({
          ok: true,
          message: out.json.message || 'Producto enviado a Multidrop.',
          product_id: out.json.product_id,
          edit_url: out.json.edit_url,
          title: out.json.title
        });
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
 * Localiza la imagen de mejor resolución en la página actual.
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
        if (!/^https?:\/\//i.test(url)) return;
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
    title: title || 'Multidrop Product Extractor',
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
        '*://*/*'
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
      notifyUser('Multidrop Extractor', 'Abre el plugin, configura el token y busca el producto destino por SKU.');
      return;
    }
    if (!storeId || !productId) {
      notifyUser('Multidrop Extractor', 'Busca primero el SKU del producto destino en el plugin.');
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
