# IDX Broker → iHomefinder Redirect Management

## Overview
One CSV per client domain. Format: `source,target,code,match` with `301,url` on every row.
Import via **WordPress Redirection plugin**.

---

## Standard 22 General Redirects (applied to every domain)

| IDX Broker Source Path | iHomefinder Destination |
|---|---|
| `/idx/search/advanced` | `/homes-for-sale-search/` |
| `/idx/featured` | `/homes-for-sale-featured/` |
| `/idx/market-reports` | `/homes-for-sale-search/` |
| `/idx/mortgage` | `/mortgage-calculator/` |
| `/idx/homevaluation` | `/valuation-form/` |
| `/idx/roster` | `/agent-list/` |
| `/idx/search/address` | `/homes-for-sale-search/` |
| `/idx/search/smart` | `/homes-for-sale-search/` |
| `/idx/search/basic` | `/homes-for-sale-search/` |
| `/idx/search/emailupdatesignup` | `/homes-for-sale-search/` |
| `/idx/search/listingid` | `/homes-for-sale-search/` |
| `/idx/map/mapsearch` | `/homes-for-sale-search/` |
| `/idx/featuredopenhouse` | `/open-home-search/` |
| `/idx/featuredvirtualtour` | `/homes-for-sale-search/` |
| `/idx/soldpending` | `/sold-featured-listing/` |
| `/idx/supplemental` | `/supplemental-listing/` |
| `/idx/linkshowcase` | `/homes-for-sale-search/` |
| `/idx/contact` | `/contact-us/` |
| `/idx/userlogin` | `/property-organizer-login/` |
| `/idx/usersignup` | `/property-organizer-login/?section=signin` |
| `/idx/searchbycity` | `/homes-for-sale-search/` |
| `/idx/sitemap` | `/homes-for-sale-search/` |

---

## Saved Search URL Pattern
- **Source:** `https://search.{domain}.com/i/{saved-search-slug}`
- **Destination:** `https://www.{domain}.com/listing-report/{Market-Name}/{iHF-market-id}`

---

## Client Files

### 1. cesipagano.com
- **File:** `cesipagano.com-redirects.csv`
- **Total redirects:** 193 (22 standard + 171 saved searches)
- **Status:** Complete
- **DNS:** Cloudflare — `search.cesipagano.com` subdomain redirect requested to point to WordPress server IP (pending propagation)
- **Notes:**
  - `cesi-pagano-featured-sidebar-listings` → currently set to `/homes-for-sale-search/` (waiting on iHF confirmation)
  - `san-juan-capistrano-active-listings` → set to `/listing-report/San-Juan-Capistrano-Homes-for-Sale/2997114`

---

### 2. obxlistings.com
- **File:** `obxlistings.com-redirects.csv`
- **Total redirects:** 801 (22 standard + 779 saved searches)
- **Status:** 22 general redirects complete. **779 saved search destinations are blank — waiting for iHomefinder markets to be created.**
- **Next step:** Once iHF provides market names and IDs, fill in destinations.

---

### 3. mingtreerealty.com
- **File:** `mingtreerealty.com-redirects.csv`
- **Total redirects:** 24 (22 standard + 2 saved searches)
- **Status:** Complete
- **Saved searches:**
  - `https://search.mingtreerealty.com/i/active-listings` → `https://www.mingtreerealty.com/homes-for-sale-featured/`
  - `https://search.mingtreerealty.com/i/humboldt-real-estate` → `https://www.mingtreerealty.com/homes-for-sale-featured/`

---

## Rules / Notes
- **Never combine domains into one CSV** — always one file per domain
- **Never leave destination URLs blank** — always ask before leaving empty
- **Match type:** Always `url` (URL only) in the `match` column
- **Redirect code:** Always `301`
- Subdomains follow pattern: `search.{domain}.com`
- WordPress pages follow pattern: `www.{domain}.com/{page-slug}/`
- iHF market pages: `www.{domain}.com/listing-report/{Market-Name}/{id}`

---

## Pending Tasks
- [ ] obxlistings.com — fill in 779 saved search destinations once iHF markets are created
- [ ] cesipagano.com — confirm final destination for `cesi-pagano-featured-sidebar-listings` with iHF
- [ ] cesipagano.com — verify redirects are firing after Cloudflare DNS propagation

---

All CSV files are saved on branch: `claude/update-redirect-spreadsheet-TCtoW` in repo `brandonemmons25/Brandon-`
