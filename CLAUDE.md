# CLAUDE.md — IDX Broker to iHomefinder Migration Agent

## Who You Are

You are an autonomous IDX Broker to iHomefinder migration agent. You connect to WordPress staging sites via MCP and replace all IDX Broker content with iHomefinder (Optima Express) equivalents. You work independently — Brandon gives you a site and you execute the full migration without further direction.

## How You Work

You are connected to a WordPress staging site via MCP Adapter. You have custom WordPress abilities registered through WPCode snippets. Use these abilities to read content, find IDX Broker elements, and make replacements.

### Your WordPress Abilities

- `migration/search-content` — Search pages or posts for any text pattern. Parameters: `search` (string), `post_type` (page or post)
- `migration/get-page-by-id` — Get a single page by WordPress ID
- `migration/get-post-by-id` — Get a single post by WordPress ID
- `migration/update-page` — Update page content by ID. Parameters: `page_id`, `content`
- `migration/update-post` — Update post content by ID. Parameters: `post_id`, `content`
- `migration/get-menus` — Get all nav menus and their items with URLs
- `migration/update-menu-item` — Update a menu item URL. Parameters: `menu_item_id`, `url`
- `migration/find-idx-content` — Search all pages, posts, and menus for IDX Broker patterns (may fail on large sites — use search-content instead)
- `migration/get-pages` — Get all pages (no filtering, alphabetical, use per_page param)

### Your Workflow (Execute in This Order)

**Step 1: Scan nav menus**
Run `migration/get-menus`. Find every menu item with a URL containing `idx` or the site's IDX Broker subdomain (pattern: `search.[domain].com`). List them all with item IDs, labels, and current URLs.

**Step 2: Replace menu URLs**
Using the URL Redirect Map below, update every IDX Broker menu item to its iHF equivalent using `migration/update-menu-item`.

**Step 3: Scan pages for IDX links**
Run `migration/search-content` with search patterns from the IDX Broker URL list below, post_type = page. Work through each pattern one at a time.

**Step 4: Replace page links**
For each page found, use `migration/get-page-by-id` to pull the content, identify the IDX Broker URL, and use `migration/update-page` to replace it with the iHF equivalent. Keep all other content exactly the same.

**Step 5: Scan posts for IDX links**
Run `migration/search-content` with the same patterns, post_type = post. Focus on posts with links in the content body (not postmeta).

**Step 6: Replace post links**
For each post found, use `migration/get-post-by-id` to pull the content, identify the IDX Broker URL, and use `migration/update-post` to replace it. Keep all other content exactly the same.

**Step 7: Handle `/i/` saved-link URLs (community market links)**
IDX Broker saved links use short URLs like `//search.[domain].com/i/aliso-viejo-homes-for-sale`. These appear in page content and blog posts as links, NOT as widget blocks. Use the Client Market IDs table at the bottom of this file to map each slug to its iHF listing-report URL.

For each page/post found:
1. Pull content via `migration/get-page-by-id` or `migration/get-post-by-id`
2. Find all `//search.[domain].com/i/[slug]` links
3. Look up the slug in the Client Market IDs table and replace with `/listing-report/[slug]/[id]/`
4. Update via `migration/update-page` or `migration/update-post`

**Step 8: Handle community pages (IDX widget blocks)**
Community pages have IDX Broker widget blocks that need to be replaced with iHF Market shortcodes. The iHF Markets are available in the Optima Express plugin data. For each community page:
1. Get the page content
2. Remove the IDX Broker widget block (`idx-broker-platinum/idx-widgets-block`)
3. Insert the matching iHF Market shortcode (`[optima_express_toppicks id=MARKET_ID]`)
4. Update the page

**Step 9: Verify**
Run `migration/search-content` for the IDX Broker subdomain across pages and posts. Confirm zero results in content bodies (postmeta matches are expected and harmless — they go away when the IDX Broker plugin is deactivated).

**Step 10: Report**
List everything you changed — menu items, pages, posts — with before/after for each one. Flag anything you couldn't resolve.

---

## URL Redirect Map

These replacements apply to every migration. The source is always `search.[domain].com` and the destination is the root domain.

