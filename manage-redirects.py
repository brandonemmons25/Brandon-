#!/usr/bin/env python3
"""
Redirect Spreadsheet Manager
Manages IDX Broker → iHomefinder redirect mappings in a CSV spreadsheet.

Usage:
  python manage-redirects.py add <source_url> <destination_url>
  python manage-redirects.py generate <idx_subdomain> <ihomefinder_domain>
  python manage-redirects.py import <csv_file>
  python manage-redirects.py list
  python manage-redirects.py remove <source_url>

Examples:
  python manage-redirects.py add "https://search.example.com/idx/contact" "https://www.example.com/contact-us/"
  python manage-redirects.py generate search.example.com www.example.com
  python manage-redirects.py import custom-markets.csv
  python manage-redirects.py list
"""

import csv
import sys
import os

REDIRECTS_FILE = os.path.join(os.path.dirname(__file__), "redirects.csv")
FIELDNAMES = ["Source URL", "Destination URL"]

# Standard IDX Broker path → iHomefinder destination path mappings
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


def load_redirects():
    """Load existing redirects from CSV, returning a list of dicts."""
    if not os.path.exists(REDIRECTS_FILE):
        return []
    with open(REDIRECTS_FILE, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def save_redirects(rows):
    """Save redirect rows to CSV, deduplicating by Source URL (last wins)."""
    seen = {}
    for row in rows:
        seen[row["Source URL"].strip()] = row["Destination URL"].strip()
    with open(REDIRECTS_FILE, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=FIELDNAMES)
        writer.writeheader()
        for src, dst in seen.items():
            writer.writerow({"Source URL": src, "Destination URL": dst})
    return len(seen)


def cmd_add(source, destination):
    """Add or update a single redirect."""
    rows = load_redirects()
    rows.append({"Source URL": source.strip(), "Destination URL": destination.strip()})
    total = save_redirects(rows)
    print(f"Added: {source} → {destination}")
    print(f"Total redirects: {total}")


def cmd_generate(idx_subdomain, ihf_domain):
    """
    Generate all standard redirects for a given IDX Broker subdomain
    and iHomefinder domain, then add them to the spreadsheet.

    idx_subdomain  - e.g. search.example.com  (no https://)
    ihf_domain     - e.g. www.example.com     (no https://)
    """
    for scheme in ("https://", "http://"):
        if idx_subdomain.startswith(scheme):
            idx_subdomain = idx_subdomain[len(scheme):]
        if ihf_domain.startswith(scheme):
            ihf_domain = ihf_domain[len(scheme):]
    idx_subdomain = idx_subdomain.rstrip("/")
    ihf_domain    = ihf_domain.rstrip("/")

    new_rows = []
    for idx_path, ihf_path in STANDARD_PATHS:
        new_rows.append({
            "Source URL":      f"https://{idx_subdomain}{idx_path}",
            "Destination URL": f"https://{ihf_domain}{ihf_path}",
        })

    rows = load_redirects()
    rows.extend(new_rows)
    total = save_redirects(rows)
    print(f"Generated {len(new_rows)} standard redirects for {idx_subdomain} → {ihf_domain}")
    print(f"Total redirects: {total}")


def cmd_import(csv_file):
    """
    Import redirects from an external CSV file.
    The file must have 'Source URL' and 'Destination URL' columns
    (case-insensitive header matching is supported).
    """
    if not os.path.exists(csv_file):
        print(f"Error: file not found: {csv_file}", file=sys.stderr)
        sys.exit(1)

    imported = []
    with open(csv_file, newline="", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        # Normalise header names
        headers = {h.strip().lower(): h for h in reader.fieldnames or []}
        src_key = headers.get("source url") or headers.get("source_url") or headers.get("source")
        dst_key = headers.get("destination url") or headers.get("destination_url") or headers.get("destination")
        if not src_key or not dst_key:
            print(
                f"Error: CSV must have 'Source URL' and 'Destination URL' columns.\n"
                f"Found columns: {list(reader.fieldnames)}",
                file=sys.stderr,
            )
            sys.exit(1)
        for row in reader:
            src = row[src_key].strip()
            dst = row[dst_key].strip()
            if src and dst:
                imported.append({"Source URL": src, "Destination URL": dst})

    rows = load_redirects()
    rows.extend(imported)
    total = save_redirects(rows)
    print(f"Imported {len(imported)} redirects from {csv_file}")
    print(f"Total redirects: {total}")


def cmd_list():
    """Print all current redirects."""
    rows = load_redirects()
    if not rows:
        print("No redirects found.")
        return
    max_src = max(len(r["Source URL"]) for r in rows)
    max_dst = max(len(r["Destination URL"]) for r in rows)
    print(f"{'Source URL':<{max_src}}  {'Destination URL':<{max_dst}}")
    print("-" * (max_src + max_dst + 2))
    for row in rows:
        print(f"{row['Source URL']:<{max_src}}  {row['Destination URL']}")
    print(f"\nTotal: {len(rows)} redirects")


def cmd_remove(source):
    """Remove a redirect by its source URL."""
    rows = load_redirects()
    before = len(rows)
    rows = [r for r in rows if r["Source URL"].strip() != source.strip()]
    save_redirects(rows)
    removed = before - len(rows)
    if removed:
        print(f"Removed {removed} redirect(s) matching: {source}")
    else:
        print(f"No redirect found for: {source}")


def usage():
    print(__doc__)
    sys.exit(1)


def main():
    args = sys.argv[1:]
    if not args:
        usage()

    command = args[0].lower()

    if command == "add":
        if len(args) != 3:
            print("Usage: manage-redirects.py add <source_url> <destination_url>")
            sys.exit(1)
        cmd_add(args[1], args[2])

    elif command == "generate":
        if len(args) != 3:
            print("Usage: manage-redirects.py generate <idx_subdomain> <ihomefinder_domain>")
            sys.exit(1)
        cmd_generate(args[1], args[2])

    elif command == "import":
        if len(args) != 2:
            print("Usage: manage-redirects.py import <csv_file>")
            sys.exit(1)
        cmd_import(args[1])

    elif command == "list":
        cmd_list()

    elif command == "remove":
        if len(args) != 2:
            print("Usage: manage-redirects.py remove <source_url>")
            sys.exit(1)
        cmd_remove(args[1])

    else:
        print(f"Unknown command: {command}")
        usage()


if __name__ == "__main__":
    main()
