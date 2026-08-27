"""Re-descarga imágenes en mayor resolución y actualiza JSON + seed data."""
from __future__ import annotations

import json
import re
import urllib.request
from pathlib import Path

ROOT = Path(r"f:\xampp82\htdocs\html\multidrop")
JSON_PATH = ROOT / "storage" / "app" / "aliexpress_products.json"
IMG_DIR = ROOT / "public" / "media" / "products"
DEBUG = ROOT / "storage" / "app" / "ae_debug"


def enlarge(url: str) -> list[str]:
    if url.startswith("//"):
        url = "https:" + url
    url = url.split("?")[0]
    # strip nested size suffixes
    base = re.sub(r"(_\d+x\d+[^/]*)+$", "", url)
    base = re.sub(r"\.jpg_.*$", ".jpg", base)
    base = re.sub(r"\.png_.*$", ".png", base)
    base = re.sub(r"\.webp$", "", base)
    if not base.endswith((".jpg", ".png", ".jpeg")):
        base = base + ".jpg"
    candidates = [
        re.sub(r"\.(jpg|png|jpeg)$", r"_800x800q90.\1", base, flags=re.I),
        re.sub(r"\.(jpg|png|jpeg)$", r"_640x640q90.\1", base, flags=re.I),
        base,
        url,
    ]
    # unique preserve order
    out = []
    for c in candidates:
        if c not in out:
            out.append(c)
    return out


def download(url: str, dest: Path) -> int:
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0.0.0",
            "Referer": "https://www.aliexpress.com/",
            "Accept": "image/avif,image/webp,image/*,*/*",
        },
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        data = r.read()
    dest.write_bytes(data)
    return len(data)


def find_remote_for_id(pid: str) -> str | None:
    for html_path in sorted(DEBUG.glob("page_*.html")):
        html = html_path.read_text(encoding="utf-8", errors="ignore")
        # look near product id for imgUrl
        for m in re.finditer(rf'"productId"\s*:\s*"?{pid}"?(?P<body>[\s\S]{{0,1800}})', html):
            im = re.search(r'"imgUrl"\s*:\s*"(?P<img>[^"]+)"', m.group("body"))
            if im:
                u = im.group("img")
                if u.startswith("//"):
                    u = "https:" + u
                return u
        for m in re.finditer(rf'"imgUrl"\s*:\s*"(?P<img>[^"]+)"[\s\S]{{0,400}}?"productId"\s*:\s*"?{pid}"?', html):
            u = m.group("img")
            if u.startswith("//"):
                u = "https:" + u
            return u
    return None


def main():
    items = json.loads(JSON_PATH.read_text(encoding="utf-8"))
    IMG_DIR.mkdir(parents=True, exist_ok=True)
    updated = []
    for item in items:
        pid = item["product_id"]
        remote = find_remote_for_id(pid) or item.get("remote_image")
        if not remote:
            # try reconstruct from local only - skip
            print("no remote", pid)
            updated.append(item)
            continue
        item["remote_image"] = remote
        dest = IMG_DIR / f"{pid}.jpg"
        best = 0
        for cand in enlarge(remote):
            try:
                size = download(cand, dest)
                print(pid, size, cand[:80])
                if size > best:
                    best = size
                if size > 20000:
                    break
            except Exception as e:
                print("fail", pid, cand[:60], e)
        if best > 1500:
            item["image_url"] = f"/media/products/{pid}.jpg"
            item["local_image"] = item["image_url"]
        # normalize prices to whole pesos
        if item.get("price_mxn"):
            p = float(item["price_mxn"])
            if p < 50:
                p = max(99, round(p * 8))
            item["price_mxn"] = round(p)
            item["compare_at_price"] = round(p * 1.55)
        updated.append(item)

    JSON_PATH.write_text(json.dumps(updated, ensure_ascii=False, indent=2), encoding="utf-8")
    print("done", len(updated))


if __name__ == "__main__":
    main()