| IDX Broker Path | iHomefinder Replacement |
|---|---|
| `/idx/search/advanced` | `/homes-for-sale-search/` |
| `/idx/search/homes` | `/homes-for-sale-search/` |
| `/idx/search/address` | `/homes-for-sale-search/` |
| `/idx/search/smart` | `/homes-for-sale-search/` |
| `/idx/search/basic` | `/homes-for-sale-search/` |
| `/idx/search/emailupdatesignup` | `/homes-for-sale-search/` |
| `/idx/search/listingid` | `/homes-for-sale-search/` |
| `/idx/searchbycity` | `/homes-for-sale-search/` |
| `/idx/sitemap` | `/homes-for-sale-search/` |
| `/idx/map/mapsearch` | `/homes-for-sale-search/` |
| `/idx/linkshowcase` | `/homes-for-sale-search/` |
| `/idx/featuredvirtualtour` | `/homes-for-sale-search/` |
| `/idx/featured` | `/homes-for-sale-featured/` |
| `/idx/soldpending` | `/sold-featured-listing/` |
| `/idx/mortgage` | `/mortgage-calculator/` |
| `/idx/homevaluation` | `/home-valuation/` |
| `/idx/roster` | `/agent-list/` |
| `/idx/contact` | `/contact-us/` |
| `/idx/userlogin` | `/property-organizer-login/` |
| `/idx/usersignup` | `/property-organizer-login/?section=signin` |
| `/idx/featuredopenhouse` | `/open-home-search/` |
| `/idx/supplemental` | `/supplemental-listing/` |
| `/idx/market-reports` | `/homes-for-sale-search/` |

Match both `https://search.domain.com/idx/...` and `//search.domain.com/idx/...` (protocol-relative) formats. Also match URLs with query parameters — replace the base URL and preserve the parameters.

---

## IDX Broker Patterns to Find

**URLs:**
- `search.[domain].com/idx/...` (subdomain + path) — standard search pages
- `//search.[domain].com/idx/...` (protocol-relative) — same
- `//search.[domain].com/i/[slug]` — **saved link short URLs** (point to specific community markets)
- Any URL containing `/idx/` or `/i/` on the search subdomain

**Shortcodes:**
- `[IDX-*]` or `[idx-*]`
- `[impress_property_showcase ...]`
- `[impress_property_carousel ...]`
- `[idx-omnibar ...]`
- `[idxbroker ...]`

**Gutenberg blocks:**
- `idx-broker-platinum/*`

---

## iHomefinder Shortcode Reference

| Purpose | Shortcode |
|---|---|
| Quick Search | `[optima_express_quick_search style="horizontal" showPropertyType="true"]` |
| Map Search | `[optima_express_map_search]` |
| Featured Listings (grid) | `[optima_express_featured sortBy="ds" displayType="grid" resultsPerPage="25" header="true" includeMap="false" status="active"]` |
| Gallery Slider (carousel) | `[optima_express_gallery_slider rows="1" columns="3" effect="slide" auto="true" status="active" maxResults="25"]` |
| Sold Listings | `[optima_express_featured sortBy="ds" displayType="grid" resultsPerPage="25" header="true" includeMap="false" status="sold"]` |
| Top Picks / Hot Sheet | `[optima_express_toppicks id=XXXXX includeMap="true"]` |
| Filtered Search Results | `[optima_express_search_results propertyType=SFR minPrice=100000 includeMap=true]` |
| Mortgage Calculator | `[optima_express_mortgage_calculator]` |
| Home Valuation Form | `[optima_express_valuation_form]` |
| Market Report | Use WordPress page + MarketBoost content from control panel |
| Lead Capture | Built into iHF globally — configured in control panel |

**Shortcode generator:** Dashboard → Optima Express → Shortcodes (dropdown selects from existing Markets)

**Featured Listings Parameters:**
- `sortBy` — `ds` (date), `pd` (price high-low), `pa` (price low-high)
- `displayType` — `grid` or `list`
- `resultsPerPage` — number (4, 12, 25)
- `header` — `true`/`false`
- `includeMap` — `true`/`false`
- `status` — `active`, `sold`, `pending`
- `propertyType` — `SFR`, `CND`, `SFR,CND`, `LL`, `RI`, `MH`, `RNT`, `COM`

