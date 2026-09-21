# Andy Oei Core

Custom post types, taxonomies, fields and directory filters for andyoei.com.
General page content, design and IDX are handled outside this plugin.

## Install

1. Zip the `andyoei-core` folder and upload it under **Plugins → Add New → Upload**.
2. Activate. On activation it registers everything and seeds the launch
   vocabularies (16 neighborhoods, 8 features, 3 press categories, 5 insight
   categories).
3. Install **ACF Pro** for the editing panels. The post types, taxonomies and
   filters work without it; only the custom fields need it.
4. After any import or bulk edit, run **Tools → Andy Oei Index** to rebuild the
   sort and search index.

## What it creates

**Post types:** Condominium Buildings · Sold Properties · Testimonials ·
Press & Media · Seller Case Studies · Market Insights · Proof Points

**Taxonomies:** Neighborhood (shared) · Height · Construction · Features ·
Press Category · Insight Category · Topic · Client Type · Proof Context

Neighborhoods are a **taxonomy, not a post type**. One record drives the
building filter, the directory card and the individual neighborhood page, so
the 16 neighborhoods are never maintained twice. The neighborhood page content
lives in ACF fields on the term; build the page itself as a taxonomy archive
template in your builder.

## Shortcodes

| Shortcode | Purpose |
| --- | --- |
| `[ao_directory key="buildings"]` | Condominium Building Directory — search, filters, sorts, A–Z headers, Load More |
| `[ao_directory key="sold"]` | Sold Properties, high price → low |
| `[ao_directory key="press"]` | Press & Media archive |
| `[ao_directory key="insights"]` | Market Insights archive |
| `[ao_directory key="testimonials"]` | Testimonial collection |
| `[ao_directory key="case_studies"]` | Seller Case Studies |
| `[ao_neighborhoods]` | Neighborhood Directory |
| `[ao_featured type="ao_building" limit="6" hide_when_filtered="buildings"]` | Curated featured strip |
| `[ao_proof context="seller" limit="4"]` | Proof points, written once and reused |
| `[ao_carousel type="ao_sold" eyebrow="…" title="Recent Transactions" link="/sold-properties/" link_text="View All Transactions"]` | Editorial section header + swipeable card row |
| `[ao_section type="ao_sold" title="Selected Transactions"]` | Same header and cards as a static grid |

`hide_when_filtered` takes a directory key. The strip hides itself as soon as
that directory is searched, filtered or re-sorted, and returns on reset.

## Filter behaviour

Set in `includes/class-directories.php`, one line per filter:

- Neighborhood, Height, Construction → **OR** within the group
- Features → **AND** (a building must have every selected feature)
- Between groups → **AND**
- Search covers building name, address and neighborhood
- Sorts: A–Z, Z–A, Neighborhood A–Z; leading "The" is ignored, so The
  Ritz-Carlton sorts and groups under R
- Result count, removable chips and Clear All are live
- Filter state is written to the URL, so a filtered view is shareable

The first page renders server-side, so directories work without JavaScript and
stay indexable. JavaScript takes over for filtering and Load More via
`/wp-json/andyoei/v1/directory/{key}`.

## Where IDX plugs in

The plugin never queries the MLS. It gives you the fields to paste an embed or
shortcode into:

- **Building → Availability** — `IDX Availability Embed`
- **Neighborhood term → Related** — `IDX Listings Embed`

Sold Properties is a manual post type by design: it exists for the case where
the feed cannot hide sold dates. If your feed can hide them, use IDX and skip
that post type.

## Section design

`[ao_carousel]` and `[ao_section]` render the rule-and-eyebrow header, the
display heading and the view-all link, then the cards. Property cards use a
full-bleed image with the detail panel overlapping its bottom-left corner.

Both accept `featured="1"` to restrict the row to featured records, and
`orderby="price|date|menu_order"`. Transactions default to high price → low.

Tune the look by redefining tokens in your theme — no rule overrides needed:

```css
.ao-section {
	--ao-font-display: "Your Serif", Georgia, serif;
	--ao-visible: 2;          /* cards in view */
	--ao-media-ratio: 4 / 3;
	--ao-panel-width: 56%;    /* overlapping panel */
	--ao-panel-lift: 2.5rem;  /* how far it rides up over the image */
	--ao-gap: 2rem;
}
```

The track is native scroll-snap, so it swipes on touch and works by keyboard
before any JavaScript loads; the arrows only add click navigation.

## Templates

Every card is overridable. Copy any file from `templates/` into your theme at
`andyoei/<file>` and it wins. `assets/css/filters.css` is structure only —
type, colour and spacing are the theme's job.
