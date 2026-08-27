"""Construye catálogo limpio y diverso desde HTMLs debug + JSON scrape."""
from __future__ import annotations

import json
import re
from pathlib import Path

DEBUG = Path(r"f:\xampp82\htdocs\html\multidrop\storage\app\ae_debug")
OUT = Path(r"f:\xampp82\htdocs\html\multidrop\storage\app\aliexpress_products.json")

CATEGORY_HINTS = {
    0: "lighting",
    1: "powerbank",
    2: "fan",
    3: "flashlight",
    4: "power",
}


def normalize_img(url: str) -> str:
    if url.startswith("//"):
        url = "https:" + url
    # Prefer jpg preview
    url = url.replace(".jpg_.webp", ".jpg")
    url = url.replace(".png_.webp", ".png")
    url = re.sub(r"_\d+x\d+q?\d*\.", "_500x500.", url)
    return url


def is_good_image(url: str) -> bool:
    bad = ["330x64", "154x64", "banner", "logo"]
    u = url.lower()
    if any(b in u for b in bad):
        return False
    return ("alicdn" in u or "aliexpress-media" in u) and (
        ".jpg" in u or ".jpeg" in u or ".webp" in u or ".png" in u
    )


def clean_title(title: str) -> str:
    title = re.sub(r"\s+", " ", title).strip()
    title = re.sub(r"^MX\$[0-9.,]+\s*", "", title)
    title = re.sub(r"MX\$[0-9.,]+.*?vendidos\s*", "", title, flags=re.I)
    title = re.sub(r"\d+\.\d+\s*$", "", title)
    title = re.sub(r"Env[ií]o gratis.*$", "", title, flags=re.I)
    title = re.sub(r"-?\d+%\s*con monedas.*$", "", title, flags=re.I)
    title = re.sub(r"\d[\d.,]*\+?\s*vendidos.*$", "", title, flags=re.I)
    return title.strip(" -|")[:140]


def extract_price_mxn(text: str) -> float | None:
    m = re.search(r"MX\$\s*([0-9]+(?:[.,][0-9]+)?)", text)
    if m:
        return float(m.group(1).replace(",", ""))
    return None


