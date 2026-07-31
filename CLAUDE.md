# Claude Code – Project Rules

## Version numbering (REQUIRED on every change)

Every time any file inside `button-link-scanner/` is modified, **both** of the
following version strings MUST be updated in `button-link-scanner/button-link-scanner.php`
before committing:

```php
 * Version:     X.Y            ← plugin header (line ~6)
define( 'BLS_VERSION', 'X.Y' );  ← PHP constant  (line ~14)
```

They must always match each other.

### Version bump rules

**Two components only — `MAJOR.MINOR`. There is no patch component.**

| Change type                           | Bump  | Example     |
|---------------------------------------|-------|-------------|
| Anything at all — fix, tweak, feature | Minor | 1.26 → 1.27 |
| Minor has reached 99                  | Major | 1.99 → 2.0  |

Every change increments the minor by exactly one — `1.26 → 1.27 → 1.28` — and
that continues all the way to `1.99`. Only then does major roll over, to
`2.0`. Never write a third number: `1.27`, not `1.27.0` or `1.26.1`.

History note — the numbering has been reset twice, so don't be thrown by
gaps:

1. Everything up to and including `1.4.24` used three-part
   `MAJOR.MINOR.PATCH`.
2. That became two-part at `1.5`, continuing `1.6`.
3. Renumbered again to `1.26` to reflect the real number of releases, since
   `1.5` read as a step *backwards* from `1.4.24` at a glance.

Leave all earlier versions as they are — don't retro-renumber them. The
current version is always whatever is in `button-link-scanner.php`; trust
that over any number written down elsewhere.

### Rebuild the zip on every change

After updating code, always rebuild the distributable zip:

```bash
rm -f button-link-scanner.zip
zip -r button-link-scanner.zip button-link-scanner/
```

Stage and commit the updated zip alongside the code changes.
