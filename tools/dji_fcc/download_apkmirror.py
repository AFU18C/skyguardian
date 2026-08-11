#!/usr/bin/env python3
import hashlib
import re
import sys
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup

PAGE = "https://www.apkmirror.com/apk/dji-technology-co-ltd/dji-fly/dji-fly-1-21-8-release/dji-fly-1-21-8-android-apk-download/"
EXPECTED = "c602f4ffaef95e11b40314a6281ad2ce4adc1b22b2d318022ab1655edc021eb7"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36"


def get(session, url, stream=False):
    r = session.get(url, headers={"User-Agent": UA, "Referer": PAGE}, timeout=60, stream=stream, allow_redirects=True)
    r.raise_for_status()
    return r


def resolve(session):
    r = get(session, PAGE)
    soup = BeautifulSoup(r.text, "html.parser")
    candidates = []
    for a in soup.find_all("a", href=True):
        href = a["href"]
        text = " ".join(a.stripped_strings).lower()
        if ("download apk" in text or "downloadbutton" in " ".join(a.get("class", []))) and "/download/" in href:
            candidates.append(urljoin(r.url, href))
    if not candidates:
        m = re.search(r'href=["\']([^"\']+/download/\?key=[^"\']+)', r.text)
        if m:
            candidates.append(urljoin(r.url, m.group(1)))
    if not candidates:
        raise RuntimeError("APKMirror intermediate download link not found")

    r2 = get(session, candidates[0])
    soup2 = BeautifulSoup(r2.text, "html.parser")
    final_candidates = []
    for a in soup2.find_all("a", href=True):
        href = a["href"]
        if a.get("id") == "download-link" or "download.php" in href or "download.php" in str(a):
            final_candidates.append(urljoin(r2.url, href))
    if not final_candidates:
        # Some APKMirror responses redirect straight to the binary.
        ctype = r2.headers.get("content-type", "")
        if "application" in ctype and "html" not in ctype:
            return r2.url
        raise RuntimeError("APKMirror final binary link not found")
    return final_candidates[0]


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else "dji-fly.apk"
    s = requests.Session()
    url = resolve(s)
    print("Resolved download URL host/path:", re.sub(r'([?&](?:key|token)=[^&]+)', r'\\1<redacted>', url))
    h = hashlib.sha256()
    total = 0
    with get(s, url, stream=True) as r, open(out, "wb") as f:
        for chunk in r.iter_content(4 * 1024 * 1024):
            if not chunk:
                continue
            f.write(chunk)
            h.update(chunk)
            total += len(chunk)
            if total % (64 * 1024 * 1024) < len(chunk):
                print(f"Downloaded {total / 1024 / 1024:.0f} MiB")
    digest = h.hexdigest()
    print("bytes:", total)
    print("sha256:", digest)
    if digest != EXPECTED:
        raise SystemExit(f"Unexpected SHA256: {digest}")


if __name__ == "__main__":
    main()