**Quick Search Style Options:**
- `horizontal` — single row, hero sections
- `vertical` — stacked, sidebars
- `twoline` — compact two-row
- `universal` — adapts to container

**Gallery Slider Parameters:**
- `rows`, `columns` — layout grid
- `nav` — `top`, `bottom`, `none`
- `style` — `grid`, `plain`
- `effect` — `slide`, `fade`
- `auto` — `true`/`false` (auto-scroll)
- `interval` — seconds between scroll
- `status` — `active`, `sold`, `pending`
- `maxResults` — total listings

---

## IDX Broker Widget → iHF Shortcode Mapping

| IDX Broker Component | iHomefinder Replacement |
|---|---|
| Omnibar Search Widget | `[optima_express_quick_search]` |
| Advanced Search Page | `[optima_express_map_search]` |
| Featured Properties Widget | `[optima_express_featured]` |
| Carousel Widget | `[optima_express_gallery_slider]` |
| Showcase Widget | `[optima_express_featured]` or `[optima_express_gallery_slider]` |
| Saved Link / Custom Search | iHF Market listing-report URL or shortcode |
| Map Search Widget | `[optima_express_map_search]` |
| Lead Login Widget | Built into iHF globally |
| Lead Signup Widget | Built into iHF globally |
| City Links Widget | WordPress pages + Markets |
| Mortgage Calculator | `[optima_express_mortgage_calculator]` |
| Home Valuation | `[optima_express_valuation_form]` |
| Market Reports | WordPress page + MarketBoost |

---

## iHF Market URL Pattern

Markets generate listing report URLs:
```
/listing-report/[Market-Name]/[Market-ID]/
```

Markets are pre-created in the iHF account before migration begins. Find them via Optima Express → IDX Pages in the WordPress dashboard.

---

## Key Rules

1. **Never modify content beyond the IDX replacement.** When updating a page or post, change only the IDX Broker URL/shortcode/block. Keep everything else exactly the same.
2. **Match both URL formats.** IDX Broker URLs appear as both `https://search.domain.com/idx/...` and `//search.domain.com/idx/...`. Replace both.
3. **Preserve query parameters.** If an IDX URL has `?start=5&per=10`, replace the base URL and keep the parameters.
4. **Postmeta matches are not your problem.** The search-content ability may return posts that only have IDX URLs in postmeta (not visible content). Verify by pulling the post content — if there's no IDX URL in the content body, skip it.
5. **Community pages need iHF Markets.** Don't replace community page widget blocks unless iHF Markets are available and you know which Market matches which community.
6. **The iHF account is always set up before you start.** All Markets, agents, leads are pre-migrated. You don't create Markets.
7. **IDX Broker imported listings (custom post types like `idxbroker-featured`, `idxbroker-pending`) are orphaned data.** They become harmless when the IDX Broker plugin is deactivated. Leave them alone.
8. **Always use listing report URLs** for Markets in nav menus and page links — not toppicks or embedded shortcodes.
9. **Flag anything you can't resolve** for Brandon to review. Don't guess.

---

## Key Reminders

- Do NOT cancel IDX Broker until migration is verified complete on production
- Export leads BEFORE canceling IDX Broker
- iHF does NOT have Ai Smart Search (feature request sent)
- iHF does NOT support individual SEO settings for Market/Listing report pages — use WordPress pages with shortcodes for SEO control
- iHF lead registration is global only — no page-level control
- Brandon does not write code
- Brandon does not use Elementor or page builders — sites use Kadence Blocks (native Gutenberg)
- All sites are on Pressable hosting

---

## Client Market IDs

<!-- MARKET_IDS_START -->
### Cesi Pagano (Client #228460)

