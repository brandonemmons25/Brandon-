# andyoei-core 3.0.0 — what changed, and what's left on the site side

For whoever picks up the theme/site work. The plugin side is done and pushed;
this is the part that lives outside it.

## What shipped

Two releases since 2.1.2.

**2.2.0** — shortcode output is now immune to `wpautop`. Every shortcode
return runs through `preg_replace('/>\s+</', '><', trim($html))` before it
leaves the plugin, so there are no newlines between tags for WordPress to read
as paragraph breaks. Also: `.ao-featured` carries its own width
(`max-width: var(--ao-width, 1280px); margin-inline: auto`), and the card title
reserves two lines so a long name doesn't push its image below its neighbours'.

**3.0.0** — the buildings directory rebuilt to the 10/29 visual feedback (§03,
§04) and the 11/05 meeting decisions.

## Delete these now

The two temporary Customizer rules from the earlier write-up are obsolete —
2.2.0 fixes the cause:

```css
body.home .ao-featured{max-width:none !important;width:100% !important}
body.home .ao-featured .ao-track p{display:none !important}
```

Also remove any site CSS that re-adds `border-radius` or `box-shadow` to
plugin markup. The plugin now renders neither — verified against the live DOM,
zero of each on any element it outputs. Anything still rounding a card photo or
a filter control is coming from the theme.

## Markup that changed

Theme CSS targeting these will need revisiting.

### Building cards — restructured

Was: name above the photo, then three labelled spec rows.

```html
<a class="ao-card ao-card--building">
  <div class="ao-card-mark"><span class="ao-card-name">…</span></div>
  <div class="ao-card-media">…</div>
  <dl class="ao-card-specs">… Starting At / Area / Completion Date …</dl>
</a>
```

Now: photo first, then name and address, per §05.

```html
<a class="ao-card ao-card--building">
  <div class="ao-card-media">…</div>
  <div class="ao-card-body">
    <p class="ao-card-eyebrow">…</p>   <!-- featured strip only -->
    <h3 class="ao-card-name">…</h3>
    <p class="ao-card-address">…</p>
  </div>
</a>
```

`.ao-card-mark` and `.ao-card-specs` are gone from the building card. Both are
still used by the **neighborhood** card, so don't delete rules for them
wholesale.

`.ao-card-name` is now an `<h3>`, not a `<span>`. Any selector written as
`span.ao-card-name` stops matching — including the hover-underline rule in the
Customizer:

```css
.ao-card-name { border-bottom: 1px solid transparent; padding-bottom: 2px; }
.ao-card-name:hover { border-bottom-color: currentColor; }
```

That one is element-agnostic, so it still works, but it now underlines a block
element running the card's full width rather than just the text. Worth a look.

### Filter bar — reordered and relabelled

Order is now search → condominium → neighborhood → Filters. Filters moved from
first to last.

Facet pills no longer carry a visible `.ao-pill-label`. The uppercase label
outside each control is gone per §03 ("visible wording inside controls • no
external uppercase labels"); the control's own wording sits in
`.ao-pill-value`, and the accessible label moved to a `.screen-reader-text`
span. Rules styling `.ao-pill-label` inside the bar no longer apply — the class
survives only in the drawer.

`.ao-clear` moved out of `.ao-bar` and into `.ao-meta`. Sibling selectors like
`.ao-pill + .ao-clear` no longer match.

New in the bar: `.ao-search-icon`.

### New markup

| Class | What it is |
|---|---|
| `.ao-chips` / `.ao-chip` / `.ao-chip-x` | Active filters, each removing itself |
| `.ao-count-total` / `.ao-count-note` | The count, and the "· Grouped by Neighborhood" suffix that hides once filtered |
| `.ao-group-name` / `.ao-group-back` | Neighborhood heading and its ↑ Back to search link |
| `.ao-card-body` / `.ao-card-eyebrow` / `.ao-card-address` | Card text block |
| `#ao-controls-buildings` | The bar, so Back to search has an anchor |

Note `.ao-group` is now used in two places: inside a drawer menu (a facet's
option list, unchanged) and in the results grid (a neighborhood heading). The
new rules are scoped `.ao-results .ao-group` to keep them apart.

## Behaviour that changed

- **Removed from the directory:** height, status, built date, views. The
  taxonomies and their stored terms are untouched in the database, so this is
  reversible — they're just not registered as facets any more.
- **Pagination is gone.** All 49 buildings render in one scroll (§05, "Default:
  all 49 buildings"). `per_page` is `-1`; Load More never appears.
- **Default order** is neighborhood A–Z, then building A–Z ignoring a leading
  "The".
- **Construction is derived, not tagged.** See below.
- **Photos are 5:4**, was 4:3. Existing images aren't recropped — WordPress
  will letterbox or crop per the theme's image sizes. Worth regenerating
  thumbnails.

## Construction: the new data dependency

This is the one piece that needs data before it does anything.

A new field, **First Occupancy / Delivery**, sits on each building. It accepts
`2026`, `2026-04`, or `2026-04-15` — only the precision actually known, per
§04's "Never invent a day for year-only data."

On save it's indexed as a timestamp in `_ao_occupancy`. The filter then asks
for the span of delivery dates falling in a bucket **as of that request**:

- New Construction — delivered within the last 2 years
- Newer Construction — delivered 2 to 15 years ago

So a building crosses from New to Newer on its own, with nobody re-tagging it.

A year-only entry resolves to 1 January of that year. That's a comparison
point, never displayed. Taking the start of the period makes the building the
oldest it could be, so a boundary case falls into the older bucket rather than
claiming a newness it may not have.

**Nothing is populated yet.** All 49 buildings have an empty delivery date, so
the Construction filter matches nothing until the team enters them. The old
`ao_completion` (year) field still holds data but is no longer read by any
filter — a migration seeding the new field from it is a small job if wanted.

## Open items

- **Overlay card variant** — not built. The 11/05 notes have Brandon testing
  the name/address inside an overlay container against the plain-text version.
  The plain-text version is what shipped.
- **Results count** — §03 asks for it and 3.0.0 reinstates it, but it had been
  deliberately removed in 2.0.2 at Brandon's request. Flagging the reversal
  rather than assuming.
- **Featured strip** — §02 wants three equal cards and the 11/05 notes say up
  to six, ordered numerically, with the line separator removed and no
  descriptive copy. The ordering already works off Featured Rank. The separator
  and copy are in the strip template and haven't been touched.
- **FAQ accordion** (§06) — not in the plugin. Single-column, CMS-editable, 12
  illustrative questions.

## Checking the work

`andyoei-core/tests/relations-test.php` runs under plain PHP with WordPress
stubbed — `php tests/relations-test.php`. 37 checks, covering the removed
filters, the condominium narrowing, the Construction date spans, date-precision
parsing, and the card contents. It catches wrong data shapes, not wrong hooks.
