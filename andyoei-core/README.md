# Andy Oei Core

Custom post type, taxonomies, fields and directory filters for the
**Condominium Building Directory (02A)** and **Neighborhood Directory (03A)**
on andyoei.com. General content, page design and IDX are handled outside this
plugin.

## Install

1. Zip the `andyoei-core` folder and upload it under **Plugins → Add New → Upload**.
2. Activate. It registers everything and seeds the launch vocabularies.
3. After an import or bulk edit, run **Condominium Buildings → Rebuild Index**.

**No other plugin is required.** Both directories are complete on their own:
the fields they depend on — Street Address, Featured, Featured Rank and the
neighborhood Card Image — are built in.

ACF Pro is optional, and only adds the *individual page* content (hero,
amenities, gallery, FAQs, and the neighborhood editorial modules). Install it
when you build those templates.

## Structure

**Post type:** Condominium Buildings

**Taxonomies:** Neighborhood · Height · Construction · Features

Neighborhoods are a **taxonomy, not a post type**. One record drives the
building filter, the directory card and the individual neighborhood page, so
the 16 neighborhoods are never maintained twice. Page content lives in ACF
fields on the term; build the page as a taxonomy archive template.

Seeded on activation: 16 neighborhoods, Low/Mid/High-Rise, New and Newer
Construction, and the 8 features (Doorman / Concierge · Fitness Center ·
Parking · Elevator · Pets Allowed · Swimming Pool · Outdoor Space · Storage).

## Shortcodes

| Shortcode | Purpose |
| --- | --- |
| `[ao_buildings]` | 02A — controls, featured strip, filters, A–Z headers, Load More |
| `[ao_neighborhoods]` | 03A — controls, featured strip, A–Z headers, all 16 |
| `[ao_featured_buildings]` | The curated strip on its own |
| `[ao_featured_neighborhoods]` | The curated strip on its own |

Both directories include their featured strip by default. Turn it off with
`featured="0"`, or retitle it with `featured_title="…"` and size it with
`featured_limit="6"`.

## Filter behaviour (02A)

- **Neighborhood / Height / Construction** → OR within each group
- **Features** → AND, so a building must carry every selected feature
- **Across groups** → AND
- Live count, removable chips, Clear All
- FILTERS opens a drawer; controls stay visually subordinate
- ~24 cards, then Load More; search and filters cover the entire directory

**Search:** building name + address.
**Sorts:** A–Z (default) · Z–A · Neighborhood A–Z. A leading "The" is ignored,
so The Ritz-Carlton sorts and groups under **R**.

**Featured strip** hides whenever search, a filter, or a non-default sort is
active, and returns on reset — on both directories.

The first page renders server-side, so the directory works without JavaScript
and stays indexable; JavaScript takes over for filtering and Load More via
`/wp-json/andyoei/v1/directory/buildings`. Filter state is written to the URL,
so a filtered view is shareable.

## Neighborhood directory (03A)

Sixteen terms at launch, so the whole set renders at once and search and sort
run in the browser — no request per keystroke. Alphabetical headers follow the
selected sort and disappear when a group empties out. No filters, per the spec.

## Editing

**Building** (Condominium Buildings → Add New)
- Title, featured image, and the Neighborhood / Height / Construction /
  Features boxes in the sidebar
- **Building Details** box — Street Address, searched with the building name
- **Featured** box — Featured toggle + Featured Rank

**Neighborhood** (Condominium Buildings → Neighborhoods → edit a term)
- **Card Image** — shown on the directory card
- **Featured** + **Featured Rank**

Rank 1 shows first; nothing is automatic. A record marked featured without a
rank still appears, after the ranked ones. The Featured column on both list
screens shows the curated set at a glance.

## Where IDX plugs in

Two fields, for an embed or shortcode:

- **Building → Availability** — `IDX Availability Embed`
- **Neighborhood term → Related** — `IDX Listings Embed`

The plugin never queries the MLS.

## Templates and styling

Copy any file from `templates/` into your theme at `andyoei/<file>` to override
it. `assets/css/andyoei.css` is structural; tune it with tokens rather than
rule overrides:

```css
.ao-directory {
	--ao-columns: 3;            /* cards across desktop */
	--ao-featured-visible: 4;   /* cards in the strip */
	--ao-media-ratio: 4 / 3;
	--ao-accent: #c8944e;       /* A–Z group headers */
	--ao-radius: 10px;
	--ao-font-display: "Your Serif", Georgia, serif;
}
```