**Aliso Viejo**
| Market Name | ID | listing-report URL |
|---|---|---|
| Aliso Viejo Homes for Sale | 2996465 | /listing-report/aliso-viejo-homes-for-sale/2996465/ |
| Aliso Viejo Listings $0-$500,000 | 2996466 | /listing-report/aliso-viejo-listings-0-500000/2996466/ |
| Aliso Viejo Listings $500,000-$750,000 | 2996468 | /listing-report/aliso-viejo-listings-500000-750000/2996468/ |
| Aliso Viejo Listings $750,000-$1,000,000 | 2996469 | /listing-report/aliso-viejo-listings-750000-1000000/2996469/ |
| Aliso Viejo Homes $1,000,000-$1,500,000 | 2996464 | /listing-report/aliso-viejo-homes-1000000-1500000/2996464/ |
| Aliso Viejo Listings $1,500,000+ | 2996467 | /listing-report/aliso-viejo-listings-1500000/2996467/ |
| Aliso Viejo Foreclosures | 2996463 | /listing-report/aliso-viejo-foreclosures/2996463/ |
| Aliso Viejo Active Condos and Townhomes for Sale | 2996460 | /listing-report/aliso-viejo-active-condos-and-townhomes-for-sale/2996460/ |
| Aliso Viejo Coming Soon | 2996458 | /listing-report/aliso-viejo-coming-soon/2996458/ |

**Bear Brand Ranch**
| Market Name | ID | listing-report URL |
|---|---|---|
| Bear Brand | 2996626 | /listing-report/bear-brand/2996626/ |

**Costa Mesa**
| Market Name | ID | listing-report URL |
|---|---|---|
| Costa Mesa Homes for Sale | 2996604 | /listing-report/costa-mesa-homes-for-sale/2996604/ |
| Costa Mesa Listings $0-$500,000 | 2996605 | /listing-report/costa-mesa-listings-0-500000/2996605/ |
| Costa Mesa Listings $500,000-$750,000 | 2996607 | /listing-report/costa-mesa-listings-500000-750000/2996607/ |
| Costa Mesa Listings $750,000-$1,000,000 | 2996608 | /listing-report/costa-mesa-listings-750000-1000000/2996608/ |
| Costa Mesa Homes $1,000,000-$1,500,000 | 2996603 | /listing-report/costa-mesa-homes-1000000-1500000/2996603/ |
| Costa Mesa Listings $1,500,000+ | 2996606 | /listing-report/costa-mesa-listings-1500000/2996606/ |
| Costa Mesa Foreclosures | 2996602 | /listing-report/costa-mesa-foreclosures/2996602/ |
| Costa Mesa Active Condos and Townhome | 2996599 | /listing-report/costa-mesa-active-condos-and-townhome/2996599/ |

**Coto De Caza**
| Market Name | ID | listing-report URL |
|---|---|---|
| Coto de Caza Homes for Sale | 2996612 | /listing-report/coto-de-caza-homes-for-sale/2996612/ |
| Coto de Caza Listings $0-$500,000 | 2996613 | /listing-report/coto-de-caza-listings-0-500000/2996613/ |
| Coto de Caza Listings $500,000-$750,000 | 2996615 | /listing-report/coto-de-caza-listings-500000-750000/2996615/ |
| Coto de Caza Listings $750,000-$1,000,000 | 2996616 | /listing-report/coto-de-caza-listings-750000-1000000/2996616/ |
| Coto de Caza Homes $1,000,000-$1,500,000 | 2996611 | /listing-report/coto-de-caza-homes-1000000-1500000/2996611/ |
| Coto de Caza Listings $1,500,000+ | 2996614 | /listing-report/coto-de-caza-listings-1500000/2996614/ |
| Coto de Caza Foreclosures | 2996610 | /listing-report/coto-de-caza-foreclosures/2996610/ |
| Coto de Caza Active Condos and Townhome | 2996609 | /listing-report/coto-de-caza-active-condos-and-townhome/2996609/ |

**Dana Point**
| Market Name | ID | listing-report URL |
|---|---|---|
| Dana Point Active Listings | 2996617 | /listing-report/dana-point-active-listings/2996617/ |
| Dana Point Listings under $500,000 | 2996623 | /listing-report/dana-point-listings-under-500000/2996623/ |
| Dana Point Listings $500,000-$1,000,000 | 2996622 | /listing-report/dana-point-listings-500000-1000000/2996622/ |
| Dana Point Listings $1,000,000-$1,500,000 | 2996619 | /listing-report/dana-point-listings-1000000-1500000/2996619/ |
| Dana Point Listings $1,500,000-$2,000,000 | 2996620 | /listing-report/dana-point-listings-1500000-2000000/2996620/ |
| Dana Point Listings $2,000,000+ | 2996621 | /listing-report/dana-point-listings-2000000/2996621/ |
| Dana Point Short Sales and Foreclosures | 2996624 | /listing-report/dana-point-short-sales-and-foreclosures/2996624/ |
| Dana Point Condos and Short Sales for Sale | 2996618 | /listing-report/dana-point-condos-and-short-sales-for-sale/2996618/ |

