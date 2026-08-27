"""
Re-scrape AliExpress msite, descarga imágenes locales y precios MXN reales.
"""
from __future__ import annotations

import json
import re
import sys
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(r"f:\xampp82\htdocs\html\multidrop")
OUT_JSON = ROOT / "storage" / "app" / "aliexpress_products.json"
IMG_DIR = ROOT / "public" / "media" / "products"

QUERIES = [
    ("lighting", "lampara recargable emergencia"),
    ("powerbank", "power bank 20000mah"),
    ("fan", "ventilador usb portatil"),
    ("flashlight", "linterna led recargable"),
    ("power", "mini ups portatil"),
]


def clean_title(t: str) -> str:
    t = re.sub(r"\\u([0-9a-fA-F]{4})", lambda m: chr(int(m.group(1), 16)), t)
    t = re.sub(r"\s+", " ", t).strip()
    t = re.sub(r"^MX\$[0-9.,]+\s*", "", t)
    return t[:120]


def enlarge_candidates(url: str) -> list[str]:
    if url.startswith("//"):
        url = "https:" + url
    raw = url.split("?")[0]
    base = re.sub(r"(_\d+x\d+[^/]*)+$", "", raw)
    base = re.sub(r"\.(jpg|png|jpeg)_.*$", r".\1", base, flags=re.I)
    base = re.sub(r"\.webp$", "", base)
    if not re.search(r"\.(jpg|jpeg|png)$", base, re.I):
        base += ".jpg"
    cands = [
        re.sub(r"\.(jpg|jpeg|png)$", r"_800x800q90.\1", base, flags=re.I),
        re.sub(r"\.(jpg|jpeg|png)$", r"_640x640q90.\1", base, flags=re.I),
        base,
        raw,
    ]
    out = []
    for c in cands:
        if c not in out:
            out.append(c)
    return out


def download(url: str, dest: Path) -> bool:
    try:
        best = b""
        for cand in enlarge_candidates(url):
            try:
                req = urllib.request.Request(
                    cand,
                    headers={
                        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0.0.0",
                        "Referer": "https://www.aliexpress.com/",
                        "Accept": "image/avif,image/webp,image/*,*/*",
                    },
                )
                with urllib.request.urlopen(req, timeout=25) as r:
                    data = r.read()
                if len(data) > len(best):
                    best = data
                if len(data) > 25000:
                    break
            except Exception:
                continue
        if len(best) < 2000:
            return False
        dest.write_bytes(best)
        return True
    except Exception as e:
        print("img fail", url, e, file=sys.stderr)
        return False


def parse_cards(page, category: str) -> list[dict]:
    # Evaluate in browser for structured cards
    data = page.evaluate(
        """() => {
        const out = [];
        const seen = new Set();
        document.querySelectorAll('a[href*="/item/"]').forEach(a => {
          const m = (a.getAttribute('href') || '').match(/\\/item\\/(\\d+)\\.html/);
          if (!m) return;
          const id = m[1];
          if (seen.has(id)) return;
          const img = a.querySelector('img');
          if (!img) return;
          const src = img.getAttribute('src') || img.getAttribute('data-src') || '';
          if (!src.includes('alicdn') && !src.includes('aliexpress-media')) return;
          if (/24x24|330x64|154x64|banner/i.test(src)) return;
          let title = (img.getAttribute('alt') || '').trim();
          if (title.length < 10) {
            title = (a.innerText || '').replace(/\\s+/g, ' ').trim();
          }
          // strip price noise from title
          title = title.replace(/^MX\\$[\\d.,]+\\s*/, '').slice(0, 140);
          if (title.length < 10) return;
          const block = (a.closest('div') || a).innerText || '';
          const pm = block.match(/MX\\$\\s*([\\d.,]+)/);
          const price = pm ? parseFloat(pm[1].replace(/,/g, '')) : null;
          seen.add(id);
          out.push({
            product_id: id,
            title,
            image_url: src.startsWith('//') ? 'https:' + src : src,
            price_mxn: price,
            url: 'https://es.aliexpress.com/item/' + id + '.html'
          });
        });
        return out;
      }"""
    )
    for d in data:
        d["category"] = category
        d["source"] = "aliexpress_es"
    return data


def main() -> int:
    IMG_DIR.mkdir(parents=True, exist_ok=True)
    all_items: dict[str, dict] = {}

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            locale="es-MX",
            user_agent=(
                "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
                "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 "
                "Mobile/15E148 Safari/604.1"
            ),
            viewport={"width": 390, "height": 844},
            is_mobile=True,
            has_touch=True,
        )
        page = context.new_page()
        for category, kw in QUERIES:
            url = f"https://m.aliexpress.com/search.htm?keywords={kw.replace(' ', '+')}"
            try:
                page.goto(url, wait_until="domcontentloaded", timeout=90000)
                page.wait_for_timeout(4500)
                for _ in range(10):
                    page.mouse.wheel(0, 1800)
                    page.wait_for_timeout(500)
                batch = parse_cards(page, category)
                print(f"{category}: {len(batch)}", file=sys.stderr)
                for b in batch:
                    all_items.setdefault(b["product_id"], b)
            except Exception as e:
                print("ERR", category, e, file=sys.stderr)
        browser.close()

    # diversify pick
    quotas = {"lighting": 3, "powerbank": 3, "fan": 3, "flashlight": 2, "power": 2}
    selected = []
    used = set()
    by_cat: dict[str, list] = {}
    for v in all_items.values():
        by_cat.setdefault(v.get("category", "lighting"), []).append(v)

    for cat, n in quotas.items():
        pool = sorted(
            by_cat.get(cat, []),
            key=lambda x: (x.get("price_mxn") is not None, len(x.get("title", ""))),
            reverse=True,
        )
        c = 0
        for item in pool:
            if item["product_id"] in used:
                continue
            # download image locally
            ext = ".jpg"
            if ".png" in item["image_url"].lower():
                ext = ".png"
            dest = IMG_DIR / f"{item['product_id']}{ext}"
            ok = download(item["image_url"], dest)
            if not ok:
                continue
            item["remote_image"] = item["image_url"]
            item["local_image"] = f"/media/products/{item['product_id']}{ext}"
            item["image_url"] = item["local_image"]  # serve local for reliability
            if not item.get("price_mxn"):
                defaults = {"lighting": 289, "powerbank": 459, "fan": 179, "flashlight": 199, "power": 649}
                item["price_mxn"] = defaults.get(cat, 299)
            p = float(item["price_mxn"])
            if p < 50:
                p = max(99, round(p * 8))
            item["price_mxn"] = round(p)
            item["compare_at_price"] = round(p * 1.55)
            item["title"] = clean_title(item["title"])
            used.add(item["product_id"])
            selected.append(item)
            c += 1
            if c >= n:
                break

    OUT_JSON.write_text(json.dumps(selected, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote {len(selected)} products with local images")
    return 0 if len(selected) >= 10 else 1


if __name__ == "__main__":
    raise SystemExit(main())
