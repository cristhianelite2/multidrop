from pathlib import Path
import re
import json

DEBUG = Path(r"f:\xampp82\htdocs\html\multidrop\storage\app\ae_debug")
OUT = Path(r"f:\xampp82\htdocs\html\multidrop\storage\app\aliexpress_products.json")
CATS = {0: ("lighting", 279), 1: ("powerbank", 449), 2: ("fan", 199), 3: ("flashlight", 189), 4: ("power", 599)}


def good_img(url: str) -> bool:
    u = url.lower()
    if any(x in u for x in ("330x64", "154x64", "24x24", "banner")):
        return False
    return ("alicdn" in u or "aliexpress-media" in u)


def norm_img(url: str) -> str:
    if url.startswith("//"):
        url = "https:" + url
    url = url.replace(".jpg_.webp", ".jpg").replace(".png_.webp", ".png")
    return re.sub(r"_\d+x\d+q?\d*\.", "_500x500.", url)


def clean_title(t: str) -> str:
    # unescape common unicode sequences without breaking utf-8 spanish
    def repl(m):
        try:
            return chr(int(m.group(1), 16))
        except Exception:
            return m.group(0)

    t = re.sub(r"\\u([0-9a-fA-F]{4})", repl, t)
    t = t.replace('\\"', '"')
    t = re.sub(r"\s+", " ", t).strip()
    t = re.sub(r"^MX\$[0-9.,]+\s*", "", t)
    return t[:130]


def parse(html: str, category: str, default_price: float):
    items = {}

    # Pattern: productId ... imgUrl ... seoTitle OR nearby
    for m in re.finditer(r'"productId"\s*:\s*"?(?P<id>\d+)"?', html):
        items.setdefault(m.group("id"), {"product_id": m.group("id"), "category": category, "_pos": m.start()})

    # attach nearest imgUrl / seoTitle after productId within window
    for pid, item in list(items.items()):
        pos = item["_pos"]
        window = html[pos : pos + 2500]
        im = re.search(r'"imgUrl"\s*:\s*"(?P<img>[^"]+)"', window)
        if im:
            img = norm_img(im.group("img"))
            if good_img(img):
                item["image_url"] = img
        tm = re.search(r'"seoTitle"\s*:\s*"(?P<title>(?:\\.|[^"\\])+)"', window)
        if not tm:
            tm = re.search(r'"displayTitle"\s*:\s*"(?P<title>(?:\\.|[^"\\])+)"', window)
        if tm:
            item["title"] = clean_title(tm.group("title"))
        pm = re.search(r'"salePrice"\s*:\s*"?(?P<p>[0-9]+(?:\.[0-9]+)?)"?', window)
        if pm:
            val = float(pm.group("p"))
            item["price_mxn"] = round(val * 17.2 if val < 120 else val, 0)
        mx = re.search(r"MX\\?\$?\s*([0-9]+(?:\.[0-9]+)?)", window)
        if mx:
            item["price_mxn"] = float(mx.group(1))

    ready = []
    for v in items.values():
        v.pop("_pos", None)
        if not v.get("image_url") or not v.get("title") or len(v["title"]) < 12:
            continue
        v.setdefault("price_mxn", default_price)
        v["compare_at_price"] = round(float(v["price_mxn"]) * 1.55, 0)
        v["url"] = f"https://es.aliexpress.com/item/{v['product_id']}.html"
        v["source"] = "aliexpress_es"
        ready.append(v)
    return ready


def main():
    pools = {}
    for i, (cat, price) in CATS.items():
        p = DEBUG / f"page_{i}.html"
        html = p.read_text(encoding="utf-8", errors="ignore") if p.exists() else ""
        pools[cat] = parse(html, cat, price)
        print(cat, len(pools[cat]), "sample:", (pools[cat][0]["title"][:50] if pools[cat] else "-"))

    selected, used = [], set()
    for cat, n in [("lighting", 3), ("powerbank", 3), ("fan", 3), ("flashlight", 2), ("power", 2)]:
        count = 0
        for item in pools.get(cat, []):
            if item["product_id"] in used:
                continue
            used.add(item["product_id"])
            selected.append(item)
            count += 1
            if count >= n:
                break

    OUT.write_text(json.dumps(selected, ensure_ascii=False, indent=2), encoding="utf-8")
    print("TOTAL", len(selected))


if __name__ == "__main__":
    main()
