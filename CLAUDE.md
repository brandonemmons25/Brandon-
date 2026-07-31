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

| Change type                           | Bump  | Example   |
|---------------------------------------|-------|-----------|
| Anything at all — fix, tweak, feature | Minor | 1.4 → 1.5 |
| Breaking change / major refactor      | Major | 1.9 → 2.0 |

Every change gets the next minor version. Never write a third number:
`1.5`, not `1.5.0` or `1.5.1`. Minor keeps counting past 9 without touching
major — `1.9 → 1.10 → 1.11`.

History note: everything up to and including `1.4.24` used three-part
`MAJOR.MINOR.PATCH`. The scheme changed to two-part at `1.5`. Leave the older
three-part versions as they are — don't retro-renumber them.

### Rebuild the zip on every change

After updating code, always rebuild the distributable zip:

```bash
rm -f button-link-scanner.zip
zip -r button-link-scanner.zip button-link-scanner/
```

Stage and commit the updated zip alongside the code changes.
