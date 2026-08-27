"""Extrae >=10 productos de búsquedas AliExpress ES vía Playwright."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

OUT = Path(r"f:\xampp82\htdocs\html\multidrop\storage\app\aliexpress_products.json")
QUERIES = [
    "https://es.aliexpress.com/w/wholesale-lampara-recargable.html",
    "https://es.aliexpress.com/w/wholesale-power-bank.html",
    "https://es.aliexpress.com/w/wholesale-ventilador-usb.html",
    "https://es.aliexpress.com/w/wholesale-linterna-led.html",
]


def normalize_img(url: str | None) -> str | None:
    if not url:
        return None
    if url.startswith("//"):
        url = "https:" + url
    # pedir tamaño razonable
    url = re.sub(r"_\d+x\d+\.", "_500x500.", url)
    return url


def parse_cards(page) -> list[dict]:
    # Intentar JSON embebido primero
    products = []
    html = page.content()
    for pattern in [
        r"window\.runParams\s*=\s*(\{.*?\});?\s*</script>",
        r'"mods"\s*:\s*(\{.*?"itemList".*?\})\s*,\s*"traceInfo"',
    ]:
        m = re.search(pattern, html, re.DOTALL)
        if m:
            try:
                # demasiado frágil; seguir a DOM
                pass
            except Exception:
                pass

    # DOM cards — selectores flexibles AliExpress 2025/26
    cards = page.query_selector_all("a[href*='/item/']")
    seen = set()
    for a in cards:
        href = a.get_attribute("href") or ""
        m = re.search(r"/item/(\d+)\.html", href)
        if not m:
            continue
        pid = m.group(1)
        if pid in seen:
            continue

        title = (a.get_attribute("title") or a.inner_text() or "").strip()
        title = re.sub(r"\s+", " ", title)
        if len(title) < 12:
            # buscar img alt
            img_el = a.query_selector("img")
            if img_el:
                title = (img_el.get_attribute("alt") or title).strip()
        if len(title) < 8:
            continue

        img = None
        img_el = a.query_selector("img")
        if img_el:
            img = img_el.get_attribute("src") or img_el.get_attribute("data-src")
        img = normalize_img(img)
        if not img or "alicdn" not in img and "aliexpress-media" not in img:
            # a veces lazy
            if img_el:
                img = normalize_img(img_el.get_attribute("data-spm-anchor-id") and None)
            continue

        # precio cerca del link
        price = None
        parent = a.evaluate_handle("el => el.closest('div') || el.parentElement")
        try:
            parent_text = parent.as_element().inner_text() if parent else ""
        except Exception:
            parent_text = a.inner_text()
        pm = re.search(r"(?:MXN|US\s*\$|€|\$)\s*([0-9]+(?:[.,][0-9]+)?)", parent_text)
        if not pm:
            pm = re.search(r"([0-9]+[.,][0-9]{2})", parent_text)
        if pm:
            price = float(pm.group(1).replace(",", "."))

        url = href if href.startswith("http") else "https://es.aliexpress.com" + href.split("?")[0]
        seen.add(pid)
        products.append(
            {
                "product_id": pid,
                "title": title[:180],
                "image_url": img,
                "price_raw": price,
                "url": url.split("?")[0],
                "source": "aliexpress_es",
            }
        )
    return products


def main() -> int:
    collected: list[dict] = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            locale="es-MX",
            user_agent=(
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/122.0.0.0 Safari/537.36"
            ),
            viewport={"width": 1400, "height": 900},
        )
        page = context.new_page()
        for url in QUERIES:
            try:
                page.goto(url, wait_until="domcontentloaded", timeout=60000)
                page.wait_for_timeout(5000)
                # scroll para lazy images
                for _ in range(6):
                    page.mouse.wheel(0, 1200)
                    page.wait_for_timeout(800)
                batch = parse_cards(page)
                print(f"{url} -> {len(batch)} cards", file=sys.stderr)
                collected.extend(batch)
            except Exception as e:
                print(f"ERR {url}: {e}", file=sys.stderr)

        browser.close()

    # unique by product_id
    uniq = {}
    for p in collected:
        uniq[p["product_id"]] = p
    items = list(uniq.values())[:16]
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(items, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote {len(items)} products to {OUT}")
    return 0 if len(items) >= 10 else 1


if __name__ == "__main__":
    raise SystemExit(main())
