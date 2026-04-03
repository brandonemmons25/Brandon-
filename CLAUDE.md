# Claude Code – Project Rules

## Version numbering (REQUIRED on every change)

Every time any file inside `button-link-scanner/` is modified, **both** of the
following version strings MUST be updated in `button-link-scanner/button-link-scanner.php`
before committing:

```php
 * Version:     X.Y.Z          ← plugin header (line ~6)
define( 'BLS_VERSION', 'X.Y.Z' );  ← PHP constant  (line ~14)
```

They must always match each other.

### Version bump rules

| Change type                        | Bump  | Example        |
|------------------------------------|-------|----------------|
| Bug fix, small tweak               | Patch | 1.1.0 → 1.1.1  |
| New feature, new screen/scanner    | Minor | 1.1.1 → 1.2.0  |
| Breaking change / major refactor   | Major | 1.2.0 → 2.0.0  |

### Rebuild the zip on every change

After updating code, always rebuild the distributable zip:

```bash
rm -f button-link-scanner.zip
zip -r button-link-scanner.zip button-link-scanner/
```

Stage and commit the updated zip alongside the code changes.