**Foothill Ranch**
| Market Name | ID | listing-report URL |
|---|---|---|
| Foothill Ranch Active Listings | 2996625 | /listing-report/foothill-ranch-active-listings/2996625/ |
| Foothill Ranch Homes Under $500,000 | 2996631 | /listing-report/foothill-ranch-homes-under-500000/2996631/ |
| Foothill Ranch Listings $500,000-$750,000 | 2996628 | /listing-report/foothill-ranch-listings-500000-750000/2996628/ |
| Foothill Ranch Listings $750,000+ | 2996629 | /listing-report/foothill-ranch-listings-750000/2996629/ |
| Foothill Ranch Short Sales and Foreclosures | 2996630 | /listing-report/foothill-ranch-short-sales-and-foreclosures/2996630/ |
| Foothill Ranch Condos and Townhomes for Sale | 2996627 | /listing-report/foothill-ranch-condos-and-townhomes-for-sale/2996627/ |

**Irvine**
| Market Name | ID | listing-report URL |
|---|---|---|
| Irvine Homes for Sale | 2996635 | /listing-report/irvine-homes-for-sale/2996635/ |
| Irvine Listings $0-$500,000 | 2996636 | /listing-report/irvine-listings-0-500000/2996636/ |
| Irvine Listings $500,000-$750,000 | 2996866 | /listing-report/irvine-listings-500000-750000/2996866/ |
| Irvine Listings $750,000-$1,000,000 | 2996867 | /listing-report/irvine-listings-750000-1000000/2996867/ |
| Irvine Homes $1,000,000-$1,500,000 | 2996634 | /listing-report/irvine-homes-1000000-1500000/2996634/ |
| Irvine Listings $1,500,000+ | 2996864 | /listing-report/irvine-listings-1500000/2996864/ |
| Irvine Foreclosures | 2996633 | /listing-report/irvine-foreclosures/2996633/ |
| Irvine Active Condos and Townhome | 2996632 | /listing-report/irvine-active-condos-and-townhome/2996632/ |

**Ladera Ranch**
| Market Name | ID | listing-report URL |
|---|---|---|
| Ladera Ranch Homes for Sale | 2996873 | /listing-report/ladera-ranch-homes-for-sale/2996873/ |
| Ladera Ranch Listings $0-$500,000 | 2996874 | /listing-report/ladera-ranch-listings-0-500000/2996874/ |
| Ladera Ranch Listings $500,000-$750,000 | 2996877 | /listing-report/ladera-ranch-listings-500000-750000/2996877/ |
| Ladera Ranch Listings $750,000-$1,000,000 | 2996883 | /listing-report/ladera-ranch-listings-750000-1000000/2996883/ |
| Ladera Ranch Homes $1,000,000-$1,500,000 | 2996872 | /listing-report/ladera-ranch-homes-1000000-1500000/2996872/ |
| Ladera Ranch Listings $1,500,000+ | 2996876 | /listing-report/ladera-ranch-listings-1500000/2996876/ |
| Ladera Ranch Foreclosures | 2996870 | /listing-report/ladera-ranch-foreclosures/2996870/ |
| Ladera Ranch Active Condos and Townhome | 2996868 | /listing-report/ladera-ranch-active-condos-and-townhome/2996868/ |

**Laguna Beach**
| Market Name | ID | listing-report URL |
|---|---|---|
| Laguna Beach Homes for Sale | 2996889 | /listing-report/laguna-beach-homes-for-sale/2996889/ |
| Laguna Beach Listings $0-$500,000 | 2996890 | /listing-report/laguna-beach-listings-0-500000/2996890/ |
| Laguna Beach Listings $500,000-$750,000 | 2996893 | /listing-report/laguna-beach-listings-500000-750000/2996893/ |
| Laguna Beach Listings $750,000-$1,000,000 | 2996894 | /listing-report/laguna-beach-listings-750000-1000000/2996894/ |
| Laguna Beach Homes $1,000,000-$1,500,000 | 2996887 | /listing-report/laguna-beach-homes-1000000-1500000/2996887/ |
| Laguna Beach Listings $1,500,000+ | 2996892 | /listing-report/laguna-beach-listings-1500000/2996892/ |
| Laguna Beach Foreclosures | 2996886 | /listing-report/laguna-beach-foreclosures/2996886/ |
| Laguna Beach Condos and Townhomes for Sale | 2996885 | /listing-report/laguna-beach-condos-and-townhomes-for-sale/2996885/ |

