#!/usr/bin/env python3
"""
Export IDX Broker saved searches (all /i/ URLs) to a CSV.
Usage: python3 export-saved-searches.py
"""

import csv
import json
import sys
import urllib.request

API_KEY = "YOUR_API_KEY_HERE"  # <-- paste your IDX Broker API key

def fetch(endpoint):
    url = f"https://api.idxbroker.com/{endpoint}"
    req = urllib.request.Request(url, headers={
        "accesskey": API_KEY,
        "Content-Type": "application/x-www-form-urlencoded",
    })
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())

def main():
    if API_KEY == "YOUR_API_KEY_HERE":
        print("Error: set your API key in the script first.")
        sys.exit(1)

    print("Fetching saved links from IDX Broker...")
    data = fetch("clients/savedlinks")

    rows = []
    for link_id, info in data.items():
        rows.append({
            "id":          link_id,
            "name":        info.get("linkName", ""),
            "url_slug":    info.get("linkURL", ""),
            "full_url":    f"/i/{info.get('linkURL', '')}",
            "category":    info.get("category", ""),
            "created":     info.get("created", ""),
            "last_saved":  info.get("lastSaved", ""),
        })

    rows.sort(key=lambda r: r["name"].lower())

    out_file = "saved-searches.csv"
    with open(out_file, "w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=rows[0].keys())
        writer.writeheader()
        writer.writerows(rows)

    print(f"Done — {len(rows)} saved searches written to {out_file}")

if __name__ == "__main__":
    main()
