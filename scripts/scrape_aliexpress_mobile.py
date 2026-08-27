"""Scrape AliExpress mobile search (menos anti-bot) y extrae productos."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

OUT = Path(r"f:\xampp82\htdocs\html\multidrop\storage\app\aliexpress_products.json")
DEBUG = Path(r"f:\xampp82\htdocs\html\multidrop\storage\app\ae_debug")

KEYWORDS = [
    "lampara recargable",
    "power bank",
    "ventilador usb",
    "linterna led recargable",
    "mini ups",
]


def normalize_img(url: str | None) -> str | None:
    if not url:
        return None
    if url.startswith("//"):
        url = "https:" + url
    return url


def extract_from_html(html: str, source_url: str) -> list[dict]:
    products = []
    # product ids in links
    for m in re.finditer(r"https?://(?:[a-z]+\.)?aliexpress\.[a-z.]+/item/(\d+)\.html", html):
        pid = m.group(1)
        products.append({"product_id": pid, "url": m.group(0), "source_url": source_url})

    # also relative
    for m in re.finditer(r"/item/(\d+)\.html", html):
        pid = m.group(1)
        products.append(
            {
                "product_id": pid,
                "url": f"https://es.aliexpress.com/item/{pid}.html",
                "source_url": source_url,
            }
        )

    # images on alicdn near titles in JSON blobs
    # Common AE JSON fields
    for m in re.finditer(
        r'"productId"\s*:\s*"?(?P<id>\d+)"?.*?"(?:displayTitle|title)"\s*:\s*\{?"?(?:displayTitle"\s*:\s*")?(?P<title>[^"\\]{10,160})"',
        html,
    ):
        pass

    # More reliable: imgUrl + productId pairs in JSON
    for m in re.finditer(
        r'"productId"\s*:\s*"?(?P<id>\d+)"?[^\{]{0,400}?"imgUrl"\s*:\s*"(?P<img>[^"]+)"',
        html,
    ):
        products.append(
            {
                "product_id": m.group("id"),
                "image_url": normalize_img(m.group("img")),
                "url": f"https://es.aliexpress.com/item/{m.group('id')}.html",
                "source_url": source_url,
            }
        )

    for m in re.finditer(
        r'"imgUrl"\s*:\s*"(?P<img>[^"]+)"[^\{]{0,400}?"productId"\s*:\s*"?(?P<id>\d+)"?',
        html,
    ):
        products.append(
            {
                "product_id": m.group("id"),
                "image_url": normalize_img(m.group("img")),
                "url": f"https://es.aliexpress.com/item/{m.group('id')}.html",
                "source_url": source_url,
            }
        )

    for m in re.finditer(
        r'"displayTitle"\s*:\s*"(?P<title>(?:\\.|[^"\\]){8,200})"[\s\S]{0,500}?"productId"\s*:\s*"?(?P<id>\d+)"?',
        html,
    ):
        title = bytes(m.group("title"), "utf-8").decode("unicode_escape", errors="ignore")
        products.append(
            {
                "product_id": m.group("id"),
                "title": title,
                "url": f"https://es.aliexpress.com/item/{m.group('id')}.html",
                "source_url": source_url,
            }
        )

    for m in re.finditer(
        r'"productId"\s*:\s*"?(?P<id>\d+)"?[\s\S]{0,800}?"displayTitle"\s*:\s*"(?P<title>(?:\\.|[^"\\]){8,200})"',
        html,
    ):
        title = m.group("title").encode("utf-8").decode("unicode_escape", errors="ignore")
        products.append(
            {
                "product_id": m.group("id"),
                "title": title,
                "url": f"https://es.aliexpress.com/item/{m.group('id')}.html",
                "source_url": source_url,
            }
        )

    # prices
    price_map = {}
    for m in re.finditer(
        r'"productId"\s*:\s*"?(?P<id>\d+)"?[\s\S]{0,1200}?"salePrice"\s*:\s*"?(?P<p>[0-9]+(?:\.[0-9]+)?)"?',
        html,
    ):
        price_map[m.group("id")] = float(m.group("p"))
    for m in re.finditer(
        r'"salePriceFormatted"\s*:\s*"(?P<f>[^"]+)"[\s\S]{0,400}?"productId"\s*:\s*"?(?P<id>\d+)"?',
        html,
    ):
        num = re.search(r"([0-9]+(?:[.,][0-9]+)?)", m.group("f").replace(",", ""))
        if num:
            price_map.setdefault(m.group("id"), float(num.group(1)))

    # merge by id
    merged = {}
    for p in products:
        pid = p["product_id"]
        cur = merged.setdefault(pid, {"product_id": pid, "source": "aliexpress_es"})
        for k, v in p.items():
            if v and not cur.get(k):
                cur[k] = v
        if pid in price_map:
            cur["price_usd"] = price_map[pid]
    return list(merged.values())


def main() -> int:
    DEBUG.mkdir(parents=True, exist_ok=True)
    all_items: dict[str, dict] = {}

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            locale="es-ES",
            user_agent=(
                "Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) "
                "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 "
                "Mobile/15E148 Safari/604.1"
            ),
            viewport={"width": 390, "height": 844},
            is_mobile=True,
            has_touch=True,
        )
        page = context.new_page()

        urls = [
            f"https://m.aliexpress.com/search.htm?keywords={kw.replace(' ', '+')}"
            for kw in KEYWORDS
        ] + [
            "https://es.aliexpress.com/w/wholesale-lampara-recargable.html",
            "https://www.aliexpress.com/w/wholesale-rechargeable-camping-lantern.html",
        ]

        for i, url in enumerate(urls):
            try:
                page.goto(url, wait_until="networkidle", timeout=90000)
                page.wait_for_timeout(4000)
                for _ in range(8):
                    page.mouse.wheel(0, 1600)
                    page.wait_for_timeout(600)
                html = page.content()
                (DEBUG / f"page_{i}.html").write_text(html, encoding="utf-8", errors="ignore")
                page.screenshot(path=str(DEBUG / f"page_{i}.png"), full_page=False)
                batch = extract_from_html(html, url)
                # enrich from DOM
                for a in page.query_selector_all("a[href*='/item/']"):
                    href = a.get_attribute("href") or ""
                    m = re.search(r"/item/(\d+)\.html", href)
                    if not m:
                        continue
                    pid = m.group(1)
                    item = all_items.setdefault(
                        pid,
                        {
                            "product_id": pid,
                            "url": f"https://es.aliexpress.com/item/{pid}.html",
                            "source": "aliexpress_es",
                        },
                    )
                    img = a.query_selector("img")
                    if img:
                        src = normalize_img(img.get_attribute("src") or img.get_attribute("data-src"))
                        alt = (img.get_attribute("alt") or "").strip()
                        if src and ("alicdn" in src or "aliexpress-media" in src):
                            item["image_url"] = src
                        if alt and len(alt) > 8:
                            item["title"] = alt[:180]
                    txt = (a.inner_text() or "").strip()
                    if (not item.get("title")) and len(txt) > 12:
                        item["title"] = re.sub(r"\s+", " ", txt)[:180]

                for b in batch:
                    pid = b["product_id"]
                    cur = all_items.setdefault(pid, {"product_id": pid, "source": "aliexpress_es"})
                    for k, v in b.items():
                        if v and not cur.get(k):
                            cur[k] = v
                print(f"{url} -> html_products={len(batch)} total={len(all_items)}", file=sys.stderr)
            except Exception as e:
                print(f"ERR {url}: {e}", file=sys.stderr)

        browser.close()

    # keep only with image + title
    ready = [
        v
        for v in all_items.values()
        if v.get("image_url") and v.get("title") and len(v.get("title", "")) > 8
    ]
    # if still short, keep with at least image
    if len(ready) < 10:
        ready = [v for v in all_items.values() if v.get("image_url")]
    ready = ready[:14]
    OUT.write_text(json.dumps(ready, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote {len(ready)} products")
    return 0 if len(ready) >= 10 else 1


if __name__ == "__main__":
    raise SystemExit(main())