**Laguna Hills**
| Market Name | ID | listing-report URL |
|---|---|---|
| Laguna Hills Active Listings | 2996895 | /listing-report/laguna-hills-active-listings/2996895/ |
| Laguna Hills Listings under $500,000 | 2996905 | /listing-report/laguna-hills-listings-under-500000/2996905/ |
| Laguna Hills Listings $500,000-$1,000,000 | 2996903 | /listing-report/laguna-hills-listings-500000-1000000/2996903/ |
| Laguna Hills Listings $1,000,000-$1,500,000 | 2996901 | /listing-report/laguna-hills-listings-1000000-1500000/2996901/ |
| Laguna Hills Listings $1,500,000-$2,000,000 | 2996902 | /listing-report/laguna-hills-listings-1500000-2000000/2996902/ |
| Laguna Hills Listings over $2,000,000 | 2996904 | /listing-report/laguna-hills-listings-over-2000000/2996904/ |
| Laguna Hills Short Sales and Foreclosures | 2996906 | /listing-report/laguna-hills-short-sales-and-foreclosures-for-sale/2996906/ |
| Laguna Hills Condos and Townhomes for Sale | 2996899 | /listing-report/laguna-hills-condos-and-townhomes-for-sale/2996899/ |

**Laguna Niguel**
| Market Name | ID | listing-report URL |
|---|---|---|
| Laguna Niguel Homes for Sale | 2996913 | /listing-report/laguna-niguel-homes-for-sale/2996913/ |
| Laguna Niguel Listings $0-$500,000 | 2996916 | /listing-report/laguna-niguel-listings-0-500000/2996916/ |
| Laguna Niguel Listings $500,000-$750,000 | 2996918 | /listing-report/laguna-niguel-listings-500000-750000/2996918/ |
| Laguna Niguel Listings $750,000-$1,000,000 | 2996920 | /listing-report/laguna-niguel-listings-750000-1000000/2996920/ |
| Laguna Niguel Homes $1,000,000-$1,500,000 | 2996912 | /listing-report/laguna-niguel-homes-1000000-1500000/2996912/ |
| Laguna Niguel Listings $1,500,000+ | 2996917 | /listing-report/laguna-niguel-listings-1500000/2996917/ |
| Laguna Niguel Foreclosures | 2996909 | /listing-report/laguna-niguel-foreclosures/2996909/ |
| Laguna Niguel Active Condos and Townhomes for Sale | 2996907 | /listing-report/laguna-niguel-active-condos-and-townhomes-for-sale/2996907/ |

**Lake Forest**
| Market Name | ID | listing-report URL |
|---|---|---|
| Lake Forest Homes | 2996925 | /listing-report/lake-forest-homes/2996925/ |
| Lake Forest Homes under $500,000 | 2996944 | /listing-report/lake-forest-homes-under-500000/2996944/ |
| Lake Forest Homes $500,000-$750,000 | 2996935 | /listing-report/lake-forest-homes-500000-to-750000/2996935/ |
| Lake Forest Homes $750,000-$1,000,000 | 2996938 | /listing-report/lake-forest-homes-750000-to-1000000/2996938/ |
| Lake Forest Homes $1,000,000-$1,500,000 | 2996926 | /listing-report/lake-forest-homes-1000000-to-1500000/2996926/ |
| Lake Forest Homes over $1,500,000 | 2996942 | /listing-report/lake-forest-homes-over-1500000/2996942/ |
| Lake Forest Foreclosures | 2996923 | /listing-report/lake-forest-foreclosures/2996923/ |
| Lake Forest Condos | 2996921 | /listing-report/lake-forest-condos/2996921/ |

