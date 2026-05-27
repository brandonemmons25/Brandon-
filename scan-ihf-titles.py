#!/usr/bin/env python3
"""
scan-ihf-titles.py
Checks iHomefinder SEO titles across multiple client WordPress sites.

Logic:
  PASS  - <title> contains the expected keyword for that slug
  FAIL  - <title> is missing, empty, contains a raw URL, or is the
          wrong title (e.g. another page's keyword appears instead)
  SKIP  - HTTP status != 200 or connection error
"""

import urllib.request
import urllib.error
import html.parser
import re
import sys

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

SITES = [
    "www.cesipagano.com",
    "www.cbintermountainrealty.com",
    "www.sanbornteam.com",
    "www.dicksakowicz.com",
    "www.theallmanteam.com",
    "www.collegestationhomes.com",
]

# slug -> expected keyword (case-insensitive match against <title>)
PAGES = {
    "/homes-for-sale-search/":   "Property Search",
    "/homes-for-sale-featured/": "Featured Properties",
    "/open-home-search/":        "Open Houses",
    "/sold-featured-listing/":   "Sold Properties",
    "/mortgage-calculator/":     "Mortgage Calculator",
    "/valuation-form/":          "Home Valuation",
    "/agent-list/":              "Agent",
}

TIMEOUT = 2  # seconds per request

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/124.0.0.0 Safari/537.36"
)

# ---------------------------------------------------------------------------
# HTML title extractor
# ---------------------------------------------------------------------------

class TitleParser(html.parser.HTMLParser):
    def __init__(self):
        super().__init__()
        self._in_title = False
        self._title = []

    def handle_starttag(self, tag, attrs):
        if tag.lower() == "title":
            self._in_title = True

    def handle_endtag(self, tag):
        if tag.lower() == "title":
            self._in_title = False

    def handle_data(self, data):
        if self._in_title:
            self._title.append(data)

    @property
    def title(self):
        return "".join(self._title).strip()


def fetch_title(url):
    """
    Fetch *url* and return (status_code, title_text).
    status_code is None on connection error.
    title_text is None if not found.
    """
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            status = resp.status
            # Only parse body on 200
            if status != 200:
                return status, None
            charset = "utf-8"
            content_type = resp.headers.get_content_charset()
            if content_type:
                charset = content_type
            body = resp.read(65536).decode(charset, errors="replace")
    except urllib.error.HTTPError as exc:
        return exc.code, None
    except Exception:
        return None, None

    parser = TitleParser()
    parser.feed(body)
    title = parser.title or None
    return status, title


# ---------------------------------------------------------------------------
# Evaluation
# ---------------------------------------------------------------------------

def evaluate(title, expected_keyword, all_keywords):
    """
    Return ("PASS", reason) | ("FAIL", reason) given the extracted title.

    Parameters
    ----------
    title            : str or None  – extracted <title> text
    expected_keyword : str          – what we expect to find in the title
    all_keywords     : list[str]    – all possible IHF keywords (to detect
                                      "wrong" titles)
    """
    if not title:
        return "FAIL", "no <title> tag"

    # Title contains a raw URL fragment → broken
    if re.search(r"https?://", title, re.IGNORECASE):
        return "FAIL", "title contains URL"

    lower_title = title.lower()
    lower_expected = expected_keyword.lower()

    if lower_expected in lower_title:
        return "PASS", ""

    # Check if a *different* IHF keyword is showing up (repeated wrong title)
    for kw in all_keywords:
        if kw.lower() != lower_expected and kw.lower() in lower_title:
            return "FAIL", f"shows '{kw}' instead of '{expected_keyword}'"

    return "FAIL", f"expected '{expected_keyword}' not in title"


# ---------------------------------------------------------------------------
# Formatting helpers
# ---------------------------------------------------------------------------

COL_SITE  = 30
COL_SLUG  = 28
COL_TITLE = 55
COL_STAT  = 6

DIVIDER = "-" * (COL_SITE + COL_SLUG + COL_TITLE + COL_STAT + 7)

def header_row():
    return (
        f"{'SITE':<{COL_SITE}} "
        f"{'PAGE':<{COL_SLUG}} "
        f"{'TITLE':<{COL_TITLE}} "
        f"{'STATUS':<{COL_STAT}}"
    )

def data_row(site, slug, title, status):
    title_display = (title or "(none)")
    if len(title_display) > COL_TITLE:
        title_display = title_display[: COL_TITLE - 1] + "…"
    return (
        f"{site:<{COL_SITE}} "
        f"{slug:<{COL_SLUG}} "
        f"{title_display:<{COL_TITLE}} "
        f"{status:<{COL_STAT}}"
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    all_keywords = list(PAGES.values())

    # site -> {pass: n, fail: n, skip: n}
    summary = {site: {"PASS": 0, "FAIL": 0, "SKIP": 0} for site in SITES}

    print(header_row())
    print(DIVIDER)

    for site in SITES:
        for slug, expected_kw in PAGES.items():
            url = f"https://{site}{slug}"
            status_code, title = fetch_title(url)

            if status_code is None:
                row_status = "SKIP"
                title_display = "(connection error)"
            elif status_code != 200:
                row_status = "SKIP"
                title_display = f"(HTTP {status_code})"
            else:
                row_status, reason = evaluate(title, expected_kw, all_keywords)
                title_display = title
                if reason:
                    # append short reason to title column
                    note = f"  [{reason}]"
                    max_t = COL_TITLE - len(note)
                    t = (title or "(none)")
                    if len(t) > max_t:
                        t = t[: max_t - 1] + "…"
                    title_display = t + note

            summary[site][row_status] += 1
            print(data_row(site, slug, title_display, row_status))

        print(DIVIDER)

    # Per-site summary table
    print()
    print(f"{'SITE':<{COL_SITE}}  {'PASS':>5}  {'FAIL':>5}  {'SKIP':>5}")
    print("-" * (COL_SITE + 22))
    total_pass = total_fail = total_skip = 0
    for site in SITES:
        p = summary[site]["PASS"]
        f = summary[site]["FAIL"]
        s = summary[site]["SKIP"]
        total_pass += p
        total_fail += f
        total_skip += s
        print(f"{site:<{COL_SITE}}  {p:>5}  {f:>5}  {s:>5}")
    print("-" * (COL_SITE + 22))
    print(f"{'TOTAL':<{COL_SITE}}  {total_pass:>5}  {total_fail:>5}  {total_skip:>5}")


if __name__ == "__main__":
    main()