def parse_html(path: Path, category: str) -> list[dict]:
    html = path.read_text(encoding="utf-8", errors="ignore")
    items = {}

    # productId + imgUrl + displayTitle blobs
    for m in re.finditer(r'"productId"\s*:\s*"?(?P<id>\d+)"?', html):
        items.setdefault(m.group("id"), {"product_id": m.group("id"), "category": category})

    for m in re.finditer(
        r'"productId"\s*:\s*"?(?P<id>\d+)"?[\s\S]{0,900}?"imgUrl"\s*:\s*"(?P<img>[^"]+)"',
        html,
    ):
        img = normalize_img(m.group("img"))
        if is_good_image(img):
            items.setdefault(m.group("id"), {"product_id": m.group("id"), "category": category})
            items[m.group("id")]["image_url"] = img

    for m in re.finditer(
        r'"imgUrl"\s*:\s*"(?P<img>[^"]+)"[\s\S]{0,900}?"productId"\s*:\s*"?(?P<id>\d+)"?',
        html,
    ):
        img = normalize_img(m.group("img"))
        if is_good_image(img):
            items.setdefault(m.group("id"), {"product_id": m.group("id"), "category": category})
            items[m.group("id")]["image_url"] = img

    for m in re.finditer(
        r'"displayTitle"\s*:\s*"(?P<title>(?:\\.|[^"\\])+)"[\s\S]{0,600}?"productId"\s*:\s*"?(?P<id>\d+)"?',
        html,
    ):
        title = clean_title(m.group("title").encode().decode("unicode_escape", errors="ignore"))
        if len(title) > 10:
            items.setdefault(m.group("id"), {"product_id": m.group("id"), "category": category})
            items[m.group("id")]["title"] = title

    for m in re.finditer(
        r'"productId"\s*:\s*"?(?P<id>\d+)"?[\s\S]{0,800}?"displayTitle"\s*:\s*"(?P<title>(?:\\.|[^"\\])+)"',
        html,
    ):
        title = clean_title(m.group("title").encode().decode("unicode_escape", errors="ignore"))
        if len(title) > 10:
            items.setdefault(m.group("id"), {"product_id": m.group("id"), "category": category})
            items[m.group("id")]["title"] = title

    # prices
    for m in re.finditer(
        r'"productId"\s*:\s*"?(?P<id>\d+)"?[\s\S]{0,1200}?"salePrice"\s*:\s*"?(?P<p>[0-9]+(?:\.[0-9]+)?)"?',
        html,
    ):
        if m.group("id") in items:
            items[m.group("id")]["price_usd"] = float(m.group("p"))

    for m in re.finditer(r"MX\$\s*([0-9]+(?:[.,][0-9]+)?)", html):
        pass

    # DOM-ish titles in anchors (fallback from existing json style strings)
    for m in re.finditer(
        r'href="[^"]*/item/(?P<id>\d+)\.html[^"]*"[^>]*>[\s\S]{0,400}?alt="(?P<alt>[^"]{12,180})"',
        html,
    ):
        pid = m.group("id")
        items.setdefault(pid, {"product_id": pid, "category": category})
        if not items[pid].get("title"):
            items[pid]["title"] = clean_title(m.group("alt"))

    for m in re.finditer(
        r'src="(?P<img>https?://[^"]*alicdn[^"]+|https?://[^"]*aliexpress-media[^"]+)"[^>]*(?:alt="(?P<alt>[^"]*)")?',
        html,
    ):
        img = normalize_img(m.group("img"))
        if not is_good_image(img):
            continue
        # try find nearby item id in surrounding 500 chars - skip if unknown

    ready = []
    for v in items.values():
        if v.get("image_url") and v.get("title") and is_good_image(v["image_url"]):
            v["url"] = f"https://es.aliexpress.com/item/{v['product_id']}.html"
            v["source"] = "aliexpress_es"
            # MXN estimate if only USD
            if "price_mxn" not in v:
                if "price_usd" in v:
                    v["price_mxn"] = round(v["price_usd"] * 17.2, 0)
            ready.append(v)
    return ready


def main():
    by_cat: dict[str, list] = {}
    for i, hint in CATEGORY_HINTS.items():
        path = DEBUG / f"page_{i}.html"
        if not path.exists():
            continue
        by_cat[hint] = parse_html(path, hint)
        print(f"{hint}: {len(by_cat[hint])}")

    # pick diverse top N
    selected = []
    per = {"lighting": 3, "powerbank": 3, "fan": 3, "flashlight": 2, "power": 2}
    used = set()
    for cat, n in per.items():
        pool = by_cat.get(cat, [])
        # prefer longer titles, jpg images
        pool = sorted(pool, key=lambda x: (("jpg" in x["image_url"]), len(x["title"])), reverse=True)
        c = 0
        for p in pool:
            if p["product_id"] in used:
                continue
            used.add(p["product_id"])
            # default price bands if missing
            if not p.get("price_mxn"):
                defaults = {
                    "lighting": 279,
                    "powerbank": 449,
                    "fan": 199,
                    "flashlight": 189,
                    "power": 599,
                }
                p["price_mxn"] = defaults.get(cat, 299)
            p["compare_at_price"] = round(p["price_mxn"] * 1.55, 0)
            selected.append(p)
            c += 1
            if c >= n:
                break

    # fill to at least 12
    if len(selected) < 12:
        for cat, pool in by_cat.items():
            for p in pool:
                if p["product_id"] in used:
                    continue
                if not p.get("price_mxn"):
                    p["price_mxn"] = 299
                p["compare_at_price"] = round(p["price_mxn"] * 1.55, 0)
                selected.append(p)
                used.add(p["product_id"])
                if len(selected) >= 12:
                    break
            if len(selected) >= 12:
                break

    OUT.write_text(json.dumps(selected[:14], ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote {min(14, len(selected))} products")


if __name__ == "__main__":
    main()