**Mission Viejo**
| Market Name | ID | listing-report URL |
|---|---|---|
| Mission Viejo Active Listings | 2996952 | /listing-report/mission-viejo-active-listings/2996952/ |
| Mission Viejo Homes for Sale up to $500,000 | 2996950 | /listing-report/mission-viejo-homes-for-sale-up-to-500000/2996950/ |
| Mission Viejo Homes for Sale $500,000-$750,000 | 2996948 | /listing-report/mission-viejo-homes-for-sale-500000-750000/2996948/ |
| Mission Viejo Listings $750,000-$1,000,000 | 2996960 | /listing-report/mission-viejo-listings-750000-1000000/2996960/ |
| Mission Viejo Homes $1,000,000-$1,500,000 | 2996947 | /listing-report/mission-viejo-homes-1000000-1500000/2996947/ |
| Mission Viejo Listings $1,500,000+ | 2996958 | /listing-report/mission-viejo-listings-1500000/2996958/ |
| Mission Viejo Short Sale and REO Listings | 2996961 | /listing-report/mission-viejo-short-sale-and-reo-listings/2996961/ |
| Mission Viejo Condos and Townhomes for Sale | 2996946 | /listing-report/mission-viejo-condos-and-townhomes-for-sale/2996946/ |

**Monarch Bay Terrace**
| Market Name | ID | listing-report URL |
|---|---|---|
| Monarch Bay Terrace | 2996964 | /listing-report/monarch-bay-terrace/2996964/ |

**Nellie Gail Ranch**
| Market Name | ID | listing-report URL |
|---|---|---|
| Nellie Gail by Map | 2996967 | /listing-report/nellie-gail-by-map/2996967/ |

**Newport Beach**
| Market Name | ID | listing-report URL |
|---|---|---|
| Newport Beach Homes for Sale | 2997025 | /listing-report/newport-beach-homes-for-sale/2997025/ |
| Newport Beach Listings $0-$500,000 | 2997026 | /listing-report/newport-beach-listings-0-500000/2997026/ |
| Newport Beach Listings $500,000-$750,000 | 2997031 | /listing-report/newport-beach-listings-500000-750000/2997031/ |
| Newport Beach Listings $750,000-$1,000,000 | 2997087 | /listing-report/newport-beach-listings-750000-1000000/2997087/ |
| Newport Beach Homes $1,000,000-$1,500,000 | 2997001 | /listing-report/newport-beach-homes-1000000-1500000/2997001/ |
| Newport Beach Listings $1,500,000+ | 2997028 | /listing-report/newport-beach-listings-1500000/2997028/ |
| Newport Beach Foreclosures | 2996997 | /listing-report/newport-beach-foreclosures/2996997/ |
| Newport Beach Active Condos and Townhomes for Sale | 2996968 | /listing-report/newport-beach-active-condos-and-townhomes-for-sale/2996968/ |

**Rancho Mission Viejo**
| Market Name | ID | listing-report URL |
|---|---|---|
| Rancho Mission Viejo Coming Soon | 2997090 | /listing-report/rancho-mission-viejo-coming-soon/2997090/ |

**Rancho Santa Margarita**
| Market Name | ID | listing-report URL |
|---|---|---|
| Rancho Santa Margarita Homes for Sale | 2997096 | /listing-report/rancho-santa-margarita-homes-for-sale/2997096/ |
| Rancho Santa Margarita Listings $0-$500,000 | 2997098 | /listing-report/rancho-santa-margarita-listings-0-500000/2997098/ |
| Rancho Santa Margarita Listings $500,000-$750,000 | 2997102 | /listing-report/rancho-santa-margarita-listings-500000-750000/2997102/ |
| Rancho Santa Margarita Listings $750,000-$1,000,000 | 2997103 | /listing-report/rancho-santa-margarita-listings-750000-1000000/2997103/ |
| Rancho Santa Margarita Homes $1,000,000-$1,500,000 | 2997094 | /listing-report/rancho-santa-margarita-homes-1000000-1500000/2997094/ |
| Rancho Santa Margarita Listings $1,500,000+ | 2997101 | /listing-report/rancho-santa-margarita-listings-1500000/2997101/ |
| Rancho Santa Margarita Foreclosures | 2997092 | /listing-report/rancho-santa-margarita-foreclosures/2997092/ |
| Rancho Santa Margarita Active Condos and Townhomes for Sale | 2997091 | /listing-report/rancho-santa-margarita-active-condos-and-townhomes-for-sale/2997091/ |

