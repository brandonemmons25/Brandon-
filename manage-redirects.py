#!/usr/bin/env python3
"""
Usage: python manage-redirects.py <domain>
       python manage-redirects.py <domain> --import <csv_file>

Examples:
  python manage-redirects.py dicksakowicz.com
  python manage-redirects.py dicksakowicz.com --import saved-links.csv
"""

import csv, sys, os, re

REDIRECTS_FILE = os.path.join(os.path.dirname(__file__), "redirects.csv")
FIELDNAMES = ["Source URL", "Destination URL"]

STANDARD_PATHS = [
    ("/idx/search/advanced",          "/homes-for-sale-search/"),
    ("/idx/featured",                 "/homes-for-sale-featured/"),
    ("/idx/market-reports",           "/homes-for-sale-search"),
    ("/idx/mortgage",                 "/mortgage-calculator/"),
    ("/idx/homevaluation",            "/home-valuation/"),
    ("/idx/roster",                   "/agent-list/"),
    ("/idx/search/address",           "/homes-for-sale-search/"),
    ("/idx/search/smart",             "/homes-for-sale-search/"),
    ("/idx/search/basic",             "/homes-for-sale-search/"),
    ("/idx/search/emailupdatesignup", "/homes-for-sale-search/"),
    ("/idx/search/listingid",         "/homes-for-sale-search/"),
    ("/idx/map/mapsearch",            "/homes-for-sale-search/"),
    ("/idx/featuredopenhouse",        "/open-home-search/"),
    ("/idx/featuredvirtualtour",      "/homes-for-sale-search/"),
    ("/idx/soldpending",              "/sold-featured-listing/"),
    ("/idx/supplemental",             "/supplemental-listing/"),
    ("/idx/linkshowcase",             "/homes-for-sale-search/"),
    ("/idx/contact",                  "/contact-us/"),
    ("/idx/userlogin",                "/property-organizer-login/"),
    ("/idx/usersignup",               "/property-organizer-login/?section=signin"),
    ("/idx/searchbycity",             "/homes-for-sale-search/"),
    ("/idx/sitemap",                  "/homes-for-sale-search/"),
]


def bare_domain(url):
    """Strip scheme and trailing slash from a URL or domain string."""
    url = re.sub(r'^https?://', '', url).rstrip('/')
    return url


def load_redirects():
    if not os.path.exists(REDIRECTS_FILE):
        return {}
    with open(REDIRECTS_FILE, newline='', encoding='utf-8') as f:
        return {r['Source URL'].strip(): r['Destination URL'].strip() for r in csv.DictReader(f)}


def save_redirects(mapping):
    with open(REDIRECTS_FILE, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=FIELDNAMES)
        w.writeheader()
        for src, dst in mapping.items():
            w.writerow({'Source URL': src, 'Destination URL': dst})


def add_domain(domain):
    domain = bare_domain(domain)
    mapping = load_redirects()
    added = 0
    for idx_path, ihf_path in STANDARD_PATHS:
        src = f"https://search.{domain}{idx_path}"
        dst = f"https://www.{domain}{ihf_path}"
        if src not in mapping:
            mapping[src] = dst
            added += 1
    save_redirects(mapping)
    print(f"Added {added} redirects for {domain} ({len(mapping)} total in spreadsheet)")


def import_csv(domain, csv_file):
    if not os.path.exists(csv_file):
        print(f"File not found: {csv_file}")
        sys.exit(1)
    domain = bare_domain(domain)
    mapping = load_redirects()
    added = 0
    with open(csv_file, newline='', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        headers = {h.strip().lower(): h for h in (reader.fieldnames or [])}
        src_key = headers.get('source url') or headers.get('source_url') or headers.get('source')
        dst_key = headers.get('destination url') or headers.get('destination_url') or headers.get('destination')
        if not src_key or not dst_key:
            print(f"CSV must have 'Source URL' and 'Destination URL' columns. Found: {list(reader.fieldnames)}")
            sys.exit(1)
        for row in reader:
            src, dst = row[src_key].strip(), row[dst_key].strip()
            if src and dst:
                mapping[src] = dst
                added += 1
    save_redirects(mapping)
    print(f"Imported {added} redirects from {csv_file} ({len(mapping)} total in spreadsheet)")


if __name__ == '__main__':
    args = sys.argv[1:]
    if not args:
        print(__doc__)
        sys.exit(1)

    domain = args[0]
    if '--import' in args:
        idx = args.index('--import')
        if idx + 1 >= len(args):
            print("Provide a CSV file after --import")
            sys.exit(1)
        import_csv(domain, args[idx + 1])
    else:
        add_domain(domain)