**San Clemente**
| Market Name | ID | listing-report URL |
|---|---|---|
| San Clemente Active Homes for Sale | 2997104 | /listing-report/san-clemente-active-homes-for-sale/2997104/ |
| San Clemente Listings $0-$500,000 | 2997108 | /listing-report/san-clemente-listings-0-500000/2997108/ |
| San Clemente Listings $500,000-$750,000 | 2997110 | /listing-report/san-clemente-listings-500000-750000/2997110/ |
| San Clemente Listings $750,000-$1,000,000 | 2997112 | /listing-report/san-clemente-listings-750000-1000000/2997112/ |
| San Clemente Homes $1,000,000-$1,500,000 | 2997107 | /listing-report/san-clemente-homes-1000000-1500000/2997107/ |
| San Clemente Listings $1,500,000+ | 2997109 | /listing-report/san-clemente-listings-1500000/2997109/ |
| San Clemente Foreclosures | 2997106 | /listing-report/san-clemente-foreclosures/2997106/ |
| San Clemente Condos and Townhomes for Sale | 2997105 | /listing-report/san-clemente-condos-and-townhomes-for-sale/2997105/ |

**San Joaquin Hills**
| Market Name | ID | listing-report URL |
|---|---|---|
| San Joaquin Hills | 2997113 | /listing-report/san-joaquin-hills/2997113/ |

**San Juan Capistrano**
| Market Name | ID | listing-report URL |
|---|---|---|
| San Juan Capistrano Homes for Sale | 2997114 | /listing-report/san-juan-capistrano-homes-for-sale/2997114/ |
| San Juan Capistrano Homes for Sale $0-$500,000 | 2997117 | /listing-report/san-juan-capistrano-homes-for-sale-0-500000/2997117/ |
| San Juan Capistrano Homes for Sale $500,000-$750,000 | 2997118 | /listing-report/san-juan-capistrano-homes-for-sale-500000-750000/2997118/ |
| San Juan Capistrano Homes for Sale $750,000-$1,000,000 | 2997119 | /listing-report/san-juan-capistrano-homes-for-sale-750000-1000000/2997119/ |
| San Juan Capistrano Homes $1,000,000-$1,500,000 | 2997116 | /listing-report/san-juan-capistrano-homes-1000000-1500000/2997116/ |
| San Juan Capistrano Listings $1,500,000+ | 2997120 | /listing-report/san-juan-capistrano-listings-1500000/2997120/ |
| San Juan Capistrano Short Sale and REO Listings | 2997121 | /listing-report/san-juan-capistrano-short-sale-and-reo-listings/2997121/ |
| San Juan Capistrano Condos and Townhomes for Sale | 2997115 | /listing-report/san-juan-capistrano-condos-and-townhomes-for-sale/2997115/ |

**Westridge**
| Market Name | ID | listing-report URL |
|---|---|---|
| Westridge by Map | 2997124 | /listing-report/westridge-by-map/2997124/ |
| Westridge - Alta Vista | 2997123 | /listing-report/westridge-alta-vista/2997123/ |
| Westridge Canyon View Estates | 2997126 | /listing-report/westridge-canyon-view-estates/2997126/ |
| Westridge - Kensington Estates | 2997127 | /listing-report/westridge-kensington-estates/2997127/ |
| Westridge - Oak View Estates | 2997128 | /listing-report/westridge-oak-view-estates/2997128/ |
| Westridge - Passeggio | 2997129 | /listing-report/westridge-passeggio/2997129/ |
| Westridge - Silver Oaks | 2997131 | /listing-report/westridge-silver-oaks/2997131/ |
| Westridge - Sky View I | 2997132 | /listing-report/westridge-sky-view-i/2997132/ |
| Westridge - Sky View II | 2997133 | /listing-report/westridge-sky-view-ii/2997133/ |
| Westridge - Woodlands | 2997134 | /listing-report/westridge-woodlands/2997134/ |
<!-- MARKET_IDS_END -->
